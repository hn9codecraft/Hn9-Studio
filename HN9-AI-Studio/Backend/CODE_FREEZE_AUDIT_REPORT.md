# Code Freeze Audit Report

Scope: entire `Backend/` tree — 320 PHP files under `app/`, 20 API controllers, 87 registered routes, 16 migrations, 314 tests.

## Executive Summary

The core domain is in good shape. The repository layer is perfectly paired (13 implementations, 13 contracts, zero orphans on either side), every container binding resolves, every route target method exists, there are no duplicate controllers, services, repositories, DTOs or policies, and no unused DTOs.

The problems are concentrated in **one cluster: seven placeholder controllers added in the most recent sprints** — `Dashboard`, `Analytics`, `Settings`, `Export`, `System`, `Trace`, `ActivityLog`. They account for **every** PHPStan error, **every** PSR-12 failure, the only security finding, and the only architecture-layering violations in the codebase. Between them they own 18 of the 87 routes.

Their common shape is string-based service location — `class_exists('App\Services\DashboardService')`, `app('App\Services\ExportService')` — probing for **ten service classes that do not exist**:

```
App\Services\DashboardService     NOT FOUND    App\Services\SystemService     NOT FOUND
App\Services\UsageService         NOT FOUND    App\Services\MetricsService    NOT FOUND
App\Services\CostService          NOT FOUND    App\Services\TraceService      NOT FOUND
App\Services\NotificationService  NOT FOUND    App\Services\AnalyticsService  NOT FOUND
App\Services\SettingsService      NOT FOUND    App\Services\ExportService     NOT FOUND
```

Verified in a booted container: every probe is false, so **every one of those 18 endpoints returns an empty envelope**. One exception — `GET /system/activity-logs` returns real data, and did so with no authorization at all.

Four things were fixed: one security vulnerability, two latent runtime bugs, one dead duplicate class, plus style restoration. Nothing working was touched, nothing was renamed, no architecture was redesigned, and no feature was implemented.

---

## Issues Found

### Critical

**C1 — Unauthenticated-tier data leak on `GET /api/v1/system/activity-logs`** *(fixed)*

`ActivityLogController::index` had **no `authorize()` call** and the query applies **no ownership scope**. `ActivityLogRepository::filterable()` exposes only `action` and `user_id` as optional equality filters, so the default call returned the entire `activity_logs` table.

Any authenticated member could therefore read every other user's audit trail, including `ip_address`, `user_agent`, and the `properties` JSON column that carries before/after values. The raw `LengthAwarePaginator` was returned directly as the response body, so internal auto-increment ids leaked too.

For contrast, all 11 properly-built controllers authorize every action:

| Controller | authorize() calls | public methods |
|---|---|---|
| GeneratedContent | 8 | 9 |
| Provider | 9 | 10 |
| Project / GeneratedAsset | 7 | 8 |
| WorkflowRun | 6 | 7 |
| User | 5 | 6 |
| Agent / ProjectPrompt | 4 | 5 |
| BrandBrain / Generation | 3 | 4 |
| **ActivityLog, Analytics, Dashboard, Export, Settings, System, Trace** | **0** | **18 total** |

`AuthController` and `HealthController` legitimately need none.

### High

**H2 — `DashboardController` called a method that does not exist** *(fixed)*

Lines 27 and 45 called `App\Services\ProjectService::listForDashboard()`. **No such method exists.** It was reachable only behind `app()->bound('App\Services\ProjectService')`, and the container binds `ProjectServiceInterface`, never the concrete class — verified `bound = NO`. So the branch was dead and the endpoint returned `[]` instead of crashing.

This was a live landmine: the moment anyone binds `ProjectService` concretely, `GET /dashboard/summary` and `GET /dashboard/projects` become instant `500`s. PHPStan flagged both as `method.notFound`.

**H3 — `SettingsController` reported success while silently discarding all input** *(fixed)*

`PATCH /api/v1/settings` and `PATCH /api/v1/settings/notifications` wrote to `$user->settings` and `$user->notification_settings`. Verified against the schema: **`users` has no `settings` column and no `notification_settings` column** (`Schema::hasColumn('users','settings') === false`).

The write therefore always threw at `save()`, was swallowed by a bare `catch (\Throwable)`, and the method fell through to `return ApiResponse::success([])`. The API answered **HTTP 200 to a write that persisted nothing** — the worst failure mode available, since a client cannot distinguish it from success. PHPStan flagged both as `property.notFound`, plus a `catch.neverThrown` dead catch on line 30.

### Medium

**M4 — Duplicate, entirely dead Resource: `WorkflowResource`** *(fixed)*

Both `WorkflowResource` and `WorkflowRunResource` mapped the same `WorkflowRun` model with overlapping fields. An exhaustive search across `*.php`, `*.blade.php` and `*.md` outside `vendor/` found **zero references** to `WorkflowResource` other than its own declaration and a mention in the historical `SPRINT_5_2_REPORT.md`. `WorkflowRunResource` superseded it and is the one `WorkflowRunController` uses.

**M5 — Controllers reaching past the service layer into repositories** *(partially fixed)*

`ActivityLogController` and `TraceController` resolved repositories directly and called `paginate()` on them, skipping the service layer entirely — a breach of the project's Controllers → Services → Repositories rule that every other controller follows. Both also used `app()->bound(...)` service location instead of constructor injection.

**M6 — PSR-12 non-compliance** *(fixed)*

`vendor/bin/pint --test` failed on exactly 8 files: the 7 placeholder controllers plus `routes/api/v1.php`. Violations were `ordered_imports`, `line_ending`, `blank_line_before_statement`, `cast_spaces` and `fully_qualified_strict_types`. Every other file in the project already complied.

### Low / informational — no code changed

**L7 — Latent authorization gap on `GET /system/traces`.** Same unguarded shape as C1, but `TraceRepositoryInterface` does not exist and `TraceService` does not exist, so the endpoint returns a hardcoded empty envelope. **Not currently exploitable — it becomes C1 the moment tracing infrastructure is bound.**

**L8 — Two unused Form Requests.** `StoreAssetRequest` and `StoreProviderRequest` are complete and valid but referenced nowhere; the `POST /generated-assets` and `POST /providers` endpoints they validate were never built. Left in place as prepared work rather than deleted.

**L9 — Inconsistent favourite verbs between sibling resources.**

```
content:  POST /generated-content/{uuid}/favorite   DELETE /generated-content/{uuid}/favorite
assets:   POST /generated-assets/{uuid}/favorite    POST   /generated-assets/{uuid}/unfavorite
```

Two different conventions for the same operation. Not changed — assets' form is covered by passing tests, and altering either breaks a published contract.

**L10 — Singular/plural collection inconsistency.** `generated-content` is singular; `generated-assets`, `workflow-runs`, `provider-settings`, `projects`, `users` are plural. Flagged in the Sprint 5.4.4 report and still open.

**L11 — 27 blueprint endpoints documented but not implemented.** Chiefly the asset library (`/assets/*`), the workflows module (`/workflows/*`), and health/provider sub-resources. Expected mid-build state, listed in Remaining Recommendations.

**L12 — `AuthServiceProvider` extends a deprecated base class.** It uses `Illuminate\Foundation\Support\Providers\AuthServiceProvider` with a `$policies` array and `registerPolicies()`. Laravel 11+ auto-discovers `App\Policies\{Model}Policy`, which is how the other four policies already resolve, so the explicit map for `AiProvider` and `User` is redundant. Working, so untouched.

**L13 — One lazy-loaded relation per single-record workflow response.** `WorkflowRunResource` reads `$this->project?->uuid`. The list path eager-loads `['project']` correctly, but `show`, `retry`, `cancel` and `timeline` resolve via `findByUuidOrFail()` with no `with()`, costing one extra query each. Single record, not a loop — not an N+1.

**L14 — `StorageInterface` / `FilesystemStorage` bound but never consumed.** No service or controller injects it; only the container-bindings test references it. Legitimate infrastructure held for future use.

### Audit items with nothing to report

| # | Item | Result |
|---|---|---|
| 1 | Duplicate Controllers | **None.** 20 controllers, no overlapping responsibilities |
| 2 | Duplicate Services | **None.** 16 services, each with one contract |
| 3 | Duplicate Repositories | **None.** 13 implementations ↔ 13 contracts, zero orphans either direction |
| 5 | Duplicate Resources | **One found** → M4 |
| 6 | Duplicate Policies | **None.** 6 policies, 6 distinct models |
| 7 | Duplicate DTOs | **None.** 11 DTOs, all referenced outside `app/DTOs` |
| 8 | Duplicate Interfaces | **None.** 36 declared; 34 bound; the 2 unbound are `RepositoryInterface` (generic base) and `ProviderDefinitionInterface` (implemented by provider classes, not container-resolved) — both correct |
| 9 | Unused Routes | **None orphaned.** All 87 route targets verified to resolve to an existing class and method |
| 13 | Incorrect DI bindings | **None.** Every binding resolves; `ProviderRegistryService` intentionally serves two contracts as a documented shared singleton |
| 14 | Route → Controller → Service | Consistent in 13 of 20 controllers; the 7 placeholders are M5/H2/H3 |
| 15 | Repository → Service | Consistent except M5 |

---

## Issues Fixed

| ID | Fix | Risk |
|---|---|---|
| C1 | Added `ActivityLogPolicy` (admin-only `viewAny`, mirroring `UserPolicy`) and an `authorize('viewAny', ActivityLog::class)` call. Replaced `app()->bound()` service location with constructor injection of the already-bound repository contract. | Behaviour change **by design**: non-admins now get `403` instead of other users' data. Admin response shape unchanged. |
| H2 | Removed the two unreachable `listForDashboard()` branches. `$payload['projects']` is now explicitly `[]`. | **Zero.** Observable behaviour identical — the branch could never execute. |
| H3 | Removed the impossible `$user->settings` / `$user->notification_settings` writes and reads, and the two dead `catch (\Throwable)` blocks. | **Zero.** All four endpoints already returned `{"data":[]}`; they still do. The lie is gone from the code, though the endpoint contract is unchanged — see Recommendation R2. |
| M4 | Deleted `app/Http/Resources/WorkflowResource.php`. | **Zero.** Confirmed unreferenced. |
| M5 | Fixed for `ActivityLogController` as part of C1. **`TraceController` left as-is** — its repository branch is unreachable, so there is no live violation to fix, and rewriting it would mean designing a tracing service that does not exist. | — |
| M6 | Ran `vendor/bin/pint` (the project's own formatter, already the standard everywhere else). | Formatting only. |

### Deliberately not fixed

- **The 7 placeholder controllers were not rewritten.** Making them functional requires `DashboardService`, `SettingsService`, `AnalyticsService`, `ExportService`, `SystemService`, a tracing layer, and a `users.settings` migration. That is feature work, explicitly out of scope for this audit. Their inert `class_exists` probes were left intact — ugly, but harmless and clearly the author's forward-compatibility intent.
- **No response contract was altered.** `PATCH /settings` still answers `200 {"data":[]}`. Converting it to a truthful `501` is the right call but it is a contract change during a freeze, so it is Recommendation R2 for your decision rather than a unilateral edit.
- **L8–L14** left untouched per "do not change working code".

---

## Files Changed

**Modified (4)**

| File | Change |
|---|---|
| `app/Http/Controllers/Api/V1/ActivityLogController.php` | Added policy authorization; constructor DI replaces service location; added return types |
| `app/Http/Controllers/Api/V1/DashboardController.php` | Removed 2 unreachable calls to a non-existent method |
| `app/Http/Controllers/Api/V1/SettingsController.php` | Removed impossible column writes/reads and 2 dead catch blocks |
| `routes/api/v1.php` | Import ordering (pint) |

**Added (2)**

| File | Purpose |
|---|---|
| `app/Policies/ActivityLogPolicy.php` | The authorization that was missing for C1 |
| `tests/Feature/SystemEndpointAuthorizationTest.php` | 4 regression tests pinning the C1 boundary |

**Deleted (1)**

- `app/Http/Resources/WorkflowResource.php` — dead duplicate

**Reformatted by pint, no logic change (4)**

`AnalyticsController.php`, `ExportController.php`, `SystemController.php`, `TraceController.php`

No file or class was renamed. No migration was added. No binding was changed.

---

## Verification Results

All seven commands, in the order specified, on Laragon PHP 8.3.30.

| # | Command | Result |
|---|---|---|
| 1 | `composer validate --strict` | `./composer.json is valid` |
| 2 | `composer dump-autoload` | Generated optimized autoload files containing **7036 classes** |
| 3 | `php artisan optimize:clear` | config, cache, compiled, events, routes, views — all `DONE` |
| 4 | `php artisan test` | **314 tests, 1119 assertions, 0 failures** |
| 5 | `php artisan route:list` | **87 routes**, all resolving |
| 6 | `vendor/bin/pint --test` | **passed** |
| 7 | `vendor/bin/phpstan analyse --memory-limit=2G` | **`[OK] No errors`** |

### Before → after

| Metric | Before | After |
|---|---|---|
| PHPStan errors | **5** | **0** |
| `pint --test` | **fail** (8 files) | **passed** |
| Tests | 310 | **314** |
| Assertions | 1109 | **1119** |
| Unauthorized data-leaking endpoints | **1** | **0** |
| Calls to non-existent methods | **2** | **0** |
| Dead duplicate classes | **1** | **0** |

No suppressions were used anywhere: no `@phpstan-ignore`, no baseline, no `assert()`, no inline `@var`, no casts or widened signatures added to silence anything. `phpstan.neon` is unchanged.

**Note on `php artisan test` output.** It reports "299 warnings". Every one is the same pre-existing environment notice — `file_get_contents(Backend\.env): Failed to open stream` from `vlucas/phpdotenv`, because the repository has no `.env`. It predates this audit, affects tests from every earlier sprint equally, does not occur under `phpunit` directly, and no test fails either way. Direct `phpunit` run: `OK (314 tests, 1119 assertions)`.

**New tests added (4)** — `SystemEndpointAuthorizationTest`:
- non-admin gets `403` on `/system/activity-logs`
- another user's audit trail does not leak: asserts neither the action string nor the seeded IP `203.0.113.7` appears in the body
- an admin still gets `200` with the paginated structure intact
- unauthenticated gets `401`

---

## Remaining Recommendations

Ordered by priority. **None were applied** — all need either a decision or feature work.

**R1 — Decide the fate of the 18 placeholder endpoints.** They are live, documented, authenticated routes that always return empty data. A client integrating against them cannot tell "no data" from "not built". Either implement the services behind them or remove the routes until the services exist. Leaving them advertised is the current risk.

**R2 — Make `PATCH /settings` and `PATCH /settings/notifications` stop claiming success.** They answer `200` to writes that cannot persist. Returning `501` with an `error_code` via the existing `ApiResponse::error()` would be honest and costs almost nothing — but it is a contract change, so it needs your sign-off.

**R3 — Gate `/system/traces` before wiring tracing.** It is safe only because no trace infrastructure is bound. Add authorization in the same commit that introduces `TraceRepositoryInterface`, or it ships as a duplicate of C1.

**R4 — Wrap the activity-log response in a JsonResource.** It currently returns the raw paginator, exposing internal auto-increment ids and every model attribute. Admin-only now, so severity is low, but every other endpoint in the project uses a Resource.

**R5 — Settle the favourite-verb and singular/plural inconsistencies (L9, L10)** before more clients integrate. Both are cheap now and expensive after adoption.

**R6 — Add the remaining 27 blueprint endpoints** or mark them as deferred in `API_BLUEPRINT.md`, so the document stops reading as a description of what exists.

**R7 — Modernise `AuthServiceProvider` (L12).** Drop the deprecated base class and the redundant `$policies` map; rely on the auto-discovery the other policies already use.

**R8 — Eager-load `project` on single-record workflow reads (L13).** A `with(['project'])` on those four paths removes one query each.

**R9 — Add a `.env.example` and a test-bootstrap `.env`,** to clear the 299 phpdotenv warnings that currently bury real signal in `artisan test` output.

**R10 — Cover the placeholder endpoints with tests when they are implemented.** The complete absence of tests for those 18 routes is exactly why a call to a non-existent method (H2) and a silently-failing write (H3) survived in a suite of 310 passing tests. PHPStan caught both; tests did not.

---

## Status

Audit complete. Fixes limited to the four real defects plus style restoration. All seven verification commands pass, PHPStan is clean, and 314 tests pass.

**Stopping here and awaiting approval** before any further change.
