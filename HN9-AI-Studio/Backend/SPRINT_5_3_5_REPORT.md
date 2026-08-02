# Sprint 5.3.5 — OpenRouter Provider

**Status:** Complete
**Scope:** OpenRouter provider only. No ElevenLabs, no Veo, no queue, no workflow engine.
**Foundation:** Untouched. `AIProviderInterface`, `ProviderFactory`, `ProviderRegistry`, `ProviderManager` and `HealthManager` were audited and reused as-is — not one of them was modified.

---

## 1. Architecture Summary

OpenRouter is an **aggregator**: one credential and one OpenAI-compatible endpoint front many upstream
vendors (OpenAI, Anthropic, Google, DeepSeek, Llama, Mistral, Qwen and whatever the catalogue gains
next). The adapter therefore had to satisfy two constraints that the three existing providers do not:

1. **The model list is not knowable at build time.** It is broad, cross-vendor and changes weekly, so
   the catalogue is supplied entirely by configuration. No model identifier appears anywhere in `app/`.
2. **Per-model facts differ wildly.** A 200k-context Claude and an 8k Llama sit behind the same
   credential at prices that differ by two orders of magnitude, so per-model metadata became a
   first-class, provider-independent concept (`ModelMetadataDTO`).

Everything else plugged into the existing seams. The adapter follows the exact shape the previous three
sprints established — `Provider` + `Client` + `Config` + `ModelRegistry` + `UsageCalculator` +
`ResponseNormalizer` + `TokenCounter` — extends the shared abstract bases, and registers itself through
`ProviderRegistry` with a build closure. The registry, factory and manager remain free of any
provider-specific knowledge; adding OpenRouter required **no change to any of them**.

```
AIProviderInterface  ◄── AbstractProvider  ◄── OpenRouterProvider
                                                    │
                          AbstractProviderClient ◄── OpenRouterClient        (transport + typed errors)
                          AbstractModelRegistry  ◄── OpenRouterModelRegistry (catalogue + metadata)
                          AbstractUsageCalculator◄── OpenRouterUsageCalculator (tokens + cost)
                          AbstractTokenCounter   ◄── OpenRouterTokenCounter  (local estimate)
                                                     OpenRouterResponseNormalizer (vendor → shared DTOs)
                                                     OpenRouterConfig        (typed, validated settings)
```

### Design decisions worth recording

| Decision | Rationale |
| --- | --- |
| **Vendor-reported cost wins over configured rates.** | OpenRouter routes to whichever upstream endpoint it selects, so the settled charge can differ from any static rate. With `usage_accounting` on, the adapter asks for the real charge (`usage.include`) and prefers it. Configured pricing remains the fallback. |
| **Health = credential probe + catalogue verification.** | `GET /key` proves connectivity and authentication without billing a generation; `GET /models/{author}/{slug}/endpoints` proves the configured model is actually routable. |
| **A working key with an unroutable model is `Degraded`, not `Unavailable`.** | The credential works and other models still route; failing the whole provider would take a healthy account out of rotation over one model. `Degraded` is operational, so routing continues. |
| **HTTP 403 is *not* an authentication failure.** | OpenRouter reserves 403 for moderation blocks. The shared client treats 401/403 alike, so `isAuthenticationFailure()` is narrowed to 401 — otherwise a flagged prompt would look like a bad API key and trigger credential rotation. |
| **An `error` object inside a 2xx envelope is still an error.** | OpenRouter can succeed at the transport while the routed upstream call failed. `decode()` inspects the body and maps the embedded status onto the same typed exceptions. |
| **Image/video/voice are not overridden.** | `AbstractProvider` already throws `UnsupportedCapabilityException` with the correct capability. Re-declaring three throwing methods would be duplication for no behavioural gain. Documented explicitly in the class docblock. |
| **Router features travel in `request->options`.** | `provider.order`, `route`, `models` fallbacks and `transforms` pass through untouched, so the shared `TextRequest` contract did not have to grow vendor fields. |

---

## 2. Files Created

### OpenRouter provider (`app/AI/Providers/OpenRouter/`)

| File | Lines | Responsibility |
| --- | --- | --- |
| `OpenRouterProvider.php` | 230 | `AIProviderInterface` implementation: text generation, health, cost, tokens, metadata. |
| `OpenRouterClient.php` | 116 | Transport: bearer auth, attribution headers, routes, vendor error taxonomy. |
| `OpenRouterConfig.php` | 101 | Typed, validated settings resolved from `config/ai.php`. |
| `OpenRouterModelRegistry.php` | 141 | Configured catalogue, model resolution, per-model metadata, catalogue routes. |
| `OpenRouterUsageCalculator.php` | 58 | Prompt/completion/total tokens, configured *and* vendor-settled cost. |
| `OpenRouterResponseNormalizer.php` | 124 | Vendor payload → `TextResponse` / `UsageResponse` / `TokenResponse` / `ProviderResponseDTO`. |
| `OpenRouterTokenCounter.php` | 15 | Local preflight estimate (no vendor tokenizer exists). |

### Shared (provider-independent)

| File | Lines | Responsibility |
| --- | --- | --- |
| `app/AI/DTOs/ModelMetadataDTO.php` | 72 | Immutable per-model description: vendor, capabilities, streaming, function calling, context window, max output tokens, pricing. |
| `app/AI/Support/ConfigNormalizer.php` | 75 | Coercion of loosely typed config into strict shapes: `stringList`, `stringMap`, `nonEmptyString`, `positiveInt`. |

### Tests

| File | Lines | Contents |
| --- | --- | --- |
| `tests/Feature/OpenRouterProviderTest.php` | 625 | 43 tests / 134 assertions across every area the sprint requires. |

---

## 3. Files Modified

| File | Change | Risk |
| --- | --- | --- |
| `config/ai.php` | Added the `openrouter` provider block. No existing block touched. | None — additive; disabled by default. |
| `app/Providers/AIServiceProvider.php` | Added `registerOpenRouterProvider()` + `makeOpenRouterProvider()` and one call in `boot()`. No existing registration touched. | None — additive; mirrors the Gemini pattern exactly. |
| `app/AI/Providers/Gemini/GeminiConfig.php` | Deleted the private `stringList()` helper; now calls `ConfigNormalizer::stringList()`. Behaviour identical. | Low — pure de-duplication, covered by the pre-existing Gemini suite (all green). |

**Not modified:** `AIProviderInterface`, `AbstractProvider`, `AbstractProviderClient`,
`AbstractModelRegistry`, `AbstractUsageCalculator`, `AbstractTokenCounter`, `ProviderFactory`,
`ProviderRegistry`, `ProviderRegistration`, `ProviderManager`, `HealthManager`, every DTO, every
Request/Response, every Exception, and the OpenAI, Claude and Gemini adapters.

---

## 4. OpenRouter Components

### `OpenRouterProvider`

| Method | Behaviour |
| --- | --- |
| `generateText()` | `POST /chat/completions`, normalized into `TextResponse`. |
| `healthCheck()` | `GET /key` + catalogue verification → `ProviderHealthDTO`. |
| `estimateCost()` | Local token estimate × configured rates. Never calls the API. |
| `countTokens()` | Shared character-ratio estimate against the resolved model. |
| `supportedModels()` | The configured catalogue. |
| `supportsStreaming()` | Configured flag. |
| `supportsFunctionCalling()` | Configured flag. |
| `providerName()` | `"openrouter"`. |
| `providerVersion()` | `"1.0.0"`. |
| `modelMetadata()` | `ModelMetadataDTO` for every configured model. |
| `modelMetadataFor()` | Metadata for one model; resolves (and validates) the identifier first. |
| `generateImage()` / `generateVideo()` / `generateVoice()` | Inherited from `AbstractProvider` → `UnsupportedCapabilityException` with the correct capability. |

### `OpenRouterClient` routes

| Route | Purpose |
| --- | --- |
| `POST /chat/completions` | Text generation across every routed vendor. |
| `GET /key` | Credential metadata — the health probe (authenticates without billing). |
| `GET /models/{author}/{slug}/endpoints` | Catalogue metadata for one model — model verification. |

Headers on every request: `Authorization: Bearer …`, plus `HTTP-Referer` and `X-Title` when configured,
plus any additional configured headers. Configured headers are applied *before* the credential, so a
misconfigured `Authorization` entry can never displace real authentication (test-covered).

---

## 5. Shared Components

### Reused unchanged (zero duplication)

| Component | What OpenRouter got for free |
| --- | --- |
| `AbstractProviderClient` | Base URL, timeout, retry, JSON decoding, and the mapping of transport failures onto `ProviderTimeoutException` / `ProviderNetworkException` / `ProviderRateLimitException` / `ProviderAuthenticationException` / `ProviderApiException`. |
| `AbstractProvider` | Capability defaults, the unsupported-capability contract, `elapsedMilliseconds()`. |
| `AbstractModelRegistry` | Configured model list + `resolve()` / `resolveFrom()` with the config-error path. |
| `AbstractUsageCalculator` | Per-million-token pricing arithmetic and `UsageResponse` assembly. |
| `AbstractTokenCounter` | The character-ratio estimate. |
| `ProviderConfigResolver`, `ProviderConfigDTO` | Configuration resolution. |
| `ProviderRegistry` / `ProviderFactory` / `ProviderManager` / `HealthManager` | Registration, lazy construction, routing, health aggregation. |

### Extracted this sprint (item 11 review)

**`App\AI\Support\ConfigNormalizer`** — the only extraction made. `GeminiConfig` carried a private
`stringList()` helper and OpenRouter needed the same coercion plus a header-map and positive-int
variant. One shared, vendor-agnostic helper now serves both; Gemini delegates to it.

### Deliberately **not** extracted

| Candidate | Why not |
| --- | --- |
| The `healthCheck()` try/catch shape (repeated in all four providers) | Each probe differs in route, details and status semantics; the common part is a four-line `try`. Extracting it would mean editing three FINAL adapters for negative net value. |
| `fromUsage()` token mapping | Each provider maps *its own vendor's* payload (`input_tokens` vs `prompt_tokens` vs `promptTokenCount`). That is provider-specific translation by definition, not duplication. |
| Reusing `OpenAIResponseNormalizer` for the chat-completions shape | OpenRouter must not depend on the OpenAI adapter. Cross-provider coupling would break the isolation the architecture is built on. |
| `array_filter(… !== null)` payload building | Three-token idiom; a shared abstraction would cost more than it saves. |

---

## 6. Configuration

Read **only** from `config/ai.php` (and therefore the environment). No credential, endpoint, header,
model identifier, context window or price is hardcoded in `app/`.

```php
'openrouter' => [
    'enabled'                   => (bool) env('OPENROUTER_ENABLED', false),
    'api_key'                   => env('OPENROUTER_API_KEY'),
    'base_url'                  => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'default_model'             => env('OPENROUTER_DEFAULT_MODEL'),
    'timeout'                   => (int) env('OPENROUTER_TIMEOUT', 30),
    'max_retries'               => (int) env('OPENROUTER_MAX_RETRIES', 2),
    'models'                    => /* comma-separated OPENROUTER_MODELS */,
    'http_referer'              => env('OPENROUTER_HTTP_REFERER'),
    'app_name'                  => env('OPENROUTER_APP_NAME'),
    'headers'                   => [],
    'usage_accounting'          => (bool) env('OPENROUTER_USAGE_ACCOUNTING', true),
    'supports_streaming'        => (bool) env('OPENROUTER_SUPPORTS_STREAMING', true),
    'supports_function_calling' => (bool) env('OPENROUTER_SUPPORTS_FUNCTION_CALLING', true),
    'model_metadata'            => [],
    'pricing'                   => [],
    'priority'                  => (int) env('OPENROUTER_PRIORITY', 70),
    'options'                   => [],
],
```

| Requirement | Key |
| --- | --- |
| API Key | `api_key` → `OPENROUTER_API_KEY` |
| Base URL | `base_url` → `OPENROUTER_BASE_URL` |
| Timeout | `timeout` → `OPENROUTER_TIMEOUT` |
| Retry | `max_retries` → `OPENROUTER_MAX_RETRIES` |
| Headers | `headers` (map, applied last) |
| HTTP Referer | `http_referer` → `OPENROUTER_HTTP_REFERER` |
| Application Name | `app_name` → `OPENROUTER_APP_NAME` (sent as `X-Title`) |
| Models | `models` → `OPENROUTER_MODELS` (comma-separated) |

**Environment variables** (all optional; the provider stays unregistered while `OPENROUTER_ENABLED` is
false — verified at runtime):

```
OPENROUTER_ENABLED, OPENROUTER_API_KEY, OPENROUTER_BASE_URL, OPENROUTER_DEFAULT_MODEL,
OPENROUTER_TIMEOUT, OPENROUTER_MAX_RETRIES, OPENROUTER_MODELS, OPENROUTER_HTTP_REFERER,
OPENROUTER_APP_NAME, OPENROUTER_USAGE_ACCOUNTING, OPENROUTER_SUPPORTS_STREAMING,
OPENROUTER_SUPPORTS_FUNCTION_CALLING, OPENROUTER_PRIORITY
```

Missing `api_key` or `base_url` raises `ProviderNotConfiguredException` at construction — the adapter
never runs half-configured.

---

## 7. Dynamic Model Registry

Models come from configuration alone. `grep` for any vendor model name across `app/` returns nothing.

- `all()` — the configured catalogue.
- `resolve(?string)` — requested model, else the default; rejects anything unlisted with
  `ProviderNotConfiguredException` **before** any HTTP call is made (test-covered).
- `metadata()` / `metadataFor()` — see §12.
- `upstreamProvider()` / `upstreamProviders()` — the vendor namespace of an identifier.
- `catalogueRoute()` — splits `vendor/model[:variant]` into the segments the catalogue route addresses;
  the variant selects a routing tier of the same model, so it is excluded from the path.
- `contextWindow()` / `defaultContextWindow()` — configured windows; `null` when undeclared.

### Supported Models

Whatever the deployment lists. Every family named in the sprint brief is reachable by configuration
alone, with no code change: OpenAI GPT (`openai/…`), Claude (`anthropic/…`), Gemini (`google/…`),
DeepSeek (`deepseek/…`), Llama (`meta-llama/…`), Mistral (`mistralai/…`), Qwen (`qwen/…`) — and any
model published after this adapter was written. A dedicated test proves four never-before-seen
identifiers work end to end purely from configuration.

---

## 8. Response Normalization

Every OpenRouter payload becomes a provider-independent object. Nothing downstream sees a vendor shape
— or learns which upstream vendor served the call.

| Output | Source |
| --- | --- |
| `TextResponse` | `choices[0].message.content` — accepted as a plain string **or** as typed content parts (routed vendors differ). Falls back to `choices[0].text`. |
| `UsageResponse` | `usage` block → prompt / completion / total tokens, cost, currency, execution time. |
| `TokenResponse` | `usage.total_tokens` — the vendor's authoritative post-hoc count. |
| `ProviderResponseDTO` | `envelope()` → success flag, modality, provider key, model, payload, usage. |

`model` reports what actually served the request (e.g. a `:nitro` tier) while pricing uses the resolved
configured identifier. The full payload is retained on `TextResponse->raw`, so router specifics (serving
vendor, `native_finish_reason`, generation id) stay available for telemetry without widening the shared
contract. A payload that cannot be understood raises `ProviderApiException` rather than yielding an
empty result.

---

## 9. Usage Tracking

| Field | Source |
| --- | --- |
| Prompt tokens | `usage.prompt_tokens` |
| Completion tokens | `usage.completion_tokens` |
| Total tokens | `usage.total_tokens` (falls back to prompt + completion) |
| Estimated cost | Vendor-settled `usage.cost` when reported, else configured per-million rates |
| Execution time | `hrtime()` around the call, in milliseconds |
| Selected model | Resolved model for pricing; served model on the response |

## 10. Cost Estimation

- **Preflight** (`estimateCost()`): local token estimate × configured rates. No network call, so cost
  estimation can never fail a request that has not been made.
- **Post-hoc**: the settled charge OpenRouter reports, which is authoritative for a router.
- **Unpriced model**: cost is `0.0`. No rate is ever invented.

## 11. Health Check

`ProviderHealthDTO` with:

| Detail | Meaning |
| --- | --- |
| `default_model` | The resolved configured model. |
| `model_verified` | `true` / `false` / `null` (identifier not namespaced, so not addressable). |
| `models_configured` | Size of the configured catalogue. |
| `upstream_providers` | Distinct vendors reachable through it. |
| `usage_accounting` | Whether settled-cost reporting is on. |
| `key_label`, `credit_limit`, `credits_used`, `free_tier` | Credential facts, omitted when the vendor does not return them. |

| Outcome | Status |
| --- | --- |
| Authenticated + model verified | `Healthy` |
| Authenticated, model unverifiable | `Degraded` (still operational) |
| Auth failure, network failure, timeout, or no model configured | `Unavailable` with the reason |

Both probes are read-only — no generation is ever billed by a health check.

## 12. Model Metadata

`ModelMetadataDTO` is produced for **every** configured model, assembled from three sources in
precedence order: the explicit `model_metadata` block, the `pricing` block, then the identifier itself.

| Field | Resolution |
| --- | --- |
| `provider` | Configured value, else the identifier namespace (`anthropic/claude-x` → `anthropic`). |
| `capabilities` | Configured modalities, defaulting to `[Text]` — the modality every chat-completions model answers. |
| `streaming` | Model-level flag, falling back to the provider-level declaration. |
| `functionCalling` | Model-level flag, falling back to the provider-level declaration. |
| `contextWindow` | Configured, else `null`. Never guessed. |
| `maxOutputTokens` | Configured, else `null`. |
| `pricing` | Configured per-million rates; `isPriced()` reports whether any exist. |

The default model's context window is surfaced to the registry as
`ProviderCapabilityDTO::$maxInputTokens`, so capability discovery gains it without a new contract.

---

## 13. Registration

Registered in `AIServiceProvider::boot()` through `ProviderRegistry::register()` with a lazy build
closure, exactly as the other three are:

```php
$this->registry()->register(
    'openrouter',
    fn (ProviderConfigDTO $providerConfig): OpenRouterProvider => $this->makeOpenRouterProvider(
        OpenRouterConfig::fromProviderConfig($providerConfig),
    ),
    new ProviderCapabilityDTO(
        key: 'openrouter', name: 'OpenRouter', version: OpenRouterProvider::VERSION,
        text: true, streaming: $config->supportsStreaming,
        functionCalling: $config->supportsFunctionCalling, models: $models->all(),
        maxInputTokens: $models->defaultContextWindow(),
    ),
    priority: (int) ($settings['priority'] ?? 70),
);
```

**Zero provider-specific logic entered `ProviderManager`, `ProviderRegistry` or `ProviderFactory`.**
Registration is skipped entirely when `enabled` is false (test-covered), and the build closure is
invoked lazily by the factory — registering neither instantiates the adapter nor calls any API.

Default routing priority is 70, below OpenAI (100), Claude (90) and Gemini (80): the aggregator is the
fallback route, and the ordering is configuration-driven.

---

## 14. Dependency Graph

```
AIServiceProvider::boot()
  └── ProviderRegistry::register('openrouter', closure, ProviderCapabilityDTO, priority)
                                       │
ProviderManager ──► ProviderFactory ──► closure(ProviderConfigDTO)
                                       └── OpenRouterProvider
                                            ├── OpenRouterClient ──────────── AbstractProviderClient ── Illuminate\Http\Client\Factory
                                            ├── OpenRouterModelRegistry ───── AbstractModelRegistry ─── ModelMetadataDTO
                                            ├── OpenRouterUsageCalculator ─── AbstractUsageCalculator ─ UsageResponse
                                            ├── OpenRouterResponseNormalizer ─ OpenRouterUsageCalculator
                                            │                                  └─► TextResponse / TokenResponse / ProviderResponseDTO
                                            ├── OpenRouterTokenCounter ─────── AbstractTokenCounter ─── TokenResponse
                                            └── OpenRouterConfig ───────────── ConfigNormalizer
                                                    ▲
                            ProviderConfigResolver ─┘  (config/ai.php → ProviderConfigDTO)

HealthManager ──► ProviderFactory ──► OpenRouterProvider::healthCheck() ──► ProviderHealthDTO
```

No arrow points from OpenRouter to OpenAI, Claude or Gemini, or from the registry/factory/manager to
any concrete provider.

---

## 15. Verification

All commands run from `Backend/` on PHP 8.3.30.

| Check | Command | Result |
| --- | --- | --- |
| Composer | `composer validate --strict` | ✅ `./composer.json is valid` |
| Autoload | `composer dump-autoload -o` | ✅ 6898 classes, no warnings |
| PHPStan | `vendor/bin/phpstan analyse` (larastan, level 5) | ✅ **No errors** |
| Pint | `vendor/bin/pint --test` | ✅ `{"tool":"pint","result":"passed"}` |
| PHPUnit (OpenRouter) | `vendor/bin/phpunit --filter OpenRouterProviderTest` | ✅ **43 tests, 134 assertions** |
| PHPUnit (full suite) | `vendor/bin/phpunit` | ✅ **125 tests, 376 assertions** |
| Runtime config | `artisan tinker` | ✅ `openrouter` present, disabled by default, `available() === []` |

PHPStan reported four narrowing errors and one redundant `is_array()` on the first run. Both were fixed
at the source (redundant runtime guards over already-typed values removed) — no baseline entry, no
`@phpstan-ignore`, no cast added to silence anything.

### Test Coverage by Requirement

| Sprint requirement | Tests |
| --- | --- |
| Provider registration | registry/factory/manager wiring, capability DTO, priority, disabled-provider skip, `maxInputTokens` |
| Configuration loading | resolver round-trip, header assembly, trimming, missing key, missing base URL, attribution omission, credential precedence |
| Dynamic model loading | configured catalogue, four unseen future identifiers end to end, unlisted-model rejection with no HTTP call |
| Text generation | payload mapping (messages, system hoisting, max tokens, temperature, top-p, stop, tools, usage accounting), bare prompt, router option pass-through |
| Response normalization | string content, typed content parts, served-model echo, finish reason (+ `native_finish_reason`), usage, `ProviderResponseDTO` envelope, `TokenResponse`, parsing failure |
| Health check | healthy, degraded (unroutable model), unavailable (401), unavailable (no model configured), aggregate isolation |
| Token counting | local estimate, resolved model, no network call |
| Cost estimation | configured rates, unpriced model → 0.0, vendor-settled cost override |
| Typed exception mapping | 401 auth, 403 moderation → API (not auth), 402 credits, 429 rate limit, 400 invalid model, embedded 2xx error, embedded 429, timeout, network, unparseable |
| Unsupported capabilities | image, video, voice — each with the correct capability in context |
| Model metadata | every configured model, model-level overrides, namespace derivation, `toArray()`, unconfigured rejection |

### Regression Results

| Suite | Before | After |
| --- | --- | --- |
| `OpenAIProviderTest` | pass | ✅ pass |
| `ClaudeProviderTest` | pass | ✅ pass |
| `GeminiProviderTest` | pass | ✅ pass (including the `ConfigNormalizer` delegation) |
| `AIProviderSubsystemTest`, `AIFoundationTest`, `ContainerBindingsTest` | pass | ✅ pass |
| Sprint 5.1 / 5.2 suites (domain, repository, models, auth, health endpoint, DTOs, enums) | pass | ✅ pass |
| **Total** | 82 tests | **125 tests, 376 assertions, 0 failures** |

A dedicated regression test boots all four providers together, asserts each resolves to its own key, and
pins the configuration-driven priority ordering `['openai', 'claude', 'gemini', 'openrouter']`. A second
test proves aggregate health isolates providers: OpenRouter healthy while OpenAI returns 500.

**Environment note:** running `vendor/bin/phpunit` in a checkout with no `.env` produces two
`MissingAppKeyException` errors in `ExampleTest` and `ModelRelationshipsTest`. These are pre-existing and
unrelated to this sprint (neither test touches `App\AI`); with `APP_KEY` set, the suite is 125/125 green.

---

## 16. Known Risks

| # | Risk | Mitigation / Status |
| --- | --- | --- |
| 1 | **Preflight token counts are approximate.** The tokenizer that applies depends on which upstream vendor serves the request, so no exact preflight count exists. | Documented on `OpenRouterTokenCounter`. Estimates are conservative and used only for preflight accounting; the vendor's `usage` block is authoritative after execution. |
| 2 | **Per-model context windows and prices must be maintained by hand.** OpenRouter publishes them via `GET /models`, but consuming that would make configuration non-deterministic. | Deliberate: all metadata is configured. An undeclared window stays `null` and an unpriced model costs `0.0` rather than being guessed. A future sprint could add an opt-in catalogue sync. |
| 3 | **Health checks make two HTTP calls.** | Both are read-only and unbilled. `HealthManager` already caches via `ai.health.cache_ttl`. |
| 4 | **`usage_accounting` defaults to on**, adding `usage: {include: true}` to every request. | Free and read-only; it buys accurate spend tracking. Set `OPENROUTER_USAGE_ACCOUNTING=false` to opt out — test-covered in both states. |
| 5 | **Streaming is declared but not consumed.** OpenRouter streams over SSE on the same route; the shared provider contract is synchronous. | Identical to the three existing providers. `supportsStreaming()` reports the configured capability without altering the payload. |
| 6 | **Image output is not implemented.** Some routed models can return images through the chat route. | Deliberate: the abstraction has no image-model configuration for this provider, and inventing one would overstate capability. `Capability::Image` is not declared, so the registry never routes image work here. |
| 7 | **Model identifiers without a `/` cannot be catalogue-verified.** | Reported honestly as `model_verified: null` and left `Healthy` — not silently treated as verified or failed. |
| 8 | **`GeminiConfig` was touched.** | Pure de-duplication with identical behaviour; the full pre-existing Gemini suite passes unchanged. |

---

## 17. Preparation for Sprint 5.3.6

**What the next provider inherits at no cost:**

- The four-provider pattern is now proven stable: `Provider` + `Client` + `Config` + `ModelRegistry` +
  `UsageCalculator` + `ResponseNormalizer` + `TokenCounter`, each extending a shared abstract base.
- `ConfigNormalizer` for configuration coercion.
- `ModelMetadataDTO` for per-model description — already provider-independent, ready for any provider
  needing per-model context windows or prices.
- The registration recipe: one `register…Provider()` + one `make…Provider()` method in
  `AIServiceProvider`, one config block, one env-gated `enabled` flag, one priority.
- The test recipe: build the adapter from a config array, `Http::fake()` the routes, assert on shared
  DTOs. 43 tests in this sprint follow it.

**Open items a future sprint may pick up (none blocking):**

1. Optional catalogue sync from `GET /models` to populate context windows and pricing automatically.
2. Streaming support, which needs a contract decision at the foundation level — it affects all four
   providers and must not be bolted onto one.
3. Image output through the chat route for the routed models that support it (needs image-model
   configuration first).

**Foundation state:** unchanged and stable. Sprints 5.3.1–5.3.5 have added four providers without a
single modification to `AIProviderInterface`, `ProviderFactory`, `ProviderRegistry`, `ProviderManager`
or `HealthManager` — the Open/Closed principle has held for four consecutive integrations.

---

**Sprint 5.3.5 complete. Stopping here as instructed — Sprint 5.3.6 not started.**
