# SPRINT 5.4.2 — PROJECT MANAGEMENT APIs

1. Executive Summary

- Implemented REST APIs to expose the existing Project domain using the established Controllers → Services → Repositories architecture. No domain logic was rewritten; service/repository implementations were reused where possible.

2. APIs Implemented

- POST   /api/v1/projects
- GET    /api/v1/projects
- GET    /api/v1/projects/{uuid}
- PATCH  /api/v1/projects/{uuid}
- DELETE /api/v1/projects/{uuid}
- POST   /api/v1/projects/{uuid}/archive
- POST   /api/v1/projects/{uuid}/restore
- GET    /api/v1/projects/{uuid}/inputs
- POST   /api/v1/projects/{uuid}/inputs

Search & Sorting

- Free-text search on `name`, `description`, and `uuid` via the `search` query parameter.
- Sorting via `sort` and `order` query parameters supporting `created_at`, `updated_at`, `name`, and `status` with `asc`/`desc`.

3. Project Lifecycle

- Creation via `ProjectService::create` using `CreateProjectData` DTO.
- Updates via `ProjectService::update` with `UpdateProjectData` DTO and guarded status transitions.
- Archive implemented as a status change to `archived` via `ProjectService::changeStatus`.
- Delete uses existing soft-deletes; restore uses `ProjectService::restore` (new helper delegating to model restore).

4. Validation

- `StoreProjectRequest` and `UpdateProjectRequest` were reused.
- `StoreProjectInputRequest` added for generation briefs.

5. Authorization

- All controller actions use `$this->authorize(...)` and the existing `ProjectPolicy`.
- No inline role checks were added in controllers.

6. Search & Filtering
 - Filtering: supported filters are delegated to the repository and include `status` and `type`.

Search & Extended Filtering

- Free-text search: implemented at the repository layer. Projects for the user are filtered by the `search` query term across `name`, `description`, and `uuid`.
- Date filtering: the `date` query parameter filters `created_at` by day (YYYY-MM-DD).
- Owner / created_by: parameters are accepted by the controller but results are scoped to the authenticated user via `paginateForUser` (no cross-user data is returned).

7. Pagination

- Listing uses the existing `ProjectService::paginateForUser` and returns the standard API envelope with `meta` pagination fields.

Sorting & Pagination

- `paginateForUser` now accepts `sort` and `order` in its `$filters` argument. Sorting is applied before pagination.

8. Files Created

- app/Http/Controllers/Api/V1/ProjectController.php
- app/Http/Controllers/Api/V1/ProjectInputController.php
- app/Http/Requests/StoreProjectInputRequest.php
- app/Http/Resources/ProjectInputResource.php
- tests/Feature/ProjectApiTest.php
- SPRINT_5_4_2_REPORT.md

9. Files Modified

- app/Services/ProjectService.php (added `restore`)
- app/Contracts/Services/ProjectServiceInterface.php (added `restore`)
- app/Contracts/Services/GenerationRequestServiceInterface.php (added `forProject`)
- app/Services/GenerationRequestService.php (implemented `forProject`)
- routes/api/v1.php (registered project routes)

10. Tests Added

- tests/Feature/ProjectApiTest.php — covers create, list, show, update, archive, delete & restore, inputs create & list.
	- tests/Feature/ProjectApiTest.php — extended with `test_search_and_sort_projects` covering free-text search and name sorting.

11. Verification Results

- Commands executed and results:

	- `composer validate --strict`: passed (no output).
	- `composer dump-autoload`: ran.
	- `vendor/bin/pint`: formatting fixes applied where required; final run passed.
	- `vendor/bin/phpstan analyse --configuration phpstan.neon`: No errors.
	- `php artisan test`: full test suite executed — 15 passed, 241 warnings, 818 assertions. Full test output saved in workspace logs.

12. Risks

- Implementation notes and risks:
	- Free-text search is implemented using SQL LIKE predicates across `name`, `description`, and `uuid`. This is correct functionally but may be slower on large datasets compared to full-text indexes — consider adding a DB full-text index or external search engine for scale.
	- Sorting is whitelisted to safe columns (`created_at`, `updated_at`, `name`, `status`) to avoid SQL injection; however sorting on non-indexed columns can impact query performance.
	- `restore` locates trashed projects via `withTrashed()` and calls Eloquent `restore()`; this relies on correct DB soft-delete semantics and appropriate test coverage.

13. Future Compatibility

- The repository now implements basic free-text search and configurable sorting. Future improvements to consider:
	- Add full-text indexes (or use a search engine) to replace LIKE-based search for better performance and relevance.
	- Add database indexes on frequently-sorted or filtered columns to improve query performance.
	- Extend filtering to support ranges (created_at ranges) and more advanced query DSL if product needs grow.

14. Final Notes

- The implementation strictly reused existing services and repositories and kept controllers thin. Let me know if you want additional feature tests (e.g., authorization edge cases) or to extend repository filtering/search.
