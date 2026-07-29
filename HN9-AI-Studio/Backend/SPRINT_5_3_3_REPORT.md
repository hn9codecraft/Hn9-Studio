# Sprint 5.3.3 — Claude Provider Report

## Architecture Summary

Claude is a text-only concrete adapter on the unchanged AI provider foundation. It is registered
through `ProviderRegistry`; manager and factory remain provider-independent.

## Files Created

`ClaudeProvider`, `ClaudeClient`, `ClaudeConfig`, `ClaudeModelRegistry`, `ClaudeUsageCalculator`,
`ClaudeResponseNormalizer`, `ClaudeTokenCounter`, `ClaudeProviderTest`, and this report.

## Files Modified

`config/ai.php` gains environment-backed Claude settings. `AIServiceProvider` registers Claude only
when enabled, alongside the existing OpenAI registration.

## Components and Shared Audit

The existing generic exceptions, request/response DTOs, usage response, registry, and provider
contract are reused. No cross-provider extraction was needed: HTTP headers, response shapes, health
probes, and payloads are provider-specific.

## Configuration and Models

`CLAUDE_API_KEY`, `CLAUDE_BASE_URL`, `CLAUDE_API_VERSION`, timeout, retry, default model, model list,
capability flags, and pricing are all configuration/environment values. No model identifier is
hardcoded. Models are dynamically supplied through `CLAUDE_MODELS`.

## Capability, Health, and Accounting

Text generation uses the configured Messages endpoint. Image, video, and voice throw the existing
typed unsupported-capability exception. Health sends a minimal configured-model Messages request,
validating connectivity, authentication, model acceptance, and provider status. Responses normalize
text, model, input/output/total tokens, estimated USD cost, and execution milliseconds. Preflight
token counts use the existing documented local four-character estimate.

## Dependency Graph

```text
ProviderRegistry → ClaudeProvider → ClaudeClient → Laravel HTTP Factory
                                  → ModelRegistry / UsageCalculator / ResponseNormalizer / TokenCounter
```

## Verification

`ClaudeProviderTest` covers registration, configuration, text response normalization, health, token
counting, cost estimation, and authentication mapping. It also exercises the unchanged registry and
factory contract.

| Gate | Result |
| --- | --- |
| Composer validation | Passed |
| Pint | Passed |
| PHPStan | Passed with a 512 MB analysis memory limit |
| PHPUnit | 54 tests passed / 171 assertions |
| OpenAI regression | Passed as part of the full PHPUnit suite |

The repository does not include `.env`, so Laravel produces non-failing `.env` read warnings during
feature tests. Verification used a process-only application key and no provider credential.

## Known Risks

Token counts are estimates before execution; pricing is intentionally configuration-managed. The
health probe consumes a minimal API request. Streaming is capability-configured; the current shared
interface remains synchronous.

## Preparation for Sprint 5.3.4

Another provider can follow the same registry registration pattern without changes to the manager,
factory, or existing OpenAI/Claude adapters.
