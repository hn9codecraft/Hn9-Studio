# Sprint 5.3.1 — AI Provider Foundation Report

**Project:** HN9 AI Studio
**Sprint:** 5.3.1 — AI Provider Foundation (infrastructure/abstraction only)
**Date:** 2026-07-27
**Stack:** Laravel 12 · PHP 8.3.30 · Larastan (PHPStan level 5) · Pint (PSR-12) · PHPUnit 11
**Builds on:** Sprint 5.1 (Backend Foundation) + Sprint 5.2 (Domain Layer) — both final, unchanged in design.

---

## 1. Architecture Summary

Sprint 5.3.1 introduces a new, self-contained **AI provider abstraction** under the `App\AI`
namespace. It defines the contracts, factory, manager, registry, request/response objects, DTOs,
exceptions and health harness that concrete providers will plug into — **without implementing a
single provider or making any API call.**

The design is a classic three-part resolution pipeline behind one façade:

```
                         ┌─────────────────────────────┐
   consumer ───────────▶ │      ProviderManager        │  (Manager pattern, façade)
                         │  resolve · validate · caps  │
                         └───────┬───────────┬─────────┘
                                 │           │
                 ┌───────────────▼──┐   ┌────▼───────────────┐
                 │ ProviderFactory  │   │   HealthManager    │
                 │ (Factory pattern)│   │ (aggregate probes) │
                 └───────┬──────────┘   └────┬───────────────┘
                         │  reads bindings    │
                 ┌───────▼────────────────────▼───────┐
                 │        ProviderRegistry             │  (Registry pattern, singleton)
                 │  key → build-closure + capabilities │
                 └─────────────────────────────────────┘
                                 ▲
              register(key, Closure(ProviderConfigDTO): AIProviderInterface, caps, priority)
```

**Key properties**

- **Open/Closed.** No provider is hardcoded anywhere. New providers are added purely by calling
  `ProviderRegistry::register(...)` with a build closure — the factory, manager and registry never
  change. `AbstractProvider` lets a concrete provider override only the modalities it serves.
- **SOLID / DIP.** Every collaborator depends on an interface (`ProviderManagerInterface`,
  `ProviderFactoryInterface`, `ProviderRegistryInterface`, `HealthManagerInterface`). All are bound
  in `AIServiceProvider`.
- **Interface Segregation.** `ProviderHealthInterface` (health probe) is separated from the full
  `AIProviderInterface` generation surface; `ProviderRequestInterface` / `ProviderResponseInterface`
  give uniform handling across modalities.
- **Immutability.** Every DTO, request and response object is `final readonly` with strict typing.
- **No static logic.** All behaviour lives in injected instances; the only statics are DTO named
  constructors (`fromArray`, `success`, …).
- **Integrates, never duplicates.** AI exceptions extend the Sprint 5.2 `App\Exceptions\DomainException`
  (so the existing API error envelope renders them), and the enums reuse the 5.2
  `App\Enums\Concerns\InteractsWithEnum` helper. The runtime `App\AI\...\ProviderRegistryInterface`
  is deliberately distinct from the Sprint 5.2 database read-model
  `App\Contracts\Providers\ProviderRegistryInterface`; §12 explains the boundary.

**Scope discipline:** No OpenAI/Claude/Gemini/OpenRouter/ElevenLabs/Veo code, no API calls, no
generation, no mock providers, no fabricated responses. `AbstractProvider` is abstract scaffolding
that throws `UnsupportedCapabilityException` for unimplemented modalities and reports `unknown`
health; `countTokens()` uses a transparent, documented local heuristic (~4 chars/token) — not a
provider call.

---

## 2. Files Created

**40 PHP classes/enums/interfaces under `app/AI/`**, plus `config/ai.php`, `AIServiceProvider`, and
2 test files.

### Contracts — `app/AI/Contracts/` (8)
`AIProviderInterface`, `ProviderHealthInterface`, `ProviderManagerInterface`,
`ProviderFactoryInterface`, `ProviderRegistryInterface`, `HealthManagerInterface`,
`ProviderRequestInterface`, `ProviderResponseInterface`.

### DTOs — `app/AI/DTOs/` (5)
`ProviderRequestDTO`, `ProviderResponseDTO`, `ProviderHealthDTO`, `ProviderCapabilityDTO`,
`ProviderConfigDTO`.

### Requests — `app/AI/Requests/` (4)
`TextRequest`, `ImageRequest`, `VideoRequest`, `VoiceRequest`.

### Responses — `app/AI/Responses/` (7)
`TextResponse`, `ImageResponse`, `VideoResponse`, `VoiceResponse`, `ErrorResponse`,
`UsageResponse`, `TokenResponse`.

### Factory / Manager / Registry
`Factory/ProviderFactory`, `Manager/ProviderManager`, `Registry/ProviderRegistry`,
`Registry/ProviderRegistration`.

### Providers — `app/AI/Providers/` (1)
`AbstractProvider` (abstract Open/Closed scaffold — no implementation, no API).

### Health — `app/AI/Health/` (2)
`HealthManager`, `CapabilityReport`.

### Exceptions — `app/AI/Exceptions/` (5)
`AIException` (base, extends `DomainException`), `ProviderNotRegisteredException`,
`ProviderDisabledException`, `ProviderNotConfiguredException`, `UnsupportedCapabilityException`.

### Support — `app/AI/Support/` (4)
`Capability`, `Modality`, `HealthStatus` (enums), `ProviderConfigResolver`.

### Configuration & wiring
`config/ai.php`, `app/Providers/AIServiceProvider.php`.

### Tests
`tests/Unit/AIFoundationTest.php`, `tests/Feature/AIProviderSubsystemTest.php`.

---

## 3. Files Modified

| File | Change |
|------|--------|
| `bootstrap/providers.php` | Registered `AIServiceProvider` (after `DomainServiceProvider`). |

No Sprint 5.1/5.2 files were otherwise modified. Nothing was redesigned or duplicated.

---

## 4. Contracts

| Contract | Role |
|----------|------|
| `AIProviderInterface` | The provider surface: `generateText/Image/Video/Voice`, `estimateCost`, `countTokens`, `supportsStreaming`, `supportsFunctionCalling`, `supportedModels`, `providerName`, `providerVersion` (+ `healthCheck` via `ProviderHealthInterface`). **Definition only.** |
| `ProviderHealthInterface` | Segregated `healthCheck(): ProviderHealthDTO`. |
| `ProviderManagerInterface` | Façade: resolve/validate/discover/capabilities/health. |
| `ProviderFactoryInterface` | `make(key)`, `makeDefault()`. |
| `ProviderRegistryInterface` | register / enable / disable / default / `forCapability`. |
| `HealthManagerInterface` | `check(key)`, `aggregate()`. |
| `ProviderRequestInterface` / `ProviderResponseInterface` | Uniform `modality()` + `toArray()` across modalities. |

---

## 5. Factory

`ProviderFactory` (implements `ProviderFactoryInterface`) resolves a provider entirely from data:
it looks up the registered build closure via the registry, resolves the provider's
`ProviderConfigDTO` through `ProviderConfigResolver`, and invokes the closure. It guards disabled
providers (`ProviderDisabledException`) and a missing default (`ProviderNotConfiguredException`).
**Zero hardcoded provider references** — the Open/Closed principle in practice.

## 6. Manager

`ProviderManager` (implements `ProviderManagerInterface`) is the single public entry point. It
coordinates the registry, factory and health manager to: resolve a provider (`provider`,
`default`), validate registration/enabled state (`validate`, `has`), discover availability
(`available`), look up capabilities (`capabilities`, `forCapability`) and aggregate health
(`health`, `healthOf`). It contains no provider-specific logic.

## 7. Registry

`ProviderRegistry` (implements `ProviderRegistryInterface`, bound as a **singleton** so
registrations persist for the request/worker lifetime) stores `ProviderRegistration` entries binding
a key → build closure + `ProviderCapabilityDTO` + priority + enabled flag. Supports enable/disable,
default selection, and priority-ordered capability routing (`forCapability`). No external calls.

---

## 8. DTOs

All `final readonly`, strongly typed:

| DTO | Purpose |
|-----|---------|
| `ProviderRequestDTO` | Provider-agnostic request envelope (modality/model/parameters); `fromRequest()`. |
| `ProviderResponseDTO` | Provider-agnostic response envelope (success/usage/error); `success()`/`failure()`. |
| `ProviderHealthDTO` | Health outcome (status/latency/message/checkedAt); `healthy()`/`unavailable()`/`unknown()`. |
| `ProviderCapabilityDTO` | Declared capabilities + models + token limits; `supports()`. |
| `ProviderConfigDTO` | Resolved config (baseUrl/defaultModel/timeout/retries/options); `fromArray()`. |

## 9. Request Objects

`TextRequest`, `ImageRequest`, `VideoRequest`, `VoiceRequest` — immutable typed requests, each
implementing `ProviderRequestInterface` (`modality()`, `model()`, `toArray()`).

## 10. Response Objects

`TextResponse`, `ImageResponse`, `VideoResponse`, `VoiceResponse` (implement
`ProviderResponseInterface`), plus `ErrorResponse`, `UsageResponse` (token/cost accounting) and
`TokenResponse` (token-count result). Media responses carry references (URLs/paths), never binary
data.

## 11. Health System

- **Contract:** `ProviderHealthInterface` (provider self-report).
- **Manager:** `HealthManager` resolves each enabled provider via the factory, times and invokes its
  probe, and isolates failures (a throwing/unhealthy provider yields an `unavailable` DTO rather than
  breaking the aggregate). With no providers registered this sprint, `aggregate()` is empty.
- **Status DTO:** `ProviderHealthDTO` + `HealthStatus` enum (`healthy`/`degraded`/`unavailable`/`unknown`).
- **Capability Report:** `CapabilityReport` introspects the registry into a `providers()` map and a
  capability→providers `matrix()`.

---

## 12. Dependency Graph (DI Bindings)

Registered in `App\Providers\AIServiceProvider`:

```
ProviderRegistryInterface  → ProviderRegistry        (singleton)
ProviderFactoryInterface   → ProviderFactory         (← ProviderRegistryInterface, ProviderConfigResolver)
HealthManagerInterface     → HealthManager           (← ProviderRegistryInterface, ProviderFactoryInterface)
ProviderManagerInterface   → ProviderManager         (← ProviderRegistryInterface, ProviderFactoryInterface, HealthManagerInterface)

ProviderConfigResolver     ← Illuminate\Contracts\Config\Repository   (autowired)
```

**Boundary with Sprint 5.2.** The 5.2 `App\Contracts\Providers\ProviderRegistryInterface` is a
**database read model** (the `ai_providers` table catalogue). The new
`App\AI\Contracts\ProviderRegistryInterface` is the **in-memory runtime registry** of provider
client bindings. They are complementary and intentionally separate; bridging the persisted catalogue
to runtime registration (and DB-backed `ProviderConfigDTO` from `provider_settings`) is a 5.3.2 task.

---

## 13. Verification

| Gate | Command | Result |
|------|---------|--------|
| Composer manifest | `composer validate --strict` | ✅ `./composer.json is valid` |
| Code style | `pint --test` | ✅ PSR-12 `passed` |
| Static analysis | `phpstan analyse` (level 5, Larastan) | ✅ **No errors** |
| Tests | `php artisan test` | ✅ **42 passed / 146 assertions** |

The 29 prior tests (5.1 + 5.2) still pass unchanged (no regressions); 13 new tests were added
(7 unit covering DTOs/enums/requests/responses, 6 feature covering the registry → factory → manager
→ health pipeline resolved through the container).

**Architecture consistency**
- No `App\AI` class references OpenAI/Claude/Gemini/OpenRouter/ElevenLabs/Veo or any HTTP client.
- No provider is registered or instantiated by application code; the registry ships empty.
- No `@phpstan-ignore`, baseline, inline `@var`, silencing cast or `assert()` was used to reach green.
- AI exceptions extend the 5.2 `DomainException`, so they render through the existing JSON envelope.

---

## 14. Preparation for Sprint 5.3.2

The foundation is ready for the first concrete provider:

1. **Implement a provider** by extending `AbstractProvider` (override only supported modalities) or
   implementing `AIProviderInterface` directly.
2. **Register it** in a provider package's boot (or in `AIServiceProvider::boot()`):
   `$registry->register('openai', fn (ProviderConfigDTO $c) => new OpenAiProvider($httpClient, $c), $capabilities, priority: 100)`.
3. **Configure it** by adding an `ai.providers.openai` block in `config/ai.php` (base URL, default
   model, timeouts) and setting `AI_DEFAULT_PROVIDER`. Credentials come from `provider_settings`
   (5.2) via a DB-backed config source wired in 5.3.2.
4. **Bridge the catalogue:** feed the 5.2 `ai_providers` records into the runtime registry so
   enable/disable/priority are database-driven.
5. **Real health probes** replace `AbstractProvider`'s `unknown` default; `estimateCost()` and
   `countTokens()` gain provider-accurate implementations.

No consumer of `ProviderManagerInterface` will need to change when providers land — that is the
whole point of the abstraction.

---

## Stop Condition

Sprint 5.3.1 is **complete**. All verification gates pass (Composer, Pint, PHPStan level 5,
42 tests). No provider implementations, API calls, generation, or mock/fake responses were created.
**Sprint 5.3.2 has not been started — awaiting the next instruction.**
