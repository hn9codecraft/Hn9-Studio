# Sprint 5.3.7 — Provider Intelligence Platform

**Status:** Complete
**Scope:** Optimisation, routing, failover, monitoring and provider intelligence. No new provider, no
queue, no workflow engine.
**Foundation:** `AIProviderInterface`, `ProviderRegistry`, `ProviderFactory`, `ProviderManager`,
`HealthManager` and all five adapters were audited and reused. **None was redesigned.** Every change is
additive; one existing binding gained a decorator and one shared base class gained a timeout seam (§13).

---

## 1. Executive Summary

The provider layer entering this sprint was correct but *passive*. A caller had to know which provider it
wanted, ask the manager for it by key, and handle whatever came back. Five adapters existed with no
relationship between them: no way to say "give me text from whichever provider is best right now", no
memory of which one failed a minute ago, no ceiling on what a request could cost or how long it could
take.

Sprint 5.3.7 adds the layer that makes them a platform. One call —

```php
$dispatcher->text(new TextRequest(prompt: 'Draft the launch copy.'));
```

— now plans an ordered set of providers, calls the best one, retries it if the failure was transient,
hands the request to the next provider if it was not, refuses to call a provider that is currently
breaking, stops when the request's time budget is spent, and records what happened so the *next* request
routes better.

**What was built**

| Concern | Component | Behaviour |
| --- | --- | --- |
| Selection | `ProviderRouter` + 5 strategies | Scored, configuration-driven, no provider names |
| Capability routing | `Capability`-driven candidate pool | Text / image / voice today; video and future modalities via one registration |
| Health routing | `ProviderHealthTracker` | Healthy routes, degraded is demoted, unavailable is withheld, recovery is automatic |
| Retry | `RetryPolicy` + `Retrier` | Count, delay, exponential backoff, jitter, typed retryable/non-retryable sets |
| Fallback | `FallbackConfig` + dispatcher chain walk | Configured chains, bounded, failure-class scoped |
| Cost | `CostEstimator` + cheapest/balanced/quality | Estimate-based selection and hard budget filtering |
| Circuit breaker | `CircuitBreaker` | Closed → open → half-open, per provider, cache-shared, self-recovering |
| Timeouts | `TimeoutConfig` + `Deadline` | Connection, request and whole-dispatch budgets |
| Metrics | `CacheMetricsCollector` | Requests, success/failure rate, latency, retries, fallbacks, usage, spend |
| Performance | 4 caches | Provider instances, health probes, metadata, parsed configuration |

**Verification:** Composer valid · Pint passed · PHPStan (Larastan) level 5 clean · **251 tests, 795
assertions, all passing** (172 before this sprint, 79 added) · every test runs under
`Http::preventStrayRequests()`.

**Honest caveats:** cost optimisation ships **disabled by default** and health *probing* during routing
ships **off by default** — both because they can issue network calls; the reasoning is in §7, §8 and §16.

---

## 2. Provider Layer Audit

All five adapters were read end to end before anything was written.

| Provider | Modalities | Priority (default) | Health probe | Notable |
| --- | --- | --- | --- | --- |
| OpenAI | text, image | 100 | `GET models/{model}` | Responses API; local token estimate |
| Claude | text | 90 | messages probe | Local token estimate |
| Gemini | text, image¹ | 80 | model probe | **Remote tokenizer** — `estimateCost()` can touch the network |
| OpenRouter | text | 70 | model + credits | Aggregator; per-model metadata and pricing |
| ElevenLabs | voice | 60 | subscription probe | Character-billed; binary response body |

¹ Image is declared only when image-capable models are configured.

### Findings

| # | Finding | Action |
| --- | --- | --- |
| 1 | The layer was **already well factored.** `AbstractProvider`, `AbstractProviderClient`, `AbstractModelRegistry`, `AbstractUsageCalculator` and `AbstractTokenCounter` hold the shared behaviour; the adapters hold only what differs. | Nothing extracted. Duplication was looked for and not found in quantity worth churning five adapters over. |
| 2 | `config/ai.php` repeated `array_values(array_filter(explode(',', …)))` **eight times** for comma-separated environment lists. | Deduplicated into one `$list()` helper at the top of the file. Behaviour additionally improved: values are now trimmed. |
| 3 | `ai.health.cache_ttl` existed in configuration but **nothing read it.** Every `ProviderManager::health()` call re-probed every vendor. | Implemented as `CachedHealthManager` (§10). |
| 4 | Each provider client set a *request* timeout but **no connection timeout.** A black-holed endpoint held the full request budget open waiting for a socket. | One seam added to `AbstractProviderClient` (§13). |
| 5 | `claude` and `openai` had **no `priority` key**, so the service provider's `?? 90` / `?? 100` literals were the real source of truth. | Both keys added to configuration; the literals remain only as fallbacks. |
| 6 | Gemini's `estimateCost()` can call the vendor's tokenizer. | Cost estimation is opt-in, memoised, and failure-tolerant (§7). |
| 7 | Only ElevenLabs' suite called `Http::preventStrayRequests()`. Four provider suites could have reached a real vendor. | Moved to the base `TestCase` — now global (§12). |
| 8 | Two tests failed before this sprint (`MissingAppKeyException`) — the suite depended on a local `.env`. | Fixed test-environment gap: a fixed throwaway `APP_KEY` in `phpunit.xml`. |

### What was deliberately *not* changed

`estimateCost()` follows a near-identical shape in four adapters (resolve model → count prompt tokens →
read `max_tokens` → price through the calculator). Extracting it would require a common pricing method
across `AbstractUsageCalculator`'s subclasses, whose signatures legitimately differ (`fromUsage`,
`fromUsageMetadata`, `fromCharacters`). That is a contract change to five shipped adapters for cosmetic
gain, and the sprint forbids redesigning contracts. **Recorded as a deferred opportunity, not done.**

---

## 3. Routing Strategy

### The pipeline

```
registry->forCapability()          the pool: providers declaring the capability
        ↓
caller exclusions / chain          restrict mode drops anything off the chain
        ↓
required capabilities              e.g. also needs function calling
        ↓
model                              a pinned model removes providers that do not publish it
        ↓
circuit                            an open circuit removes the provider
        ↓
health                             Unavailable removed · Unknown kept · Degraded kept
        ↓
cost                               estimates attached, budget filters (when enabled)
        ↓
strategy->score()                  the active strategy ranks the survivors
        ↓
ordering                           preference → health tier → chain → score → priority → key
```

### Signals and normalisation

Priorities, prices and latencies live on incomparable scales that differ per deployment. Each is
min-max normalised **across the candidates actually in play**, so one set of weights stays meaningful
everywhere and no strategy contains an absolute threshold. A signal with no spread or no data
normalises to a neutral `0.5` rather than to zero.

### The five strategies

| Key | Ranks by | Ties broken by |
| --- | --- | --- |
| `priority` | Configured registration priority | — |
| `cheapest` | Estimated cost, ascending | Priority |
| `fastest` | Observed average response time | Priority |
| `quality` | Priority (the operator's curated ranking) 0.8 + observed reliability 0.2 | — |
| `balanced` *(default)* | Weighted blend of all four signals | — |

`balanced` divides by the sum of its configured weights, so zeroing three of them yields exactly the
fourth strategy with no special case.

Strategies are resolved from `RoutingStrategyRegistry` by configuration key. **Adding a selection policy
means registering an implementation and naming it in configuration — the router gains no branch.**

### Ordering is lexicographic, not a summed score

An early draft added magic boosts (`+1000` preferred, `+100` chained) to the score. It was replaced,
because the inputs are not commensurable — a caller's explicit preference is not "worth" some quantity
of latency — and because **min-max normalisation makes a numeric health penalty unworkable**: with two
candidates the better always scores `1.0` and the other `0.0`, so no penalty below 1.0 could reliably
demote a degraded provider, and any penalty above it would swamp everything else. Each rule now breaks
only the ties the rule above it left:

```
1. caller preference   an explicit ask wins outright
2. health tier         degraded ranks behind healthy peers
3. fallback chain      operator-pinned order (in `order` mode)
4. strategy score      the measured ranking
5. priority, then key  stable and reproducible
```

### No provider-specific logic

Verified by inspection and by grep: **no provider key appears anywhere in `Routing/`, `Resilience/`,
`Execution/`, `Metrics/`, `Cache/` or `Config/`** except one docblock example of a chain string. There
are **no `switch` statements** in the new layer. Every key handled comes from the registry, from
configuration or from the caller.

---

## 4. Capability Routing

Routing is driven by the `Capability` enum via `Modality::capability()`, and the pool comes from the
registry's existing capability index — **unchanged**.

| Request | Capability | Routed to |
| --- | --- | --- |
| `TextRequest` | `text` | Providers declaring text |
| `ImageRequest` | `image` | Providers declaring image |
| `VoiceRequest` | `voice` | Providers declaring voice |
| `VideoRequest` | `video` | Providers declaring video (none today → `NoProviderAvailableException`) |

A caller may demand more than the modality implies:

```php
$dispatcher->text($request, new DispatchOptions(
    requiredCapabilities: [Capability::FunctionCalling],
));
```

**Future modalities.** `ModalityInvokerRegistry` maps a modality to the provider method that serves it.
The dispatcher holds no `match` over modalities — it asks the registry. Video is already registered.
Embeddings become dispatchable by adding the case to `Modality`/`Capability`, a request/response pair,
and one `ModalityInvoker` registration; the router, dispatcher, strategies and metrics need no change.

The invoker is generic in its request type, so the binding between a modality and its provider method is
**checked statically at the registration site** rather than asserted at runtime.

---

## 5. Health Strategy

Two independent sources, deliberately separated:

| Source | Cost | Default | Component |
| --- | --- | --- | --- |
| **Observed** (passive) | Free — derived from calls already made | On | `ProviderHealthTracker` |
| **Probed** (active) | A real vendor request | **Off** | `HealthManagerInterface`, cached |

When probing is enabled the router takes the *worse* of the two.

### Observed state machine

```
        success                    failure ×1              failure ×3
Unknown ────────► Healthy ◄──────────────────► Degraded ──────────► Unavailable
   ▲                 │        success (resets the run)                  │
   └─────────────────┴────────── entry TTL expires ─────────────────────┘
```

| State | Routing effect |
| --- | --- |
| Healthy | Routes normally |
| Unknown | Routes normally — a provider with no history is not punished for being new |
| Degraded | Kept in the plan, ranked behind every healthy peer |
| Unavailable | Removed from the plan |

### Automatic recovery

Observed state is written with `recovery_seconds` (default 300) as its **TTL**. That single decision
supplies recovery for free: a provider that stops failing — or simply stops receiving traffic — lapses
back to `Unknown` and becomes routable again with no scheduler, no background job and no operator
action. A success resets the failure run immediately.

**Why probing is off by default.** A probe is a real, billable vendor request. With probing on, routing a
request across four candidates issues four extra vendor calls before the real one. Passive health gives
most of the benefit at zero cost; probing is available for deployments that want it, and the cache keeps
it to one probe per provider per TTL.

---

## 6. Retry Strategy

| Setting | Default | Meaning |
| --- | --- | --- |
| `enabled` | `true` | Disabling yields exactly one attempt per provider |
| `max_attempts` | `3` | Total attempts per provider, including the first |
| `delay_ms` | `200` | Base delay |
| `multiplier` | `2.0` | `delay × multiplier^(attempt−1)` |
| `max_delay_ms` | `5000` | Cap |
| `jitter` / `jitter_ratio` | `true` / `0.25` | Spreads concurrent callers after a shared outage |

**Classification is by exception type, from configuration.** A non-retryable match always wins, so a
class listed in both is never repeated. An unclassified failure — anything not in either list, such as a
plain `RuntimeException` — is **not retried**: it is a bug, and repeating it only burns the deadline.

| Retryable | Non-retryable |
| --- | --- |
| `ProviderTimeoutException` | `ProviderAuthenticationException` |
| `ProviderNetworkException` | `UnsupportedCapabilityException` |
| `ProviderRateLimitException` | `ProviderNotRegisteredException` |
| `ProviderApiException` | `ProviderNotConfiguredException` |
| | `ProviderDisabledException` |

`Retrier` owns the loop, `RetryPolicy` owns the decisions, and waiting goes through Laravel's `Sleep` —
which the suite fakes, so backoff is asserted **exactly** and the tests never actually sleep. A wait that
cannot fit inside the remaining deadline is abandoned rather than taken.

**This does not duplicate the transport retry** already inside `AbstractProviderClient`. That one
re-sends a connection-level blip within a single HTTP call and knows nothing about typed failures. This
one reasons about the AI subsystem's exception taxonomy and hands control to the fallback chain when a
provider is exhausted. Different layers, different inputs, different outcomes.

---

## 7. Fallback Strategy

```
plan[0] ──fails──► plan[1] ──fails──► plan[2] ──fails──► AllProvidersFailedException
   │                  │                  │
 retries            retries            retries          (all inside one deadline)
```

| Setting | Default | Meaning |
| --- | --- | --- |
| `enabled` | `true` | `false` confines a request to one provider |
| `max_providers` | `3` | Upper bound on providers tried for one request |
| `mode` | `order` | `order` = the chain dictates order; `restrict` = the chain only limits the candidate set |
| `chains.{capability}` | *empty* | Ordered provider keys, per capability |
| `exceptions` | `[AIException::class]` | Only these failures move the request along |

Chains are supplied entirely by environment, per capability:

```
AI_FALLBACK_CHAIN_TEXT="openai,claude,gemini,openrouter"
AI_FALLBACK_CHAIN_VOICE="elevenlabs"
```

**Nothing is hardcoded.** With no chain configured — the default — the strategy alone decides.

A failure outside `exceptions` is a caller or programming error, not an outage: it surfaces immediately
rather than burning the rest of the chain on a request that cannot succeed. An `UnsupportedCapability`
failure *does* fall through, because another provider may well serve it.

---

## 8. Cost Optimisation

| Setting | Default | Meaning |
| --- | --- | --- |
| `enabled` | **`false`** | Estimation is opt-in |
| `strategy` | `balanced` | `cheapest` · `balanced` · `quality` |
| `budget` | `null` | Hard per-request ceiling |
| `currency` | `USD` | |

`CostEstimator` delegates to each provider's own `estimateCost()` — pricing knowledge stays in the
adapter that owns it, and none is duplicated. What the estimator adds is memoisation (identical
provider/request pairs are priced once per process, which matters when every candidate is priced) and
containment (a failing estimator yields `null`, never an exception — **a request must never fail because
its price could not be worked out**).

A candidate estimating above the budget is dropped during routing, before any vendor is contacted. If
the budget rules out *everyone*, `BudgetExceededException` is raised — deliberately distinct from
"nothing can serve this", because the responses differ.

**Why it is off by default.** Gemini's token counter consults a remote tokenizer, so `estimateCost()`
can issue a network call. Pricing four candidates on every request would be a material, unasked-for cost
and latency increase. Estimation is additionally skipped when it cannot change the outcome — the
`quality` strategy with no budget never asks for a price. Deployments that want cost routing set
`AI_COST_OPTIMIZATION_ENABLED=true`, and should either configure Gemini's `remote_token_counting=false`
or accept the preflight call.

---

## 9. Circuit Breaker

```
                    failures ≥ threshold
      ┌──────────┐ ───────────────────────► ┌────────┐
      │  CLOSED  │                          │  OPEN  │
      └──────────┘ ◄─────────────────────── └────────┘
            ▲       successes ≥ threshold        │  recovery_timeout elapses
            │                                    ▼  (promoted on the next call)
            │        ┌───────────┐               │
            └────────│ HALF-OPEN │◄──────────────┘
                     └───────────┘
                           │  any failure → OPEN, timer restarts
                           ▼
```

| Setting | Default |
| --- | --- |
| `enabled` | `true` |
| `failure_threshold` | `5` consecutive failures |
| `success_threshold` | `2` consecutive half-open successes |
| `recovery_timeout` | `60` seconds |
| `store` / `prefix` | default store · `ai:circuit` |

**Per provider.** State lives in the cache store, so a circuit is **shared across workers** rather than
relearned in each process. Recovery needs no scheduler: promotion to half-open happens on the first call
after the timeout, as a side effect of asking whether traffic is allowed.

`trip_on` is deliberately narrower than the retryable set — a request the provider *correctly refused*
(an unsupported capability, a caller's bad input) says nothing about the provider's condition and must
not open its circuit.

When **every** candidate is withheld by its circuit, routing raises `CircuitOpenException` (HTTP 503)
carrying `retry_after`, not `NoProviderAvailableException`. Nothing is misconfigured; the providers are
breaking and the request can be expected to succeed shortly. The two cases call for different responses,
so they are different exceptions.

---

## 10. Caching Strategy

| Cache | Scope | Lifetime | Avoids |
| --- | --- | --- | --- |
| **Provider instances** — `ProviderInstanceCache` | Process | Request/worker | Rebuilding a client + model registry + usage calculator + normaliser + token counter per touch |
| **Health probes** — `CachedHealthManager` | Cache store | `ai.health.cache_ttl` (60s) | A real vendor request per candidate per dispatch |
| **Metadata** — `ProviderMetadataCache` | Process | Request/worker | Re-reading capabilities and rebuilding model lookup maps |
| **Configuration** — `PlatformConfig` | Process (singleton) | Process | Re-parsing and re-coercing `config/ai.php` on every dispatch |

`PlatformConfig` **is** the configuration cache: `config/ai.php` is read and type-coerced exactly once
per process into an immutable object tree, and every component reads its settings from there.

`ProviderInstanceCache` decorates the factory's *use*, not the factory itself — the build path stays
`ProviderFactoryInterface::make()`, and a provider disabled after being cached is dropped and re-resolved
through the canonical path so it still raises `ProviderDisabledException`.

`CachedHealthManager` is a decorator around the untouched `HealthManager`: probing, error isolation and
aggregation stay exactly where they were.

### Minimising object creation

- One `PlatformConfig` per process; every settings object is built once with it.
- Model catalogues held as `array_fill_keys` lookup maps — O(1) membership instead of `in_array`.
- `NullMetricsCollector` replaces the collector when metrics are off, so the hot path has **no
  "are metrics enabled?" branch at all**.
- Cost estimates memoised per `(provider, request-hash)`.
- `AbstractProviderClient::timeouts()` reads the singleton rather than re-parsing configuration per call.

---

## 11. Metrics

Every measure is its own integer key, so recording is a **single atomic increment** — no
read-modify-write, and therefore no lost updates when workers record concurrently. Cost accumulates in
millionths of a currency unit because increments must be integral. A seeding `add()` gives the whole
series one retention window.

| Measure | Per provider | Per capability |
| --- | --- | --- |
| Requests | ✓ | ✓ |
| Successes / failures | ✓ | ✓ |
| Success rate / failure rate | derived | derived |
| Average response time | derived | derived |
| Retries | ✓ | ✓ |
| Fallbacks | ✓ | ✓ |
| Estimated cost | ✓ | — |
| Failure reason (typed error code) | ✓ | — |

Rates and averages are **derived on read**, never stored. `MetricsSnapshotDTO::totals()` aggregates
across providers.

Metrics also feed back into routing: `fastest` reads average response time and `balanced`/`quality` read
reliability, so the platform's own history improves its next decision. A provider with no history scores
a neutral `0.5` — neither favoured nor starved of the traffic that would give it one.

---

## 12. Test Hardening

`Http::preventStrayRequests()` moved from one suite to the base `TestCase`, so **every test in the
project** now fails loudly on an unstubbed outbound request rather than reaching a real vendor with a
test credential. The duplicated `setUp()` in `ElevenLabsProviderTest` was removed.

Verified explicitly, not just assumed:

- `ProviderRoutingTest::test_routing_reaches_no_network` — `Http::assertNothingSent()`
- `ProviderDispatcherTest::test_a_full_dispatch_reaches_no_network` — a retry-plus-fallback dispatch sends nothing
- `ProviderPlatformIntegrationTest::test_the_platform_never_reaches_an_unstubbed_vendor_route` — exact URL and count

Also fixed: the suite previously depended on a local `.env` and failed two tests with
`MissingAppKeyException`. `phpunit.xml` now supplies a fixed throwaway `APP_KEY`. `Sleep::fake()` is used
throughout the resilience suites, so backoff is asserted exactly and no test sleeps.

---

## 13. Shared Component Audit

Only two extractions were made, both behaviour-preserving and backward compatible.

**1. `AbstractProviderClient` — timeout policy.** `pending()` gained one seam:

```php
$timeouts = $this->timeouts();                                   // the PlatformConfig singleton

->timeout($timeouts->requestTimeoutFor($this->timeout))          // provider's own value still wins
…
$timeouts->connect > 0 ? $request->connectTimeout($timeouts->connect) : $request;
```

All five adapters inherit a connection timeout with **zero constructor changes and zero adapter edits**.
A provider block's own `timeout` still takes precedence; the platform value only fills gaps.

**2. `config/ai.php` — the `$list()` helper.** Eight identical comma-splitting expressions collapsed to
one, which additionally trims values.

**Nothing else was extracted.** The abstract bases were already carrying the shared behaviour correctly,
and the one remaining candidate (`estimateCost()`) would have required a contract change across five
shipped adapters — out of scope and recorded in §2 instead.

---

## 14. Dependency Graph

```
                              ┌──────────────────────┐
                              │    PlatformConfig    │  singleton — config read once
                              └──────────┬───────────┘
        ┌──────────────┬─────────────────┼──────────────┬──────────────────┐
        ▼              ▼                 ▼              ▼                  ▼
  RoutingConfig   RetryConfig  CircuitBreakerConfig  CostConfig   Metrics/Cache/Timeout
        │              │                 │              │                  │
        │              ▼                 ▼              │                  ▼
        │        RetryPolicy ◄── Retrier │              │        CacheMetricsCollector
        │              │                 ▼              │         · NullMetricsCollector
        │              │          CircuitBreaker        │                  │
        │              │                 │              │                  │
        ▼              │                 │              ▼                  │
  RoutingStrategyRegistry                │        CostEstimator             │
   priority · cheapest                   │              │                   │
   fastest  · quality                    │              ▼                   │
   balanced                              │      ProviderInstanceCache       │
        │                                │              │                   │
        └────────────┬───────────────────┴──────────────┴───────────────────┘
                     ▼
             ┌───────────────┐        ProviderMetadataCache ──► ProviderRegistry  (unchanged)
             │ ProviderRouter│ ◄───── ProviderHealthTracker
             └───────┬───────┘ ◄───── CachedHealthManager ──► HealthManager       (unchanged)
                     │  RoutingPlan (ordered ProviderCandidates)
                     ▼
             ┌────────────────────┐
             │ ProviderDispatcher │ ──► ModalityInvokerRegistry ──► AIProviderInterface
             └────────────────────┘        (text · image · voice · video)
                     │                                    ▲
                     │  Deadline · Retrier · CircuitBreaker │
                     ▼                                    │
               DispatchResult                    ProviderFactory (unchanged)
```

**Untouched:** `ProviderManager` · `ProviderRegistry` · `ProviderFactory` · every existing contract ·
all five adapters and their clients, configs, registries, normalisers, counters and calculators.

### Files

**54 new production files** across `Config/` (11), `Contracts/` (7), `Routing/` (12), `Resilience/` (4),
`Execution/` (5), `Metrics/` (2), `Cache/` (3), `Health/` (1), `DTOs/` (2), `Support/` (2),
`Exceptions/` (5).

**6 modified files:** `config/ai.php` · `app/Providers/AIServiceProvider.php` ·
`app/AI/Http/AbstractProviderClient.php` · `phpunit.xml` · `tests/TestCase.php` ·
`tests/Feature/ElevenLabsProviderTest.php`.

**6 new test files:** 2 support + 4 feature suites.

---

## 15. Regression Results

| Check | Result |
| --- | --- |
| `composer validate --strict` | `./composer.json is valid` |
| `pint --test` | **passed** — no style violations |
| `phpstan analyse` (Larastan **level 5**) | **No errors** — no baseline, no ignores, no suppressions |
| `phpunit` | **OK — 251 tests, 795 assertions** |

### Suite breakdown

| Suite | Tests | Covers |
| --- | --- | --- |
| Pre-existing (all 5 provider suites, domain, repos, DTOs, enums, endpoints) | 172 | **Zero regressions** |
| `ProviderRoutingTest` | 24 | Capability, priority, preference, exclusion, model, health, circuit, cost, budget, chains, rejection reasons |
| `ProviderResilienceTest` | 20 | Retry count/classification/backoff/jitter/deadline; circuit open, half-open, close, re-open, disable, reset |
| `ProviderDispatcherTest` | 26 | Retry, fallback, bounds, metrics, caches, timeout, typed helpers, attempt trail |
| `ProviderPlatformIntegrationTest` | 9 | **The real adapters** driven through the platform with stubbed vendor responses |
| **Total** | **251** | |

### Requirement coverage

| Required verification | Where |
| --- | --- |
| Retry logic | `ProviderResilienceTest` ×10, `ProviderDispatcherTest` ×2, integration ×2 |
| Fallback logic | `ProviderDispatcherTest` ×6, integration ×2 |
| Circuit breaker | `ProviderResilienceTest` ×8, `ProviderDispatcherTest` ×1, `ProviderRoutingTest` ×2, integration ×1 |
| Routing | `ProviderRoutingTest` ×24 |
| Health routing | `ProviderRoutingTest` ×4, `ProviderDispatcherTest` ×2, integration ×1 |
| Capability routing | `ProviderRoutingTest` ×3, `ProviderDispatcherTest` ×2, integration ×1 |
| Cost routing | `ProviderRoutingTest` ×6, integration ×1 |
| Metrics | `ProviderDispatcherTest` ×5, integration ×2 |
| Cache | `ProviderDispatcherTest` ×4 |
| Regression vs. existing providers | 172 pre-existing tests + 9 integration tests |
| `Http::preventStrayRequests()` | Global in `TestCase`; asserted in 3 tests |

### Behavioural changes to existing surfaces

Two, both intentional, neither a signature change:

1. **`ProviderManager::health()` / `healthOf()` now return cached results** for
   `ai.health.cache_ttl` (60s). This implements a configuration key that already existed and was
   previously ignored. Disable with `AI_HEALTH_CACHE_ENABLED=false`.
2. **Every provider client now applies a 10-second connection timeout** and falls back to
   `ai.timeouts.request` when a provider block omits its own `timeout`. Tune with `AI_CONNECT_TIMEOUT`.

---

## 16. Known Risks

| # | Risk | Severity | Mitigation / status |
| --- | --- | --- | --- |
| 1 | **Cost estimation can issue a network call** (Gemini's remote tokenizer), adding latency and spend to routing. | Medium | Disabled by default; skipped when it cannot change the outcome; memoised per process; failures degrade to "unknown" rather than raising. Operators enabling it should set `GEMINI_REMOTE_TOKEN_COUNTING=false`. |
| 2 | **Metrics counters use `increment()`**, which is atomic on array/Redis/Memcached but not on every store. The provider/capability *index* is a read-modify-write and can drop a concurrent first-sighting. | Low | Counters are advisory, not billing. A missed index entry only omits a provider from a snapshot until its next call. |
| 3 | **Circuit state is per cache store.** With an unshared store (`array`, per-node `file`) each worker learns its own circuit. | Medium | Configure `AI_CIRCUIT_STORE` to a shared store (Redis) in production. Documented in `config/ai.php`. |
| 4 | **`PlatformConfig` is read once per process.** A configuration change needs a worker restart. | Low | Intended — it is the configuration cache. Tests rebuild it explicitly via `configurePlatform()`. |
| 5 | **The health tracker's failure run is not atomic** (read, increment, write). Concurrent failures may under-count. | Low | Thresholds are small and failures repeat; the circuit breaker is the hard stop, the tracker is the soft signal. |
| 6 | **Latency-based routing needs history.** `fastest` is neutral until metrics accumulate. | Low | By design — a neutral score avoids starving a new provider of the traffic that would rank it. |
| 7 | **The whole-dispatch deadline governs waits and provider hand-offs, not an in-flight HTTP call.** A single provider exceeding its own request timeout can overrun `total_ms`. | Medium | Per-request and per-connection timeouts bound each call; the deadline bounds everything between them. A true hard cancel needs async HTTP — out of scope. |
| 8 | **Fallback multiplies spend.** A request crossing four providers pays four times. | Medium | Bounded by `max_providers` (default 3) and by the deadline; `fallbacks` is a first-class metric. |
| 9 | **`Deadline::remainingMs()` truncates**, so a sub-millisecond remainder reads as exhausted. | Low | Conservative in the safe direction: it stops early rather than overrunning. |
| 10 | **Registering a provider after instances are cached** would serve stale metadata. | Low | Registration is a boot-time activity; the test helper flushes both caches, and `ProviderInstanceCache` re-validates the registration on every hit. |

---

## 17. Preparation for Sprint 5.3.8

The seams this sprint deliberately left open:

| Extension point | How to use it |
| --- | --- |
| `RoutingStrategyRegistry` | Register another `RoutingStrategyInterface` and name it in `ai.routing.strategy`. No router change. |
| `ModalityInvokerRegistry` | Register another `ModalityInvoker` to make a new modality dispatchable. No dispatcher change. |
| `MetricsCollectorInterface` | Swap the cache-backed collector for a Prometheus/OpenTelemetry exporter behind the same contract. |
| `HealthTrackerInterface` / `CircuitBreakerInterface` | Alternative stores or algorithms without touching routing. |
| `RetryPolicyInterface` | Per-tenant or per-capability policies via `withMaxAttempts()` or another implementation. |
| `DispatchResult` / `RoutingPlan` | Already serialise the full decision trail — ready to back a diagnostics endpoint. |
| `ai.routing.fallback.chains` | Per-capability chains are configured but empty by default; nothing new is needed to pin one. |

**Deliberately not built** (out of scope, and unblocked by the above): streaming dispatch, request
batching, response caching, semantic/model-aware routing beyond catalogue membership, a persisted
metrics store, an admin/diagnostics API, per-tenant budgets and quotas, and the `estimateCost()`
extraction noted in §2.

---

## 18. Stop Condition

Implementation complete. All verification run and passing:

```
composer validate --strict   ./composer.json is valid
pint --test                  passed
phpstan (Larastan level 5)   No errors
phpunit                      OK (251 tests, 795 assertions)
```

Report generated. **Sprint 5.3.8 not started.** Awaiting instruction.
