# Sprint 5.3.2 — OpenAI Provider Report

**Project:** HN9 AI Studio  
**Sprint:** 5.3.2 — OpenAI Provider  
**Date:** 2026-07-28

## Architecture Summary

Sprint 5.3.2 adds the first concrete adapter to the existing `App\AI` provider foundation. The
adapter is registered with `ProviderRegistry` by `AIServiceProvider`; `ProviderManager` and
`ProviderFactory` remain provider-agnostic and unchanged. The adapter uses Laravel's injected HTTP
factory and all runtime settings originate in `config/ai.php` / environment variables.

## Files Created

- `app/AI/Providers/OpenAI/OpenAIProvider.php`
- `app/AI/Providers/OpenAI/OpenAIConfig.php`
- `app/AI/Providers/OpenAI/OpenAIClient.php`
- `app/AI/Providers/OpenAI/OpenAIModelRegistry.php`
- `app/AI/Providers/OpenAI/OpenAIUsageCalculator.php`
- `app/AI/Providers/OpenAI/OpenAIResponseNormalizer.php`
- `app/AI/Providers/OpenAI/OpenAITokenCounter.php`
- `app/AI/Exceptions/ProviderAuthenticationException.php`
- `app/AI/Exceptions/ProviderRateLimitException.php`
- `app/AI/Exceptions/ProviderTimeoutException.php`
- `app/AI/Exceptions/ProviderNetworkException.php`
- `app/AI/Exceptions/ProviderApiException.php`
- `tests/Feature/OpenAIProviderTest.php`

## Files Modified

- `config/ai.php` — OpenAI environment-backed configuration.
- `app/Providers/AIServiceProvider.php` — registry-based OpenAI registration when enabled.
- `app/AI/DTOs/ProviderConfigDTO.php` — retains provider-specific config values in its existing
  options bag.
- `app/AI/Responses/UsageResponse.php` — carries optional execution time in the existing usage
  response.

## OpenAI Components

`OpenAIProvider` supports text and image generation, and explicitly rejects video and voice through
the existing `UnsupportedCapabilityException`. It uses the Responses API for text and Images API for
image generation. `OpenAIResponseNormalizer` maps both Responses API and Chat Completions-shaped
text payloads into the project’s provider-independent `TextResponse`, `ImageResponse`,
`UsageResponse`, and `ProviderResponseDTO` envelope.

## Configuration and Supported Models

All settings are read exclusively from `config/ai.php` and its `OPENAI_*` environment variables:
`OPENAI_ENABLED`, `OPENAI_API_KEY`, `OPENAI_BASE_URL`, `OPENAI_DEFAULT_MODEL`, `OPENAI_MODELS`,
`OPENAI_TIMEOUT`, `OPENAI_MAX_RETRIES`, streaming and function-calling flags. Models are a
configuration list; no provider model identifier is hardcoded. Pricing is a configuration map of
per-million input/output token rates.

## Health, Usage, Cost, and Tokens

Health probes `GET /models/{configured-default-model}` and returns a `ProviderHealthDTO`, including
latency. Authentication, rate-limit, timeout, network, API, and configuration failures become typed
AI exceptions. Usage records prompt/input, completion/output, total tokens, model, estimated USD
cost, and execution milliseconds. Preflight token counting is a documented local four-character
estimate; OpenAI response usage is authoritative after generation.

## Dependency Graph

```text
ProviderRegistry → OpenAIProvider
                     ├─ OpenAIClient → Laravel HTTP Factory
                     ├─ OpenAIModelRegistry → OpenAIConfig
                     ├─ OpenAIUsageCalculator → OpenAIConfig
                     ├─ OpenAIResponseNormalizer → UsageCalculator
                     └─ OpenAITokenCounter
```

## Verification

`OpenAIProviderTest` covers configuration loading, registry registration, text and image
generation normalization, health checks, token counting, cost estimation, and authentication/rate
limit mapping with Laravel HTTP fakes. No live API call or API key is used by tests.

| Gate | Result |
| --- | --- |
| `composer validate --strict` | Passed |
| Pint | Passed after formatting changed PHP files |
| PHPStan | Passed with a 512 MB analysis memory limit |
| PHPUnit | 50 tests passed / 163 assertions |

The repository has no `.env` file, so Laravel reports non-failing `file_get_contents(.env)` warnings
during feature tests. Verification supplied an in-process `APP_KEY`; no local secret or API key was
created. The local PHP runtime used for verification also required its SQLite extensions to be
enabled; that runtime is ignored under `.tools`.

## Known Risks

- Token counting is intentionally an estimate until a model-specific tokenizer is introduced.
- Pricing must be supplied and maintained in configuration; absent model pricing produces a zero
  estimate rather than guessing a price.
- Streaming capability is advertised from configuration, while streamed response consumption is not
  exposed through the current synchronous interface.

## Preparation for Sprint 5.3.3

The registry and adapter are extensible without changes to `ProviderManager`. Sprint 5.3.3 can add a
new provider as a separate adapter while preserving this provider-independent contract.
