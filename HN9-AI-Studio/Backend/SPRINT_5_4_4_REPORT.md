# Sprint 5.4.4 — Generated Content APIs

## Executive Summary

Six REST endpoints now expose generated content: list, show, delete, favourite, unfavourite and regenerate. The sprint is API-only. The AI provider layer, the prompt runtime and the execution orchestrator were not modified.

The audit found almost everything needed already in place — model, repository, service, resource, policy and response envelope all existed and were reused. Three things did not exist and had to be added:

1. **No favourite storage anywhere in the schema.** `generated_contents` has no such column, no pivot table exists, and the string "favorite" appeared nowhere in `app`, `database`, `routes`, `tests` or `config`. An additive migration adds an indexed `is_favorite` boolean. The original create migration was not edited.
2. **No filtered/paginated query for content.** The repository had only `paginate()` (whitelisted equality filters, no search, sort, date or ownership scoping). A `paginateForOwner()` method was added, mirroring `ProjectRepository::paginateForUser()` field for field.
3. **No home for regeneration.** It could not go on `ContentService`: the orchestrator already depends on `ContentServiceInterface`, so injecting the orchestrator back would be a circular container dependency. A separate thin `ContentRegenerationService` was introduced that derives a request from the stored content and delegates the entire run to the existing orchestrator.

All 287 tests pass. **PHPStan reports zero errors for the first time in the project's history** — the two long-standing `BrandContextService` errors turned out to be a false positive from missing model annotations, not a runtime bug (see Final Notes).

## Architecture Reused

Reused without modification:

| Layer | Component | How |
|---|---|---|
| Model | `GeneratedContent` | Soft deletes, UUID lookup, `project` relation for ownership |
| Repository | `BaseRepository` | `findByUuidOrFail`, `update`, `delete`, `applyFilters` |
| Repository | `GeneratedContentRepository::filterable()` | Existing `type`/`status`/`channel`/`language` whitelist drives four of the list filters |
| Service | `ContentService::getByUuid`, `delete` | Show/delete endpoints; `delete` already writes the activity log |
| Service | `ExecutionOrchestratorInterface` | The entire regeneration pipeline — no generation logic was reimplemented |
| Service | `PromptServiceInterface::forAgentExecution` | Recovers the variables the original prompt was rendered with |
| Policy | `GeneratedContentPolicy` | `viewAny`, `view`, `update`, `delete`. Favourite maps to `update`. No new policy, no inline role checks |
| Resource | `GeneratedContentResource` | Extended with three fields; not replaced |
| Resource | `AssetResource` | The asset in the regenerate response |
| Support | `ApiResponse` | `success` with meta, `created`, `noContent` |
| Exceptions | `GenerationException` + the `DomainException` renderer | Regeneration failures surface as the canonical envelope |
| Pattern | `ProjectController::index` | Copied for pagination, filter collection and the meta envelope |

The `options['variables']` seam on the orchestrator, added in Sprint 5.4.3C, is what lets regeneration supply recovered variables without the orchestrator changing at all.

## APIs Added

All under `auth:sanctum`. Path segment is `generated-content` (singular), per the sprint brief.

| Method | Path | Behaviour |
|---|---|---|
| `GET` | `/api/v1/generated-content` | Paginated list, scoped to the caller's projects; admins see all |
| `GET` | `/api/v1/generated-content/{uuid}` | Show one record |
| `DELETE` | `/api/v1/generated-content/{uuid}` | Soft delete → `204` |
| `POST` | `/api/v1/generated-content/{uuid}/favorite` | Set the flag; idempotent → updated resource |
| `DELETE` | `/api/v1/generated-content/{uuid}/favorite` | Clear the flag; idempotent → updated resource |
| `POST` | `/api/v1/generated-content/{uuid}/regenerate` | Re-run the pipeline → `201` |

### Listing

- **Pagination** — `page`, `perPage` (default 15). Meta: `page`, `perPage`, `total`, `lastPage`.
- **Search** — `search` across `title`, `body`, `uuid`.
- **Sorting** — `sort` whitelisted to `created_at`, `updated_at`, `title`, `status`, `type`, `version`; `order` is `asc`/`desc`, defaulting to `created_at desc`. An unrecognised `sort` falls back to the default rather than erroring, matching `ProjectRepository`.
- **Filters** — `project` (project uuid), `status`, `provider`, `template`, `date`, plus `type`, `channel`, `language` and `favorite`.

`provider` and `template` are not columns. The orchestrator records them inside the JSON payloads it writes, so they are filtered as `metadata->provider` and `structured->template_key`. Both are surfaced on the resource so a client can read back what it filtered on.

### Regeneration

`POST .../regenerate` reconstructs a `GenerationRequestData` from the stored content (project, type, channel as platform, language, title as topic) and resolves the template key from `structured.template_key`, falling back to the content type.

Variables are resolved in ascending precedence: the variables recorded on the original `PromptExecution` (found via the content's `agent_execution_id`), then any `variables` in the request body. An empty request body therefore reproduces the original render faithfully. Content with no linked agent execution has nothing to recover, so the caller must supply the variables or receive a `422` naming the first one missing.

The original record is never mutated — regeneration produces a new content row through the normal pipeline, with `source: 'regenerate'` on the recorded project input and `regenerated_from` in the response.

Optional body: `template_key`, `model`, `topic`, `goal`, `payload`, `variables`.

## Files Added

- `app/Http/Controllers/Api/V1/GeneratedContentController.php`
- `app/Contracts/Services/ContentRegenerationServiceInterface.php`
- `app/Services/ContentRegenerationService.php`
- `app/Http/Requests/RegenerateContentRequest.php`
- `database/migrations/2026_08_08_100000_add_is_favorite_to_generated_contents_table.php`
- `tests/Feature/GeneratedContentApiTest.php`

## Files Modified

- `app/Models/GeneratedContent.php` — `is_favorite` added to `$fillable` and cast to boolean
- `app/Repositories/Contracts/GeneratedContentRepositoryInterface.php` — declared `paginateForOwner()`
- `app/Repositories/GeneratedContentRepository.php` — implemented `paginateForOwner()` (ownership scope, project/provider/template/favorite/date filters, search, whitelisted sort)
- `app/Contracts/Services/ContentServiceInterface.php` — declared `paginateForUser()` and `setFavorite()`
- `app/Services/ContentService.php` — implemented both; `setFavorite` logs `content.favorited` / `content.unfavorited`
- `app/Http/Resources/GeneratedContentResource.php` — added `is_favorite`, `provider`, `template_key` and a `whenLoaded` `project` summary
- `app/Providers/DomainServiceProvider.php` — bound `ContentRegenerationServiceInterface`
- `routes/api/v1.php` — six routes under the `generated-content` prefix
- `app/Models/Project.php` — added the missing `@property` annotations for `settings` and `metadata` (see Final Notes)
- `app/Services/PromptRuntime/BrandContextService.php` — **docblock only**: removed a stale `@param $decoded` tag referencing a parameter that does not exist (see Known Risks)
- `API_BLUEPRINT.md` — reconciled section 15.7 with the implemented routes and filters
- `SPRINT_5_4_3C_REPORT.md` — retracted an incorrect known-issue entry (see Final Notes)

## Tests Added

`tests/Feature/GeneratedContentApiTest.php` — 18 tests, 91 assertions:

| Area | Coverage |
|---|---|
| List | Ownership scoping (2 own vs 3 another user's → 2 returned); admin sees all |
| Pagination | 7 records at `perPage=3&page=2` → 3 items, `lastPage=3`, `total=7` |
| Filters | `status`, `type`, `language`; `project`, `provider`, `template`, `favorite`; `date` |
| Search & sort | `search=Alpha`; `sort=title` both `asc` and `desc` |
| Show | Field-level assertions including `is_favorite` |
| Delete | `204`, soft-deleted in DB, then absent from list and `404` on show |
| Favourite | Toggle on then off, asserted in both response and database |
| Favourite | Idempotent when already favourited |
| Regenerate | Recovers recorded prompt variables; original row untouched; count goes 1 → 2 |
| Regenerate | Explicit `variables` override when nothing is recoverable |
| Regenerate | `422 generation_missing_prompt_variable` when a variable cannot be filled |
| Regenerate | Request validation rejects a non-array `variables` |
| Authorization | Another user gets `403` on all five per-record endpoints, and the record survives |
| Authorization | All six endpoints return `401` unauthenticated |
| Authorization | An admin may act on another user's content |
| Not found | Unknown uuid → `404` |

The provider dispatcher is faked in regeneration tests, so no test reaches the network.

## Verification Results

Run with the Laragon PHP 8.3.30 runtime, in the order the sprint specified.

| Command | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `composer dump-autoload` | Generated optimized autoload files containing 7006 classes |
| `vendor/bin/pint` | 2 files fixed (import ordering, brace position) |
| `vendor/bin/pint --test` | **passed** |
| `vendor/bin/phpstan analyse --configuration phpstan.neon` | **`[OK] No errors`** |
| `php artisan test` | 287 tests, 1015 assertions, **0 failures** |

`phpunit` directly: `OK (287 tests, 1015 assertions)` — up from 268 tests / 897 assertions.

No suppressions were used. No `@phpstan-ignore` comments, no baseline, no `assert()`, no inline `@var`, no casts or widened signatures added to silence anything. The `phpstan.neon` configuration is unchanged.

Note on `php artisan test` output: it reports 272 "warnings". Every one is the same pre-existing environment notice — `file_get_contents(Backend\.env): Failed to open stream` raised by `vlucas/phpdotenv` because the repository has no `.env`. It is unrelated to this sprint, affects tests written in earlier sprints equally, and does not occur under `phpunit` directly. Zero tests fail either way.

Route registration verified with `artisan route:list --path=generated-content` — all six present with the expected `api.v1.generated-content.*` names.

## Known Risks

1. **New database column.** `is_favorite` requires the migration to run in every environment. It is nullable-free with a `false` default, so existing rows are unaffected, and `down()` drops both the index and the column.

2. **Path segment differs from the original blueprint.** The brief specified `/api/v1/generated-content` (singular). `API_BLUEPRINT.md` section 15.7 planned `generated-contents` (plural), and section 132 states the convention is plural for multi-word resources — the sibling `generated-assets` endpoints, when built, will be plural. The brief was followed and the blueprint reconciled, but `generated-content` is now the one singular collection in the v1 surface. Worth a deliberate decision before the assets equivalent is built.

3. **One file in a "do not modify" area was touched, docblock only.** `BrandContextService` is prompt runtime, which this sprint was told not to modify. It had been edited between sprints and left a stale `@param $decoded` tag referencing a parameter that does not exist, producing a PHPStan error. Since the sprint requires zero errors and forbids suppressions and baselines, the only remaining option was to delete the bogus tag. No logic, signature or behaviour changed. Flagged explicitly rather than done quietly.

4. **Regeneration is synchronous and inherits the pipeline's characteristics.** It runs inside the HTTP request and waits on the provider chain, and the pipeline is not transactional — both carried over from Sprint 5.4.3C and unchanged here.

5. **`provider` and `template` filters query JSON paths.** They work on SQLite (the test driver) and MySQL, but a JSON path predicate cannot use a conventional column index, so these two filters will scan as the table grows. Promoting them to real columns is the fix if listing volume becomes a concern.

6. **List parameters are not validated.** `perPage` is cast with `(int)`, and `sort` is whitelisted server-side, so bad input degrades to defaults rather than erroring. This matches `ProjectController::index` exactly; a `perPage` upper bound is not enforced there either, and a very large value would request a very large page.

7. **`GeneratedContentResource` gained three fields.** Additive and backward compatible, but the resource is shared with `GenerationController`'s generate and history responses, which now include them too.

8. **Content of a soft-deleted project.** `paginateForOwner` scopes through `whereHas('project')`, and `Project` soft-deletes, so content under a soft-deleted project silently leaves the list while `show` by uuid still resolves it. No test pins this behaviour either way.

## Final Notes

**PHPStan is now completely clean.** The two `BrandContextService` `function.impossibleType` errors that this project has carried — and which the Sprint 5.4.3C report recorded as a real bug silently discarding per-project tone overrides — were a **false positive**, and that earlier diagnosis was wrong.

The `Project` model casts `settings` and `metadata` to `array` but carried no matching `@property` annotations, so PHPStan fell back to the raw `json` column type of `string|null` and concluded `is_array()` could never be true. Adding the two annotations cleared both errors. Runtime behaviour was then verified directly: `is_array($project->settings)` returns `true` and the tone override does apply. Nothing was broken; the annotations were simply missing. The incorrect entry in `SPRINT_5_4_3C_REPORT.md` has been retracted in place.

**Boundaries respected.** No file under `app/AI` was touched. No prompt runtime logic was changed (the single edit there was deleting a docblock tag, item 3 above). `ExecutionOrchestrator` was not modified — regeneration consumes it through its existing interface and the `options` seam it already exposes. Controllers call services, services call repositories; the controller contains no query building and no authorization logic beyond `authorize()` calls.

**Not started.** Sprint 5.4.5 has not been begun. `PATCH /generated-content/{uuid}` and `POST /generated-content/{uuid}/approve` remain unimplemented and are marked as such in the blueprint; they were not in this sprint's scope.
