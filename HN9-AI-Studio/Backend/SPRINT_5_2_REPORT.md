# Sprint 5.2 — Domain Layer Report

**Project:** HN9 AI Studio
**Sprint:** 5.2 — Domain Layer (business architecture only)
**Date:** 2026-07-27
**Stack:** Laravel 12 · PHP 8.3.30 · Larastan (PHPStan level 5) · Pint (PSR-12) · PHPUnit 11
**Builds on:** Sprint 5.1 — Backend Foundation (final, unmodified in design)

---

## 1. Architecture Summary

Sprint 5.2 layers a complete, SOLID **Domain Layer** on top of the Sprint 5.1 foundation
**without redesigning or duplicating** any existing model, migration or config. The existing 13
Eloquent models and their schema are the single source of persistence truth; every new layer sits
*around* them.

The dependency flow is strictly one-directional:

```
HTTP (Controllers, Form Requests, Resources)
        │  depends on
        ▼
Service Contracts  ──►  Services  (business rules, orchestration, auditing)
        │                    │  depends on
        │                    ▼
        │              Repository Contracts  ──►  Repositories  (the ONLY DB access)
        │                    │                          │
        ▼                    ▼                          ▼
      DTOs   ◄────────  Enums / Exceptions / Support  ◄────  Eloquent Models (5.1)
```

Guarantees enforced across the codebase:

- **Controllers call Services only** — `HealthController` now delegates to `HealthServiceInterface`.
- **Services contain the business rules** — slug uniqueness, status-transition guards, language
  validation, provider-registry rules, activity auditing.
- **Repositories are the only layer touching Eloquent/the database.**
- **DTOs transfer data only** — immutable `final readonly` classes, no behaviour, no I/O.
- **No business logic in models** — the new model traits expose *read* helpers and relationships
  only; all mutation/querying lives in services/repositories.
- **Everything is resolved through an interface** — bound centrally in `DomainServiceProvider`, so
  any implementation is swappable without touching consumers (Dependency Inversion).

**Scope discipline:** No AI providers, no OpenAI/Claude/Gemini, no generation, no prompt execution,
no queue/jobs/events, and no workflow engine were implemented. Where a service name implies
execution (Workflow/Agent/Prompt/Generation), it manages **domain records and their state only** —
this is documented in each class's docblock.

---

## 2. Files Created

> ~95 new PHP files. Grouped by layer below. Nothing from Sprint 5.1 was recreated.

### Enums — `app/Enums/` (8 + 1 concern)
`Status`, `ProviderType`, `ProjectStatus`, `WorkflowStatus`, `ExecutionStatus`, `AssetType`,
`MediaType`, `ContentType`, and `Concerns/InteractsWithEnum` (shared `values()/options()/isValid()`).

### Exceptions — `app/Exceptions/` (5)
`DomainException` (base: error code + HTTP status hint + context), `ProviderException`,
`ValidationException`, `WorkflowException`, `GenerationException`.

### Helpers / Support — `app/Support/` (4)
`Uuid`, `StorageHelper`, `ApiResponse`, `DomainHelper` (slug/locale/uniqueness — pure functions).

### DTOs — `app/DTOs/` (10 + 1 concern)
`Project/CreateProjectData`, `Project/UpdateProjectData`, `Generation/GenerationRequestData`,
`Asset/AssetData`, `Content/ContentData`, `Provider/ProviderData`, `Provider/ProviderSettingData`,
`Workflow/WorkflowRunData`, `Agent/AgentExecutionData`, `Prompt/PromptExecutionData`, and
`Concerns/ArrayableData` (null-dropping array projection).

### Repository Contracts — `app/Repositories/Contracts/` (11)
`RepositoryInterface` (generic base) + `Project`, `ProjectInput`, `Asset`, `Provider`,
`GeneratedContent`, `WorkflowRun`, `ActivityLog`, `MediaFile`, `AgentExecution`, `PromptExecution`.

### Repository Implementations — `app/Repositories/` (1 base + 10)
`BaseRepository` (generic CRUD/lookup) + one concrete repository per contract above.

### Cross-cutting Contracts — `app/Contracts/` (16)
- `Services/` (11): one interface per domain service.
- `Providers/` (2): `ProviderRegistryInterface` (read model), `ProviderDefinitionInterface`.
- `Execution/` (1): `ExecutionTrackerInterface`.
- `Storage/` (1): `StorageInterface`.
- `Logging/` (1): `ActivityLoggerInterface`.

### Services — `app/Services/` (10 domain + 3 infrastructure)
Domain: `ProjectService`, `AssetService`, `ContentService`, `GenerationRequestService`,
`ProviderRegistryService`, `WorkflowService`, `PromptService`, `AgentExecutionService`,
`HistoryService`, `HealthService`.
Infrastructure: `Logging/ActivityLogger`, `Storage/FilesystemStorage`, `Execution/ExecutionTracker`.

### Validation — `app/Http/Requests/` (4) + `app/Rules/` (2)
`StoreProjectRequest`, `UpdateProjectRequest`, `StoreAssetRequest`, `StoreProviderRequest`;
reusable rules `EnumValue` (validates against any backed enum) and `SupportedLocale`.

### Policies — `app/Policies/` (3 new)
`GeneratedAssetPolicy`, `WorkflowRunPolicy`, `GeneratedContentPolicy` (ownership pattern,
auto-discovered by the `{Model}Policy` convention).

### API Resources — `app/Http/Resources/` (5)
`ProjectResource`, `AssetResource`, `GeneratedContentResource`, `WorkflowResource`,
`ProviderResource` (expose public UUID, never internal ids/secrets).

### Model Traits — `app/Models/Concerns/` (4 new)
`HasCreator`, `HasStatus`, `LogsActivity`, `TracksExecution` (`HasUuid` from 5.1 reused).

### Providers — `app/Providers/` (1 new)
`DomainServiceProvider` — central DI binding map.

### Tests — `tests/` (5 new)
`Unit/EnumsTest`, `Unit/DataTransferObjectTest`, `Feature/RepositoryTest`,
`Feature/DomainServiceTest`, `Feature/ContainerBindingsTest`.

---

## 3. Files Modified

| File | Change |
|------|--------|
| `app/Models/Project.php` | + `HasCreator, HasStatus, LogsActivity` traits |
| `app/Models/WorkflowRun.php` | + `HasCreator, HasStatus, LogsActivity, TracksExecution`; added `@property` docblocks |
| `app/Models/GeneratedContent.php` | + `HasStatus, LogsActivity` |
| `app/Models/GeneratedAsset.php` | + `HasStatus, LogsActivity` |
| `app/Models/AgentExecution.php` | + `HasStatus, TracksExecution` |
| `app/Models/PromptExecution.php` | + `HasStatus` |
| `app/Models/AiProvider.php` | + `HasStatus` |
| `app/Models/PublishJob.php` | + `HasCreator, HasStatus` |
| `app/Http/Controllers/Api/V1/HealthController.php` | Now delegates to `HealthServiceInterface` (thin controller) |
| `bootstrap/providers.php` | Registered `DomainServiceProvider` |

> All model changes are **additive** (traits + docblocks). No fillable/casts/relationships or
> migrations were altered; Sprint 5.1 behaviour is preserved (verified by the untouched 5.1 tests).

---

## 4. Repositories

Ten aggregate repositories over a generic, statically-typed `BaseRepository<TModel>`:

| Repository | Model | Notable methods |
|------------|-------|-----------------|
| `ProjectRepository` | `Project` | `paginateForUser`, `slugExistsForUser` |
| `ProjectInputRepository` | `ProjectInput` | `forProject` (backs generation requests) |
| `AssetRepository` | `GeneratedAsset` | `forProject`, `forProjectOfType` |
| `ProviderRepository` | `AiProvider` | `findBySlug`, `active`, `activeByCategory`, `updateOrCreateSetting` |
| `GeneratedContentRepository` | `GeneratedContent` | `forProject`, `latestVersion` |
| `WorkflowRunRepository` | `WorkflowRun` | `forProject`, `withStatus` |
| `AgentExecutionRepository` | `AgentExecution` | `forWorkflowRun` |
| `PromptExecutionRepository` | `PromptExecution` | `forAgentExecution` |
| `MediaFileRepository` | `MediaFile` | `forOwner`, `findByChecksum` |
| `ActivityLogRepository` | `ActivityLog` | `forSubject`, `forUser` |

`BaseRepository` provides `all / paginate / find / findOrFail / findByUuid / findByUuidOrFail /
create / update / delete` plus a whitelist-based `applyFilters()`. `query()` is abstract and
implemented per repository (`Model::query()`) so the concrete model type flows through every
generic return (see Known Risks §12 for the one deliberate typing note).

---

## 5. Services

| Service | Responsibility (record-level only) |
|---------|-----------------------------------|
| `ProjectService` | Create/update/delete projects; unique-slug resolution; status-transition guard; audit. |
| `AssetService` | Record/list/delete generated media assets. |
| `ContentService` | Record/list/delete textual content; auto version assignment. |
| `GenerationRequestService` | Validate + **persist** a generation request as a `project_input` brief. **No generation runs.** |
| `ProviderRegistryService` | Register/update/delete providers; resolve active providers; upsert settings. |
| `WorkflowService` | Create workflow-run records; guard status transitions. **No engine.** |
| `AgentExecutionService` | Create/list agent-execution records. **No agent runs.** |
| `PromptService` | Record/list prompt-execution rows. **No prompt rendering/model calls.** |
| `HistoryService` | Query the audit trail by subject or user. |
| `HealthService` | Liveness probes + status envelope. |

Infrastructure services: `ActivityLogger` (single audit write-path), `FilesystemStorage` (logical
disk abstraction), `ExecutionTracker` (stamps status/timing on execution rows — does not run them).
Every service uses **constructor injection** and depends only on contracts; there is no static
business logic.

---

## 6. DTOs

All DTOs are `final readonly` (immutable, strongly typed, validation-ready). Each exposes
`fromArray()` (built from validated request input) and `toArray()`/`toFullArray()` via the
`ArrayableData` concern — `toArray()` drops nulls to give clean PATCH semantics. Enum-backed
defaults keep them consistent with the schema (e.g. `CreateProjectData::status` defaults to
`ProjectStatus::Draft`).

---

## 7. Contracts

| Group | Location | Members |
|-------|----------|---------|
| Repository interfaces | `app/Repositories/Contracts/` | 11 (generic base + per-aggregate) |
| Service interfaces | `app/Contracts/Services/` | 11 |
| Provider interfaces | `app/Contracts/Providers/` | `ProviderRegistryInterface`, `ProviderDefinitionInterface` |
| Execution interfaces | `app/Contracts/Execution/` | `ExecutionTrackerInterface` |
| Storage interfaces | `app/Contracts/Storage/` | `StorageInterface` |
| Logger interfaces | `app/Contracts/Logging/` | `ActivityLoggerInterface` |

Repository interfaces are grouped under `Repositories/Contracts/` (per the Sprint 5.2 structure);
all other contract families live under `app/Contracts/`.

---

## 8. Policies

`GeneratedAssetPolicy`, `WorkflowRunPolicy`, `GeneratedContentPolicy` follow the Sprint 5.1
`ProjectPolicy` reference pattern (owner-or-admin via a `before()` admin bypass; ownership resolved
through the owning project). They use the model-matching class names required for Laravel
auto-discovery — `AssetPolicy → GeneratedAssetPolicy`, `WorkflowPolicy → WorkflowRunPolicy` — which
keeps them zero-config while satisfying the requested coverage.

---

## 9. Resources, Enums, Traits, Exceptions, Validation

- **Resources:** 5 JSON resources, each `@mixin`-annotated for static analysis, exposing UUID +
  safe fields only.
- **Enums:** 8 backed string enums with domain behaviour (`ProjectStatus::canTransitionTo()`,
  `AssetType::disk()/mediaType()`, `MediaType::fromMimeType()`, `ExecutionStatus::isSuccessful()`, …).
- **Traits:** 4 additive model concerns (read helpers + relationships only).
- **Exceptions:** 5-class hierarchy under one catchable base (`DomainException`) carrying a machine
  error code, HTTP status hint and structured context.
- **Validation:** 4 Form Requests + 2 reusable Rules; allowed-value lists live once, in the enums.

---

## 10. Dependency Graph (DI Bindings)

Registered in `App\Providers\DomainServiceProvider` (`register()`):

```
Repositories (bind)                    Services (bind)
─────────────────────────────         ──────────────────────────────────────
ProjectRepositoryInterface            ProjectServiceInterface        → ProjectService
  → ProjectRepository                 AssetServiceInterface          → AssetService
ProjectInputRepositoryInterface       ContentServiceInterface        → ContentService
  → ProjectInputRepository            GenerationRequestServiceInterface → GenerationRequestService
AssetRepositoryInterface              WorkflowServiceInterface       → WorkflowService
  → AssetRepository                   AgentExecutionServiceInterface → AgentExecutionService
ProviderRepositoryInterface           PromptServiceInterface         → PromptService
  → ProviderRepository                HistoryServiceInterface        → HistoryService
GeneratedContentRepositoryInterface   HealthServiceInterface         → HealthService
  → GeneratedContentRepository        ProviderRegistryInterface        ┐
WorkflowRunRepositoryInterface        ProviderRegistryServiceInterface┴→ ProviderRegistryService (singleton)
  → WorkflowRunRepository
ActivityLogRepositoryInterface        Infrastructure (bind)
  → ActivityLogRepository             ──────────────────────────────────────
MediaFileRepositoryInterface          ActivityLoggerInterface   → ActivityLogger
  → MediaFileRepository               StorageInterface          → FilesystemStorage
AgentExecutionRepositoryInterface     ExecutionTrackerInterface → ExecutionTracker
  → AgentExecutionRepository
PromptExecutionRepositoryInterface
  → PromptExecutionRepository
```

Resolution example: `ProjectService ← ProjectRepositoryInterface + ActivityLoggerInterface ←
ActivityLogRepositoryInterface + Request`. All wired automatically by the container from the bindings
above (verified by `ContainerBindingsTest`).

---

## 11. Verification

| Gate | Command | Result |
|------|---------|--------|
| Composer manifest | `composer validate --strict` | ✅ `./composer.json is valid` |
| Code style | `pint --test` | ✅ PSR-12 `passed` |
| Static analysis | `phpstan analyse` (level 5, Larastan) | ✅ **No errors** |
| Tests | `php artisan test` | ✅ **29 passed / 100 assertions** |

Test breakdown: the 11 Sprint 5.1 tests still pass unchanged (no regressions), plus 18 new
assertions across enums, DTO immutability, repository behaviour, service business rules
(unique slug, illegal status transition, non-editable-project rejection, provider registry
guards) and full container-binding resolution.

**Architecture consistency checks:**
- No `use Illuminate\...\DB`/`Eloquent` model calls in `app/Services` (DB access is repository-only).
- Controllers reference services, never repositories/models directly.
- No `@phpstan-ignore`, baseline entries, inline `@var` overrides, silencing casts, or `assert()`
  were used to reach a green PHPStan run.

---

## 12. Known Risks / Notes

1. **Generic-repository typing (`all()`).** Larastan collapses a template type to its bound
   (`Model`) through Eloquent's `Builder::get()` in the abstract base context. `BaseRepository::all()`
   is therefore typed `Collection<int, Model>`; every **concrete** repository query (`forProject`,
   `active`, etc.) remains precisely typed. This is a documented static-analysis limitation, not a
   suppression — the code is correct at runtime.
2. **Policy naming.** Requested `AssetPolicy`/`WorkflowPolicy` are implemented as
   `GeneratedAssetPolicy`/`WorkflowRunPolicy` to match Laravel's auto-discovery convention for the
   `GeneratedAsset`/`WorkflowRun` models.
3. **Two supporting repositories added.** `ProjectInputRepository` and a provider-setting upsert on
   `ProviderRepository` were introduced so `GenerationRequestService`/`ProviderRegistryService` never
   touch Eloquent directly. They extend the requested set; they do not replace anything.
4. **Execution/tracking is state-only.** `ExecutionTracker`, `WorkflowService::transition()` and the
   agent/prompt services mutate **record state** (status/timestamps). They intentionally run no
   pipeline, agent, prompt or provider — that behaviour is deferred to later sprints.
5. **OneDrive-synced path** (carried over from 5.1): clear the read-only attribute if Laravel's cache
   writes trip; a non-synced dev path is recommended.

---

## 13. Preparation for Sprint 5.3

The domain layer is ready to be driven:

- **API surface:** add resource controllers under `routes/api/v1.php` that inject the new service
  contracts, use the Form Requests + Resources, and apply the policies. Wrap `DomainException` in the
  exception handler using its `errorCode()/statusCode()/context()` and `ApiResponse` for a uniform
  JSON envelope.
- **AI providers (5.3+):** implement provider clients behind `ProviderDefinitionInterface`, resolved
  via the existing `ProviderRegistryService` (registry + settings are already in place).
- **Generation & pipeline:** wire the queue/jobs and a workflow engine that consume
  `GenerationRequestService` briefs and drive `WorkflowService`/`AgentExecutionService`/`PromptService`
  records through `ExecutionTracker`.
- **Storage:** stream real media through `StorageInterface`/`MediaFileRepository`.
- **Seeders:** add an admin user and a provider catalogue using the 5.1 factories.

---

## Stop Condition

Sprint 5.2 is **complete**. All verification gates pass (Composer, Pint, PHPStan level 5, 29 tests).
No Sprint 5.3 work (AI providers, generation, prompt execution, queue/jobs/events, workflow engine)
has been started. **Awaiting the next instruction.**
