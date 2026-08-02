# Sprint 5.3.6 — ElevenLabs Provider

**Status:** Complete
**Scope:** ElevenLabs text-to-speech only. No voice cloning, conversational AI, dubbing, sound effects,
speech-to-text, voice isolation or audio editing. No Veo, no queue, no workflow engine.
**Foundation:** `AIProviderInterface`, `ProviderFactory`, `ProviderRegistry`, `ProviderManager` and
`HealthManager` were audited and reused — none was modified. One behaviour-preserving extraction was made
inside `AbstractProviderClient` (§5).

---

## 1. Architecture Summary

ElevenLabs is the studio's **first voice provider** and the **first vendor that answers with binary
rather than JSON**. Both firsts fit the existing abstraction without changing it:

| New problem | How the existing architecture absorbed it |
| --- | --- |
| The response body is audio, not JSON. | The shared client's JSON decoder is bypassed for that one route while its timeout/network taxonomy is still inherited — via a small extraction that made the transport step reusable (§5). |
| `VoiceResponse` carries a *reference* to audio, not bytes. | Bytes are surfaced as a `data:` URI, exactly as the OpenAI and Gemini normalizers already do for inline image output. One representation of generated media across every modality. |
| The vendor meters **characters**, not tokens. | Characters are counted exactly and converted to the vendor's billable unit (credits), then priced through the shared per-unit arithmetic unchanged. |
| Voices are studio assets, not model identifiers. | The registry accepts a configured *name* or the raw *identifier*, so callers ask for a voice however they know it. |

Everything else followed the pattern the previous four sprints established — `Provider` + `Client` +
`Config` + registry + `UsageCalculator` + `ResponseNormalizer` + `TokenCounter`, each extending a shared
abstract base, registered through `ProviderRegistry` with a lazy build closure.

```
AIProviderInterface  ◄── AbstractProvider  ◄── ElevenLabsProvider
                                                    │
                          AbstractProviderClient ◄── ElevenLabsClient          (transport + typed errors)
                          AbstractModelRegistry  ◄── ElevenLabsVoiceRegistry   (models, voices, formats)
                          AbstractUsageCalculator◄── ElevenLabsUsageCalculator (characters → credits → cost)
                          AbstractTokenCounter   ◄── ElevenLabsTokenCounter    (exact character count)
                                                     ElevenLabsResponseNormalizer (audio → shared DTOs)
                                                     ElevenLabsConfig          (typed, validated settings)
```

### Design decisions worth recording

| Decision | Rationale |
| --- | --- |
| **`countTokens()` reports the capability unsupported.** | ElevenLabs has no tokenizer and no token concept. Returning a character count from a method that promises *tokens* would misstate what the vendor measures. Characters are available exactly, through the counter and on every response. |
| **An exhausted quota is *not* an authentication failure.** | The vendor reports an empty character balance as HTTP 401. The shared client maps 401 to `ProviderAuthenticationException`, which would send operators rotating a perfectly good key. Quota statuses are separated out and reported as HTTP 402 — the same treatment OpenRouter's insufficient-credits case received in 5.3.5. |
| **A quota failure is not a rate limit either.** | Rate-limit exceptions invite retry/backoff. An empty balance does not resolve by waiting, so retrying would just burn calls. |
| **Audio becomes a data URI, not a stored file.** | Writing to a disk would give a provider adapter a filesystem side effect, a naming scheme and a cleanup obligation. The data URI matches the precedent already set for image output. Size is recorded as a known risk (§16). |
| **Duration is left `null`.** | The audio route does not report it, and it is only derivable for some formats. A field that is sometimes computed and sometimes null is a trap for consumers; the vendor's `/with-timestamps` route is the clean future path. |
| **Health = subscription probe + voice and model verification.** | `GET /user/subscription` proves connectivity and authentication without synthesising audio; the voice and model are then verified against the account. An authenticated account with an unverifiable asset is `Degraded`, not `Unavailable` — consistent with 5.3.5. |
| **Text/image/video are not overridden.** | `AbstractProvider` already throws `UnsupportedCapabilityException` with the correct capability. Re-declaring three throwing methods would be duplication. Documented in the class docblock. |
| **`supportsFunctionCalling()` is not overridden either.** | Text-to-speech has no function calling; the inherited `false` is the honest answer, not a deployment choice. |
| **Voice settings arrive through `options`.** | Stability, similarity, style and speaker boost have no field on the shared `VoiceRequest`, and the contract was not widened for one provider. They are read from the options bag in both the vendor's spellings and shorter aliases. |

---

## 2. Files Created

### ElevenLabs provider (`app/AI/Providers/ElevenLabs/`)

| File | Lines | Responsibility |
| --- | --- | --- |
| `ElevenLabsProvider.php` | 255 | `AIProviderInterface` implementation: synthesis, health, cost, capability reporting. |
| `ElevenLabsClient.php` | 164 | Transport: API-key header, routes, binary decoding, vendor error taxonomy. |
| `ElevenLabsConfig.php` | 132 | Typed, validated settings + the single voice-settings normaliser. |
| `ElevenLabsVoiceRegistry.php` | 95 | Models, voices and output formats, and resolution against them. |
| `ElevenLabsUsageCalculator.php` | 49 | Characters → credits → cost. |
| `ElevenLabsResponseNormalizer.php` | 98 | Audio bytes → `VoiceResponse` / `UsageResponse` / `ProviderResponseDTO`. |
| `ElevenLabsTokenCounter.php` | 32 | Exact character count — the vendor's billing unit. |

### Tests

| File | Lines | Contents |
| --- | --- | --- |
| `tests/Feature/ElevenLabsProviderTest.php` | 650 | 47 tests / 146 assertions across every area the sprint requires. |

---

## 3. Files Modified

| File | Change | Risk |
| --- | --- | --- |
| `app/AI/Http/AbstractProviderClient.php` | Extracted the request/transport-error step out of the private `send()` into a `protected dispatch()`; `send()` is now `decode(dispatch(…))`. | Low — pure extraction, no behaviour change. All four existing provider suites pass unchanged. |
| `app/AI/Support/ConfigNormalizer.php` | Added `keyedMap()` for `name => value` maps supplied as either an array or a `name:value,…` string. | None — additive. |
| `config/ai.php` | Added the `elevenlabs` provider block. No existing block touched. | None — additive; disabled by default. |
| `app/Providers/AIServiceProvider.php` | Added `registerElevenLabsProvider()` + `makeElevenLabsProvider()` and one call in `boot()`. | None — additive; mirrors the existing registrations. |

**Not modified:** `AIProviderInterface`, `AbstractProvider`, `AbstractModelRegistry`,
`AbstractUsageCalculator`, `AbstractTokenCounter`, `ProviderFactory`, `ProviderRegistry`,
`ProviderRegistration`, `ProviderManager`, `HealthManager`, `CapabilityReport`, every DTO, every
Request/Response (including `VoiceRequest` and `VoiceResponse`), every Exception, and the OpenAI, Claude,
Gemini and OpenRouter adapters.

---

## 4. ElevenLabs Components

### `ElevenLabsProvider`

| Method | Behaviour |
| --- | --- |
| `generateVoice()` | `POST /text-to-speech/{voice_id}`, normalized into `VoiceResponse`. |
| `healthCheck()` | Subscription probe + voice and model verification → `ProviderHealthDTO`. |
| `estimateCost()` | Exact character count × credit multiplier × configured rate. Never calls the API. |
| `supportedModels()` | The configured text-to-speech models. |
| `supportedVoices()` | The configured voices as `name => voice id`. |
| `supportsStreaming()` | Configured flag (the vendor streams on a dedicated route). |
| `supportsFunctionCalling()` | Inherited `false` — not offered by text-to-speech. |
| `providerName()` | `"elevenlabs"`. |
| `providerVersion()` | `"1.0.0"`. |
| `countTokens()` | `UnsupportedCapabilityException` — the vendor meters characters, not tokens. |
| `generateText()` / `generateImage()` / `generateVideo()` | Inherited from `AbstractProvider` → `UnsupportedCapabilityException` with the correct capability. |

### `ElevenLabsClient` routes

| Route | Purpose |
| --- | --- |
| `POST /text-to-speech/{voice_id}` | Synthesis. Returns raw audio; body, media type and request id are handed back. |
| `GET /user/subscription` | Credential and quota metadata — the health probe (authenticates without synthesising). |
| `GET /voices/{voice_id}` | Voice verification. |
| `GET /models` | Model verification. |

Header on every request: `xi-api-key`. The synthesis route additionally sends `Accept: */*`, because it
answers with audio on success and JSON on failure and must not advertise a preference for either.

---

## 5. Shared Component Audit (item 12)

### The one extraction made

`AbstractProviderClient::send()` was private and did two things: execute the request (mapping
`ConnectionException` onto `ProviderTimeoutException` / `ProviderNetworkException`) and JSON-decode the
result. A binary route needs the first half and must not have the second.

The alternative would have been to restate the timeout/network mapping inside `ElevenLabsClient` —
duplicating the exact logic the shared client exists to own, and letting the two copies drift. Instead
the transport step became a `protected dispatch()`:

```php
protected function dispatch(Closure $call): Response      // execute + typed transport errors
private   function send(Closure $call): array             // decode(dispatch(…))
```

No behaviour changed for any existing provider; `send()` is the same composition it always was. This is
the minimum change that let a non-JSON vendor inherit the shared error taxonomy.

### Also added

`ConfigNormalizer::keyedMap()` — a `name => value` map from either an array or a
`name:value,name:value` string, which is how a map survives a single environment variable. Needed for
voices; reusable by any future provider with named resources.

### Reused unchanged (zero duplication)

| Component | What ElevenLabs got for free |
| --- | --- |
| `AbstractProviderClient` | Base URL, timeout, retry, JSON routes, and the mapping of failures onto `ProviderTimeoutException` / `ProviderNetworkException` / `ProviderRateLimitException` / `ProviderAuthenticationException` / `ProviderApiException`. |
| `AbstractProvider` | Capability defaults, the unsupported-capability contract, `elapsedMilliseconds()`. |
| `AbstractModelRegistry` | Configured model list + `resolve()` with its config-error path. |
| `AbstractUsageCalculator` | Per-unit pricing arithmetic and `UsageResponse` assembly. |
| `AbstractTokenCounter` | The base class shape (its estimate is deliberately replaced). |
| `ConfigNormalizer` | `stringList`, `stringMap`, `nonEmptyString` (added in 5.3.5). |
| `ProviderConfigResolver`, `ProviderConfigDTO` | Configuration resolution. |
| Registry / Factory / Manager / HealthManager | Registration, lazy construction, routing, health aggregation. |

### Deliberately **not** extracted

| Candidate | Why not |
| --- | --- |
| The `healthCheck()` try/catch shape (now in all five providers) | Each probe differs in routes, details and status semantics; the shared part is a four-line `try`. Extracting it means editing four FINAL adapters for negative net value. Re-reviewed this sprint and the answer is unchanged. |
| A `data:` URI builder (image normalizers do the same concatenation) | Three normalizers each build it from a different source shape; the common part is one string concatenation. |
| A shared "quota/credits exhausted" exception type | That is a new contract on the foundation. The existing `ProviderApiException` with HTTP 402 already carries the meaning, and matches how 5.3.5 handled the same situation. |

---

## 6. Configuration

Read **only** from `config/ai.php` (and therefore the environment). No credential, endpoint, model,
voice, output format or price is hardcoded in `app/` — verified by search.

```php
'elevenlabs' => [
    'enabled'            => (bool) env('ELEVENLABS_ENABLED', false),
    'api_key'            => env('ELEVENLABS_API_KEY'),
    'base_url'           => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
    'default_model'      => env('ELEVENLABS_DEFAULT_MODEL'),
    'timeout'            => (int) env('ELEVENLABS_TIMEOUT', 30),
    'max_retries'        => (int) env('ELEVENLABS_MAX_RETRIES', 2),
    'models'             => /* comma-separated ELEVENLABS_MODELS */,
    'voices'             => env('ELEVENLABS_VOICES', ''),        // "rachel:21m00…,adam:pNInz…"
    'default_voice'      => env('ELEVENLABS_DEFAULT_VOICE'),
    'output_format'      => env('ELEVENLABS_OUTPUT_FORMAT'),     // e.g. mp3_44100_128
    'output_formats'     => /* comma-separated allow-list; empty = unrestricted */,
    'voice_settings'     => [],                                  // stability, similarity, style, speaker_boost, speed
    'credit_multipliers' => [],                                  // credits per character, keyed by model
    'supports_streaming' => (bool) env('ELEVENLABS_SUPPORTS_STREAMING', true),
    'pricing'            => [],                                  // per-million-credit USD, keyed by model
    'priority'           => (int) env('ELEVENLABS_PRIORITY', 60),
    'options'            => [],
],
```

| Requirement | Key |
| --- | --- |
| API Key | `api_key` → `ELEVENLABS_API_KEY` |
| Base URL | `base_url` → `ELEVENLABS_BASE_URL` |
| Timeout | `timeout` → `ELEVENLABS_TIMEOUT` |
| Retry | `max_retries` → `ELEVENLABS_MAX_RETRIES` |
| Voice Models | `models` → `ELEVENLABS_MODELS` |
| Voice IDs | `voices` → `ELEVENLABS_VOICES`, `default_voice` → `ELEVENLABS_DEFAULT_VOICE` |
| Output Format | `output_format` (+ optional `output_formats` allow-list) |
| Voice Settings | `voice_settings` (defaults, overridable per request) |

Missing `api_key` or `base_url` raises `ProviderNotConfiguredException` at construction — the adapter
never runs half-configured. Verified at runtime: the block resolves, is disabled by default, and
`available()` stays empty until `ELEVENLABS_ENABLED` is set.

---

## 7. Voice Registry

Voices come from configuration alone. A search of `app/` for any stock voice name or identifier returns
nothing.

- `voices()` / `voiceNames()` / `voiceIds()` — the configured catalogue.
- `resolveVoice(?string)` — accepts a configured **name** (case-insensitively) **or** the raw
  **identifier**, falling back to the configured default; rejects anything unlisted with
  `ProviderNotConfiguredException` **before** any HTTP call is made.
- `voiceName(string)` — reverse lookup for reporting.
- `resolve(?string)` — the text-to-speech model, inherited from the shared registry.
- `resolveFormat(?string)` — the output format, validated only when an allow-list is configured.

**Why formats are treated more leniently than voices and models:** an unknown voice or model is a
routing and billing risk, so those are strict. An unknown format is a presentation detail the vendor
rejects cheaply, so an empty allow-list stays permissive rather than forcing every deployment to
enumerate the vendor's format catalogue.

### Supported Voices

Whatever the deployment configures. Every case named in the sprint brief works with no code change:
stock voices (Rachel, Bella, Adam), custom cloned voices, and voices that do not exist yet. A dedicated
test proves a custom clone and an unreleased identifier work end to end purely from configuration.

---

## 8. Voice Generation

| Input (item 6) | Source |
| --- | --- |
| Text | `VoiceRequest->input` → `text` |
| Voice ID | `VoiceRequest->voice` (name or id) → resolved → route segment |
| Language | `VoiceRequest->language` → `language_code` |
| Speed | `VoiceRequest->speed` → `voice_settings.speed` (wins over the same key in `options`) |
| Stability | `options.stability` → `voice_settings.stability` |
| Similarity | `options.similarity` \| `similarity_boost` → `voice_settings.similarity_boost` |
| Style | `options.style` → `voice_settings.style` |
| Speaker Boost | `options.speaker_boost` \| `use_speaker_boost` → `voice_settings.use_speaker_boost` |
| Output Format | `VoiceRequest->format` → `output_format` query parameter |

Configured `voice_settings` defaults are overlaid with the request's own, normalised once by
`ElevenLabsConfig::normalizeVoiceSettings()` so defaults and overrides can never diverge. Nested
`options.voice_settings` in the vendor's spelling is also accepted. Any option consumed as a voice
setting is removed from the top level of the payload, so it cannot appear twice; anything the shared
contract does not model (`seed`, `apply_text_normalization`, pronunciation dictionaries) passes through
untouched.

**Returns:** `VoiceResponse`, wrapped on demand into `ProviderResponseDTO` by the normalizer's
`envelope()`.

---

## 9. Response Normalization

| Output | Source |
| --- | --- |
| `VoiceResponse->audio` | `data:{media type};base64,{bytes}` |
| `VoiceResponse->model` / `voice` / `format` | The resolved model, voice id and output format |
| `VoiceResponse->durationSeconds` | `null` — not reported by the vendor, not invented |
| `UsageResponse` | Credits, cost, currency, execution time |
| `ProviderResponseDTO` | `envelope()` → success flag, modality, provider key, model, payload, usage |

Media type comes from the vendor's `Content-Type` (parameters stripped); when absent it falls back to the
family named by the output format (`pcm_16000` → `audio/pcm`). An empty body raises
`ProviderApiException` rather than yielding a zero-byte "success".

Everything the shared contract does not express travels on `VoiceResponse->raw`: `characters`, `credits`,
`voice_id`, `voice_name`, `model_id`, `output_format`, `media_type`, `bytes`, `request_id`.

---

## 10. Usage Tracking

| Field (item 8) | Where it lives |
| --- | --- |
| Characters | `raw.characters` — exact count of the submitted text |
| Credits | `raw.credits` and `UsageResponse->promptTokens` (the billed input unit) |
| Estimated Cost | `UsageResponse->cost` |
| Execution Time | `UsageResponse->executionTimeMs` |
| Voice Used | `VoiceResponse->voice` + `raw.voice_name` |
| Model Used | `VoiceResponse->model` |

**On the unit convention:** `UsageResponse` names its fields for tokens, and ElevenLabs has none. Rather
than widen the DTO for one provider, the billed unit — credits — is carried in the input-unit field, so
cost stays derivable from what is reported (`promptTokens × rate`) and spend accounting is uniform across
all five providers. The underlying character count is always alongside it in `raw`.

## 11. Cost Estimation

```
characters (exact)  ──×  credit multiplier (configured, default 1.0)  ──→  credits
credits  ──×  configured per-million-credit rate  ──→  cost
```

- **Preflight** (`estimateCost()`): exact, and makes no network call — estimation can never fail a
  request that has not been made.
- **Unpriced model**: `0.0`. No rate is ever invented.
- **Credit multipliers**: configured per model, because the vendor charges less per character on its
  faster models. Which models those are is a configuration fact, not a hardcoded one.

## 12. Health Check

`ProviderHealthDTO` with:

| Detail | Meaning |
| --- | --- |
| `default_model`, `model_verified` | The configured model, and whether the account's catalogue lists it. |
| `default_voice`, `default_voice_name`, `voice_verified` | The configured voice, and whether it still exists on the account. |
| `voices_configured`, `models_configured` | Sizes of the configured catalogues. |
| `output_format` | The configured default format. |
| `tier`, `characters_used`, `character_limit` | Character allowance, omitted when the vendor does not return them. |

| Outcome | Status |
| --- | --- |
| Authenticated, voice and model verified | `Healthy` |
| Authenticated, voice and/or model unverifiable | `Degraded` (still operational; the message names which) |
| Auth failure, network failure, timeout, or no voice/model configured | `Unavailable` with the reason |

Every probe is read-only — no audio is synthesised and no characters are billed by a health check.

---

## 13. Registration

Registered in `AIServiceProvider::boot()` through `ProviderRegistry::register()` with a lazy build
closure, exactly as the other four are:

```php
$this->registry()->register(
    'elevenlabs',
    fn (ProviderConfigDTO $providerConfig): ElevenLabsProvider => $this->makeElevenLabsProvider(
        ElevenLabsConfig::fromProviderConfig($providerConfig),
    ),
    new ProviderCapabilityDTO(
        key: 'elevenlabs', name: 'ElevenLabs', version: ElevenLabsProvider::VERSION,
        voice: true, streaming: $config->supportsStreaming, models: $voices->all(),
    ),
    priority: (int) ($settings['priority'] ?? 60),
);
```

**Zero provider-specific logic entered `ProviderManager`, `ProviderRegistry` or `ProviderFactory`.** Only
`voice` is declared, so the registry routes speech here and never text, image or video work.
Registration is skipped entirely when `enabled` is false (test-covered).

---

## 14. Dependency Graph

```
AIServiceProvider::boot()
  └── ProviderRegistry::register('elevenlabs', closure, ProviderCapabilityDTO, priority)
                                       │
ProviderManager ──► ProviderFactory ──► closure(ProviderConfigDTO)
                                       └── ElevenLabsProvider
                                            ├── ElevenLabsClient ──────────── AbstractProviderClient ── Illuminate\Http\Client\Factory
                                            │                                  └── dispatch()  ◄── binary route reuses the transport
                                            ├── ElevenLabsVoiceRegistry ───── AbstractModelRegistry
                                            ├── ElevenLabsUsageCalculator ─── AbstractUsageCalculator ─ UsageResponse
                                            ├── ElevenLabsResponseNormalizer ─ ElevenLabsUsageCalculator
                                            │                                  └─► VoiceResponse / ProviderResponseDTO
                                            ├── ElevenLabsTokenCounter ─────── AbstractTokenCounter
                                            └── ElevenLabsConfig ───────────── ConfigNormalizer
                                                    ▲
                            ProviderConfigResolver ─┘  (config/ai.php → ProviderConfigDTO)

HealthManager ──► ProviderFactory ──► ElevenLabsProvider::healthCheck() ──► ProviderHealthDTO
```

No arrow points from ElevenLabs to any other provider, or from the registry/factory/manager to any
concrete provider.

---

## 15. Verification

All commands run from `Backend/` on PHP 8.3.30.

| Check | Command | Result |
| --- | --- | --- |
| Composer | `composer validate --strict` | ✅ `./composer.json is valid` |
| Autoload | `composer dump-autoload -o` | ✅ 6906 classes, no warnings |
| PHPStan | `vendor/bin/phpstan analyse` (larastan, level 5) | ✅ **No errors** |
| Pint | `vendor/bin/pint --test` | ✅ `{"tool":"pint","result":"passed"}` |
| PHPUnit (ElevenLabs) | `vendor/bin/phpunit --filter ElevenLabsProviderTest` | ✅ **47 tests, 146 assertions** |
| PHPUnit (full suite) | `vendor/bin/phpunit` | ✅ **172 tests, 522 assertions** |
| Runtime config | `artisan tinker` | ✅ `elevenlabs` present, disabled by default, `available() === []` |

PHPStan and Pint were clean on the first run for this sprint.

### Test Coverage by Requirement

| Sprint requirement | Tests |
| --- | --- |
| Provider registration | registry/factory/manager wiring, capability DTO (`voice` + `streaming` only), priority, disabled-provider skip |
| Configuration loading | resolver round-trip, `name:id` map parsing from a single variable, trimming, voice-setting coercion and alias mapping, unknown-key drop, missing key, missing base URL |
| Voice registry | name resolution (exact and case-insensitive), identifier resolution, default fallback, reverse lookup, custom/future voices end to end, unlisted-voice rejection with no HTTP call, unlisted model, format allow-list, unrestricted formats |
| Voice generation | text/model/language mapping, merged voice settings, typed-speed precedence, nested vendor spelling, passthrough options, key-consumption isolation, output format as query parameter, credential header |
| Response normalization | data URI, media type from header, media type derived from format, `Content-Type` parameter stripping, `ProviderResponseDTO` envelope, raw payload contents, empty-body parsing failure |
| Health check | healthy, degraded (missing voice), degraded (absent model), unavailable (401), unavailable (nothing configured), aggregate isolation |
| Cost estimation | configured rates, unpriced model → 0.0, credit multiplier, exact vs estimated character counting |
| Typed exception mapping | 401 auth, 401 quota → 402 (not auth), 429 rate limit, 404 invalid voice, 422 validation list, string `detail`, timeout, network, empty body |
| Capability contracts | text/image/video unsupported with the correct capability, `countTokens()` unsupported, streaming configurable, function calling false |

### Regression Results

| Suite | After |
| --- | --- |
| `OpenAIProviderTest` | ✅ pass |
| `ClaudeProviderTest` | ✅ pass |
| `GeminiProviderTest` | ✅ pass |
| `OpenRouterProviderTest` | ✅ pass |
| `AIProviderSubsystemTest`, `AIFoundationTest`, `ContainerBindingsTest` | ✅ pass |
| Sprint 5.1 / 5.2 suites (domain, repository, models, auth, health endpoint, DTOs, enums) | ✅ pass |
| **Total** | **172 tests, 522 assertions, 0 failures** (125 before this sprint) |

The `AbstractProviderClient` extraction is the change with regression exposure, and all four existing
provider suites — which exercise it on every request — pass unchanged.

A dedicated test boots all five providers together, asserts each resolves to its own key, and pins the
routing: text stays `['openai', 'claude', 'gemini', 'openrouter']` and voice is `['elevenlabs']`. A
second test proves aggregate health isolates providers: ElevenLabs healthy while OpenAI returns 500.

**Environment note (unchanged from 5.3.5):** with no `.env` present, `ExampleTest` and
`ModelRelationshipsTest` error with `MissingAppKeyException`. Pre-existing and unrelated to this sprint;
with `APP_KEY` set the suite is 172/172.

**One finding worth flagging:** Laravel executes any HTTP request that matches no `Http::fake()` stub
*for real*. While writing these tests one case briefly reached the live ElevenLabs API. This suite now
calls `Http::preventStrayRequests()` in `setUp()`, so a missing stub fails loudly instead. The four
existing provider suites do not have this guard — they pass today, but the same latent exposure exists.
Adding it there means touching FINAL test files, so it is left as a recommendation (§17).

---

## 16. Known Risks

| # | Risk | Mitigation / Status |
| --- | --- | --- |
| 1 | **Data URIs grow with audio length.** A minute of MP3 is roughly 1 MB, ~1.4 MB base64, held in memory and in any serialised response. | Deliberate and consistent with how inline image output is already carried. Practical for the caption- and voiceover-length copy this studio generates. Offloading to a storage disk and returning a path is the clean next step — `VoiceResponse->audio` is documented as a reference, so that change needs no contract change. |
| 2 | **Duration is always `null`.** | The audio route does not report it. The vendor's `/with-timestamps` route returns alignment data and is the honest way to obtain it; a future sprint can add it as an opt-in. |
| 3 | **Health checks make three HTTP calls.** | All read-only, none billed, and `HealthManager` already caches via `ai.health.cache_ttl`. There is no single endpoint that validates connectivity, authentication, voice and model together. |
| 4 | **Credit multipliers and prices are maintained by hand.** | Deliberate: the vendor's plans change and a hardcoded table would rot silently. An unconfigured multiplier defaults to 1 credit per character; an unpriced model costs `0.0` rather than being guessed. |
| 5 | **Quota-exceeded detection depends on the vendor's `detail.status` strings.** | Two known statuses are matched. If the vendor renames them, the failure degrades to `ProviderAuthenticationException` — the pre-existing behaviour, not a crash. Test-covered so a rename surfaces as a test failure. |
| 6 | **Streaming is declared but not consumed.** | Same as the other four providers: the shared contract is synchronous, so `supportsStreaming()` reports the configured capability without altering the request. |
| 7 | **`countTokens()` throws for this provider.** | Any caller that fans a text out across every registered provider to compare token counts must handle `UnsupportedCapabilityException` — which the contract has always permitted. The alternative, reporting characters as tokens, would corrupt cost comparisons. |
| 8 | **`AbstractProviderClient` was modified.** | A pure extraction with no behaviour change, covered by four existing provider suites. It was the minimum needed to let a binary route inherit the shared error taxonomy instead of duplicating it. |
| 9 | **Stray-request exposure in the older test suites.** | See §15. This suite is guarded; the other four are not. |

---

## 17. Preparation for Sprint 5.3.7

**What the next provider inherits at no cost:**

- The pattern now holds across five providers and two modality families: `Provider` + `Client` +
  `Config` + registry + `UsageCalculator` + `ResponseNormalizer` + `TokenCounter`.
- **Non-JSON vendors are now supported.** `AbstractProviderClient::dispatch()` lets any binary or
  streaming route inherit the shared timeout/network taxonomy — the groundwork a video provider (which
  also returns binary, and asynchronously) will need.
- **Generated media has one representation** — a data URI on the modality response — across image and
  voice.
- **Non-token metering is solved**: characters → billable units → the shared pricing arithmetic, with
  the convention documented.
- `ConfigNormalizer` now covers lists, string maps, `name:value` maps, non-empty strings and positive
  integers.
- The registration and test recipes are unchanged and proven.

**Open items a future sprint may pick up (none blocking):**

1. Storage-disk offload for generated audio, replacing the data URI with a path (no contract change
   required).
2. Duration via the vendor's `/with-timestamps` route.
3. Streaming, which needs a foundation-level contract decision — it affects all five providers and must
   not be bolted onto one.
4. `Http::preventStrayRequests()` in the four older provider suites.
5. The ElevenLabs routes explicitly excluded here — cloning, dubbing, sound effects, speech-to-text,
   conversational AI — each of which needs its own request/response contract discussion first.

**Foundation state:** stable. Five providers across four sprints have been added without a single change
to `AIProviderInterface`, `ProviderFactory`, `ProviderRegistry`, `ProviderManager`, `HealthManager` or
any DTO, request or response. The one shared-class change this sprint was an extraction that removed a
reason to duplicate, not a redesign.

---

**Sprint 5.3.6 complete. Stopping here as instructed — Sprint 5.3.7 not started.**
