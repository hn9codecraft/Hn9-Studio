# Sprint 5.3.4 — Gemini Provider Report

## Architecture Summary

Gemini is a third concrete adapter on the unchanged Sprint 5.3.1 foundation. It implements
`AIProviderInterface` through `AbstractProvider`, is discovered exclusively through
`ProviderRegistry`, and is built lazily by `ProviderFactory` from a `ProviderConfigDTO`. No contract
was modified, and neither `ProviderManager`, `ProviderFactory`, `ProviderRegistry` nor `HealthManager`
gained provider-specific knowledge — Gemini is registered from `AIServiceProvider` only.

Text and image generation both travel through the vendor's `models/{model}:generateContent` route,
which is why image-capable models are configured as their own list; the `image` capability is declared
only when that list is non-empty. Video (Veo) and speech remain unimplemented and raise the shared
`UnsupportedCapabilityException`.

With three providers now in the tree, the genuinely duplicated mechanics were lifted into four shared
base classes (HTTP transport, model resolution, usage pricing, local token estimate). OpenAI and
Claude were re-based onto them without any behavioural or public-API change, verified by their
existing tests passing untouched.

## Files Created

| File | Lines | Role |
| --- | --- | --- |
| `app/AI/Http/AbstractProviderClient.php` | 167 | Shared HTTP transport and typed error mapping |
| `app/AI/Support/AbstractModelRegistry.php` | 55 | Shared configured-model list and resolution |
| `app/AI/Support/AbstractUsageCalculator.php` | 53 | Shared per-million-token cost arithmetic |
| `app/AI/Support/AbstractTokenCounter.php` | 29 | Shared local character-ratio token estimate |
| `app/AI/Providers/Gemini/GeminiProvider.php` | 269 | Capability implementation and payload construction |
| `app/AI/Providers/Gemini/GeminiClient.php` | 90 | Gemini routes, API-key header, error taxonomy |
| `app/AI/Providers/Gemini/GeminiConfig.php` | 95 | Validated settings resolved from configuration |
| `app/AI/Providers/Gemini/GeminiModelRegistry.php` | 51 | Text and image model resolution |
| `app/AI/Providers/Gemini/GeminiResponseNormalizer.php` | 168 | Vendor payload → shared responses |
| `app/AI/Providers/Gemini/GeminiUsageCalculator.php` | 34 | `usageMetadata` → `UsageResponse` |
| `app/AI/Providers/Gemini/GeminiTokenCounter.php` | 44 | Vendor tokenizer with local fallback |
| `tests/Feature/GeminiProviderTest.php` | 454 | 28 tests covering the sprint's test matrix |
| `SPRINT_5_3_4_REPORT.md` | — | This report |

## Files Modified

| File | Change |
| --- | --- |
| `config/ai.php` | Adds the environment-backed `gemini` block; refreshes the stale "intentionally empty" section comment |
| `app/Providers/AIServiceProvider.php` | Registers Gemini; the OpenAI block moved into a private method so all three registrations are symmetric; shared `settingsFor()` / `registry()` helpers |
| `app/AI/Providers/AbstractProvider.php` | Adds `protected elapsedMilliseconds()` (the timing helper both existing providers had duplicated) |
| `app/AI/Providers/OpenAI/OpenAIClient.php` | Now extends `AbstractProviderClient`; public `post()`/`get()` unchanged |
| `app/AI/Providers/OpenAI/OpenAIModelRegistry.php` | Now extends `AbstractModelRegistry` |
| `app/AI/Providers/OpenAI/OpenAIUsageCalculator.php` | Now extends `AbstractUsageCalculator` |
| `app/AI/Providers/OpenAI/OpenAITokenCounter.php` | Now extends `AbstractTokenCounter` |
| `app/AI/Providers/OpenAI/OpenAIProvider.php` | Private `elapsedMilliseconds()` removed in favour of the inherited helper |
| `app/AI/Providers/Claude/ClaudeClient.php` | Now extends `AbstractProviderClient`; public `messages()` unchanged |
| `app/AI/Providers/Claude/ClaudeModelRegistry.php` | Now extends `AbstractModelRegistry` |
| `app/AI/Providers/Claude/ClaudeUsageCalculator.php` | Now extends `AbstractUsageCalculator` |
| `app/AI/Providers/Claude/ClaudeTokenCounter.php` | Now extends `AbstractTokenCounter` |
| `app/AI/Providers/Claude/ClaudeProvider.php` | Private `elapsed()` removed in favour of the inherited helper |

No file under `app/AI/Contracts`, `app/AI/DTOs`, `app/AI/Requests`, `app/AI/Responses`,
`app/AI/Factory`, `app/AI/Manager`, `app/AI/Registry` or `app/AI/Health` was touched.

## Gemini Components

| Component | Responsibility |
| --- | --- |
| `GeminiProvider` | Implements every interface method; builds `contents`, `systemInstruction`, `generationConfig` and `tools`; owns the health probe |
| `GeminiClient` | `:generateContent`, `:countTokens` and model-metadata routes; `x-goog-api-key` header; recognises Google's credential errors reported as HTTP 400 |
| `GeminiConfig` | Validates and exposes credentials, endpoint, API version, timeout, retries, model lists, capability flags and pricing; composes the versioned `endpoint()` |
| `GeminiModelRegistry` | Resolves text models via the shared base and image models against the separate image allow-list |
| `GeminiUsageCalculator` | Maps `usageMetadata`, counting `thoughtsTokenCount` as completion tokens (Gemini bills thinking at the output rate) |
| `GeminiResponseNormalizer` | Concatenates text parts, converts `inlineData` to data URIs, reads `totalTokens`, and raises typed parsing failures |
| `GeminiTokenCounter` | Uses the vendor tokenizer endpoint when enabled, degrading to the shared local estimate |

## Shared Components

Extracted only where all three providers were doing the same thing:

| Shared class | Removed duplication |
| --- | --- |
| `AbstractProviderClient` | Base-URL/timeout/retry wiring, JSON decode, and the mapping of connection, timeout, auth, rate-limit and API failures — previously written twice, now once. Provider-specific seams: `headers()`, `failureFor()`, `isAuthenticationFailure()`, `errorMessage()` |
| `AbstractModelRegistry` | Identical `all()` / `resolve()` logic in the OpenAI and Claude registries; `resolveFrom()` lets Gemini reuse it for a second (image) allow-list |
| `AbstractUsageCalculator` | The per-million-token pricing formula, previously repeated per provider |
| `AbstractTokenCounter` | The character-ratio preflight estimate, previously copied verbatim |
| `AbstractProvider::elapsedMilliseconds()` | The `hrtime()` → milliseconds conversion present in both existing providers |

Deliberately **not** extracted, because the behaviour is genuinely per-vendor: request payload shape,
response shape, authentication scheme, health probe, error taxonomy, and the typed settings objects
(each validates a different set of required keys). `AbstractProvider::countTokens()` keeps its own
inline default because the foundation must produce an estimate without any injected collaborator.

Open/Closed is preserved: adding Gemini required no edit to any shared class other than the additive
extraction above, and every seam is an overridable protected method rather than a conditional.

## Configuration

Read exclusively from `config/ai.php`, which is entirely environment-backed. No credential, endpoint,
version, model identifier or price appears in any adapter class.

| Environment variable | Purpose | Default |
| --- | --- | --- |
| `GEMINI_ENABLED` | Registers the provider when true | `false` |
| `GEMINI_API_KEY` | API key (`x-goog-api-key`) | — |
| `GEMINI_BASE_URL` | API host | `https://generativelanguage.googleapis.com` |
| `GEMINI_API_VERSION` | API version segment | `v1beta` |
| `GEMINI_DEFAULT_MODEL` | Default text model | — |
| `GEMINI_TIMEOUT` | Request timeout (seconds) | `30` |
| `GEMINI_MAX_RETRIES` | Transport retry attempts | `2` |
| `GEMINI_MODELS` | Comma-separated text models | empty |
| `GEMINI_IMAGE_MODELS` | Comma-separated image-capable models | empty |
| `GEMINI_IMAGE_DEFAULT_MODEL` | Default image model | — |
| `GEMINI_IMAGE_RESPONSE_MODALITIES` | `generationConfig.responseModalities` for image calls | `IMAGE` |
| `GEMINI_REMOTE_TOKEN_COUNTING` | Use the vendor tokenizer endpoint | `true` |
| `GEMINI_SUPPORTS_STREAMING` | Declared streaming capability | `true` |
| `GEMINI_SUPPORTS_FUNCTION_CALLING` | Declared function-calling capability | `true` |
| `GEMINI_PRIORITY` | Capability-routing priority | `80` |

`pricing` is a per-model map of USD prices per million tokens (`input` / `output`), supplied by
deployment configuration exactly as for OpenAI and Claude. An unpriced model yields a zero estimate
rather than a guess. Missing API key, base URL or version raises `ProviderNotConfiguredException`
at construction.

## Supported Models

`supportedModels()` returns the union of `GEMINI_MODELS` and `GEMINI_IMAGE_MODELS`, de-duplicated and
in configuration order. Not one model identifier is hardcoded, so Gemini 2.5 Pro, Gemini 2.5 Flash
and any future model are adopted by editing the environment alone. A requested model outside the
configured list is rejected with `ProviderNotConfiguredException` instead of being forwarded to the
vendor; image requests are validated against the image list specifically.

## Health Check

`GET /{version}/models/{model}` on the configured default model — a read-only metadata call, so the
probe bills no generation (unlike a minimal completion request). It validates connectivity,
authentication, API version and the existence of the configured default model in one round trip, and
returns a `ProviderHealthDTO`:

- healthy → latency in milliseconds, ISO-8601 timestamp, and details carrying `api_version`,
  `default_model`, `model_verified`, `text_models`, `image_models`
- any failure → `ProviderHealthDTO::unavailable()` carrying the typed exception's message

`HealthManager::check()` and `aggregate()` consume it unchanged.

## Usage Tracking

`GeminiUsageCalculator` maps `usageMetadata` into the existing `UsageResponse`, so every call reports
prompt tokens, completion tokens, total tokens, estimated cost, currency, model used and execution
time in milliseconds. `totalTokenCount` is treated as authoritative when the vendor supplies it;
`thoughtsTokenCount` is added to completion tokens because Gemini bills thinking tokens at the output
rate. Execution time is measured by the provider around the HTTP call using the shared
`elapsedMilliseconds()` helper.

## Cost Estimation

`estimateCost(ProviderRequestDTO)` resolves the model by modality (image requests against the image
list), counts the prompt tokens, treats `max_tokens` as the output figure, and prices both with the
configured per-million rates — matching the OpenAI and Claude semantics exactly.

## Token Counting

Gemini is the first provider in the tree with a real tokenizer endpoint
(`models/{model}:countTokens`), so `countTokens()` returns exact vendor counts when
`GEMINI_REMOTE_TOKEN_COUNTING` is enabled. Because counting is a preflight concern, a typed provider
failure degrades to the shared character-ratio estimate rather than failing a request that has not
been made; setting the flag to false keeps counting entirely offline and issues no HTTP call at all.
Both paths are covered by tests.

## Dependency Graph

```text
AIServiceProvider ──registers──> ProviderRegistry
                                      │
ProviderManager ──> ProviderFactory ──┴──> GeminiProvider (AbstractProvider → AIProviderInterface)
       │                                        ├──> GeminiClient ──────> AbstractProviderClient ──> Illuminate\Http\Client\Factory
       │                                        ├──> GeminiModelRegistry ──> AbstractModelRegistry
       │                                        ├──> GeminiUsageCalculator ─> AbstractUsageCalculator
       │                                        ├──> GeminiResponseNormalizer ──> GeminiUsageCalculator
       │                                        ├──> GeminiTokenCounter ────> AbstractTokenCounter
       │                                        └──> GeminiConfig <── ProviderConfigDTO <── ProviderConfigResolver <── config/ai.php
       └──> HealthManager ──> ProviderFactory ──> AIProviderInterface::healthCheck()

OpenAIClient, ClaudeClient  ──> AbstractProviderClient          (shared transport)
OpenAI/Claude registries    ──> AbstractModelRegistry           (shared resolution)
OpenAI/Claude calculators   ──> AbstractUsageCalculator         (shared pricing)
OpenAI/Claude counters      ──> AbstractTokenCounter            (shared estimate)
```

All dependencies are constructor-injected; nothing is resolved from the container inside the adapter,
and there are no static helper classes.

## Verification

Environment note: the repository ships no `.env` and PHP was not on `PATH`. Verification used the
Laragon PHP 8.3.30 runtime, a freshly installed `vendor/`, and a process-only `APP_KEY` (no provider
credential and no real API call — every test uses `Http::fake`).

| Gate | Command | Result |
| --- | --- | --- |
| Composer | `composer validate --no-check-publish` | `./composer.json is valid` |
| Pint | `pint --test` | `{"tool":"pint","result":"passed"}` |
| PHPStan | `phpstan analyse` (larastan, level 5, 512 MB) | `[OK] No errors` |
| PHPUnit | `phpunit` | **OK (82 tests, 242 assertions)** |

Test-count movement: 54 tests / 171 assertions before this sprint → 82 / 242 after (+28 tests,
+71 assertions), all from `GeminiProviderTest`.

Coverage against the sprint's test matrix:

| Required | Covered by |
| --- | --- |
| Provider registration | registry/factory/manager resolution, capability routing, priority ordering, image capability withheld when no image models are configured |
| Configuration loading | dynamic model lists (with whitespace trimming), endpoint composition, timeout/retry, missing key and missing version rejection |
| Text generation | payload mapping (roles, `systemInstruction` hoisting, `generationConfig`), multi-part concatenation, `modelVersion`, finish reason |
| Image generation | `inlineData` → data URI, `responseModalities`, non-image model rejected |
| Response normalization | text, image, token and usage normalization; thinking-token accounting; authoritative `totalTokenCount` |
| Health check | healthy probe with details and latency; unavailable path with vendor message |
| Token counting | vendor tokenizer, local-only mode (asserts zero HTTP calls), graceful degradation on tokenizer failure |
| Cost estimation | configured rates for text; image-model resolution; unpriced model → 0.0 |
| Typed exception mapping | 401, Google's 400 `INVALID_ARGUMENT` invalid-key, `PERMISSION_DENIED`, 429, 500 with vendor message, timeout, connection failure, unparseable payload, unsupported video/voice |
| Regression | OpenAI and Claude suites pass unmodified; a dedicated test boots all three providers together and asserts each still resolves and orders correctly |

## Regression Results

The 54 pre-existing tests pass without a single edit to any existing test file — the strongest
available evidence that the shared extraction preserved behaviour. The OpenAI `Authorization: Bearer`
scheme, the Claude `x-api-key`/`anthropic-version` headers, both providers' error mapping, payload
shapes and health probes are byte-for-byte equivalent on the wire.
`test_gemini_registers_alongside_openai_and_claude_without_disturbing_them` additionally boots all
three providers simultaneously and asserts each resolves through the factory and orders by configured
priority (OpenAI 100 → Claude 90 → Gemini 80).

## Known Risks

1. **Image generation path.** Only the `generateContent` image route is implemented (native Gemini
   image models returning `inlineData`). Imagen's `models/{model}:predict` route is a different
   payload contract and is out of scope; a configured Imagen model would fail with a typed API
   exception rather than silently misbehaving.
2. **Unmapped image request fields.** `generateContent` exposes no negative-prompt, size, quality,
   style or response-format parameter, so those `ImageRequest` fields are not transmitted. `count`
   maps to `candidateCount` and `seed` to `generationConfig.seed`. Vendor-specific extras can be
   passed through `ImageRequest::$options`.
3. **Streaming.** Gemini streams through a separate `:streamGenerateContent` route, and the shared
   provider contract is synchronous. `supportsStreaming()` reports the configured capability but the
   `TextRequest::$stream` flag does not alter the payload — deliberately, since sending it would not
   produce a stream.
4. **Remote token counting adds a network call.** With `GEMINI_REMOTE_TOKEN_COUNTING` enabled,
   `countTokens()` and therefore `estimateCost()` make an HTTP request, unlike OpenAI and Claude.
   Failures degrade to the local estimate, which means an estimate can be silently approximate; set
   the flag to false for fully offline accounting.
5. **Estimates remain estimates.** The local fallback is a documented ~4-characters-per-token
   heuristic, and `max_tokens` is an upper bound, so pre-flight cost is indicative. Post-execution
   `usageMetadata` is authoritative.
6. **Cached-content tokens.** `cachedContentTokenCount` is billed at a distinct rate and is not
   modelled by the shared two-rate pricing table; costs for cached-context calls will read high.
7. **Pricing is deployment-managed.** As with the existing providers, `pricing` ships empty, so cost
   is 0.0 until rates are configured.

## Preparation for Sprint 5.3.5

The shared bases now carry everything a fourth provider needs for free: transport, retry, typed error
mapping, model resolution, cost arithmetic and the local token estimate. A new adapter supplies only
its settings object, headers, routes, payload builder, normalizer and one registration method in
`AIServiceProvider` — no change to any contract, DTO, factory, manager, registry or health component.
`AbstractProviderClient` already exposes the seams a different error taxonomy needs
(`isAuthenticationFailure()`, `failureFor()`, `errorMessage()`), as Gemini's HTTP-400 credential
handling demonstrates. `AbstractModelRegistry::resolveFrom()` supports providers with several
per-modality model lists.

Not started, per the stop condition: OpenRouter, ElevenLabs, Veo, queue and workflow engine.
