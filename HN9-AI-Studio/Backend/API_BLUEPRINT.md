# HN9 AI Studio — API Blueprint

## 1 Executive Summary

This document defines the REST API architecture for HN9 AI Studio as the single source of truth for Sprint 5.4.x.
It is a design-only blueprint: no Laravel code, controllers, routes, requests, resources, tests, or implementation changes are included.

The API is designed to support:
- React web dashboard
- Future Flutter mobile app
- Future multi-tenant SaaS
- Future public API
- AI workflow engine

It is grounded in the current backend domain model and architecture, including:
- `User`, `Project`, `ProjectInput`, `GeneratedContent`, `GeneratedAsset`, `WorkflowRun`, `AiProvider`, `ProviderSetting`, `AgentExecution`
- Current service contracts and repository patterns
- Existing AI provider configuration and provider routing design
- The current API shell in `routes/api/v1.php`, `bootstrap/app.php`, and Sanctum authentication config

This blueprint freezes endpoint naming, structure, request/response conventions, validation, pagination, filtering, sorting, search, error handling, and security for the next implementation phase.

## 2 API Architecture

### Principles

- Resource-oriented REST following Laravel conventions.
- Versioned contract from day one.
- JSON-only API surface under a single base prefix.
- Thin controllers / orchestration only, with business logic delegated to services and repositories.
- Pluggable provider and workflow architecture with strong separation of concerns.
- API surface is explicit and additive; v1 is immutable once released.

### Existing architecture

- Base route file: `routes/api/v1.php`
- Mounted at: `/api/v1` via `bootstrap/app.php`
- API middleware group is enforced globally for `/api/*`
- Force JSON responses for all API requests via middleware
- Current defined endpoints:
  - `GET /api/v1/health`
  - `GET /api/v1/user`

### Domain alignment

- `User` owns `Project`
- `Project` owns `ProjectInput`, `WorkflowRun`, `GeneratedContent`, `GeneratedAsset`, `PublishJob`
- `WorkflowRun` tracks AI workflow execution lifecycle
- `ProjectInput` represents generation requests / prompt briefs
- `GeneratedContent` and `GeneratedAsset` represent output artifacts
- `AiProvider` and `ProviderSetting` represent platform provider catalog and credentials
- AI orchestration, provider routing, and health are infrastructure concerns, not business payloads

## 3 Versioning Strategy

### URL versioning

- Use path-based versioning: `/api/v1/...`
- Every released version gets its own route file and documentation contract.
- No versioning via headers for initial launch.
- Future versions are additive; v1 remains unchanged.

### Versioning rules

- Minor and patch API changes only within new versions.
- Bugfix changes and backward-compatible additions may be released as v1 patches, provided no endpoint contract changes.
- Breaking changes require a new version: v2, v3, etc.

## 4 Authentication Strategy

### Primary mechanism

- Use Laravel Sanctum bearer token authentication for API access.
- Support first-party SPA cookie flows in future via Sanctum stateful domains.
- Public and unauthenticated routes are explicitly separated.

### Endpoint contract

- `POST /api/v1/auth/login` — obtain bearer token
- `POST /api/v1/auth/logout` — revoke current token
- `POST /api/v1/auth/refresh` — refresh token lifecycle if token refreshing is supported
- `GET /api/v1/auth/user` — current authenticated user
- `PATCH /api/v1/auth/password` — change password
- `PATCH /api/v1/auth/profile` — update profile metadata

### Token lifecycle

- Bearer tokens carry user identity and expiration.
- Use Sanctum personal access token expiration controls.
- Support token revocation and per-device sessions.
- Token refresh is optional, but if enabled should use refresh token semantics separate from primary bearer token.

### Roles and permissions

- Use `User.role` and `User.permissions` as current authority model.
- Roles: `admin`, `editor`, `viewer` as base guidance.
- Permissions are fine-grained strings stored in JSON array.
- Admins implicitly hold all permissions.
- Permission checks are implemented at policy/authorization layer.

### Access flow

- Authenticated API requests require `Authorization: Bearer <token>`.
- Public endpoints are documented separately.
- No mixed stateful+stateless authentication except for future first-party SPA support with Sanctum stateful cookies.

## 5 Endpoint Naming Standards

### General conventions

- Use plural nouns for resource collections: `/projects`, `/users`, `/providers`.
- Use singular resource identifiers for item operations: `/projects/{project_id}`.
- Use UUIDs in route parameters when possible.
- Use kebab-case for path segments.
- Use HTTP verbs semantically: `GET`, `POST`, `PATCH`, `DELETE`.
- Avoid verbs in path segments except for explicit actions that do not fit REST resources.

### Resource IDs

- Prefer `uuid` route keys for API-level identifiers; secondary integer IDs remain internal.
- Example: `/api/v1/projects/{project_uuid}`.

### Action endpoints

- Use nested resource endpoints for relationship-driven actions.
- Use action subresources for workflow operations and transitions.
- Example: `/projects/{project_uuid}/workflow-runs/{workflow_uuid}/cancel`.

### Consistency rules

- Keep endpoint names stable across modules.
- Use `-` separators for multi-word resources: `generated-assets`, `workflow-runs`.
- Use `status` query parameter for filtering status values.

## 6 Request Standards

### Request body format

- All request bodies must be JSON.
- Use `application/json` content type.
- Use concise and explicit payloads.
- Nested objects are allowed for structured payloads where domain models require it.

### Common request metadata

- `locale`: string language tag for user preferences and content generation.
- `timezone`: string IANA timezone for user-local data.
- `metadata`: optional free-form object for custom extensions.

### Example shape

```json
{
  "name": "Spring Campaign",
  "description": "A multimedia product launch brief.",
  "type": "marketing",
  "settings": {
    "tone": "professional"
  },
  "metadata": {
    "businessUnit": "growth"
  }
}
```

### Request conventions

- `POST` to create resources.
- `PATCH` to update partial resource state.
- `PUT` may be used only for full resource replacement when required.
- `DELETE` to soft-delete resources.
- `GET` for collection retrieval and single item retrieval.
- Use request bodies for complex actions; use query parameters for filtering, sorting, pagination, and simple query flags.

## 7 Response Standards

### Success response envelope

All successful responses must use a consistent envelope:

```json
{
  "success": true,
  "message": "...",
  "data": {
    ...
  },
  "meta": {
    ...
  }
}
```

### Response fields

- `success`: boolean
- `message`: human-readable summary
- `data`: resource payload or collection
- `meta`: pagination, tracing, and additional metadata

### Collection example

```json
{
  "success": true,
  "message": "Projects retrieved successfully.",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "perPage": 15,
    "total": 45,
    "lastPage": 3
  }
}
```

### Item example

```json
{
  "success": true,
  "message": "Project retrieved successfully.",
  "data": {
    "uuid": "...",
    "name": "..."
  },
  "meta": {}
}
```

### Empty payloads

- `data` may be an object or null for no payload.
- `meta` should always be an object.

### Headers

- API responses should include standard caching headers where appropriate.
- Error responses should include `Content-Type: application/problem+json` when possible.

## 8 Error Standards

### Error envelope

```json
{
  "success": false,
  "message": "...",
  "errors": [
    {
      "code": "...",
      "field": "...",
      "message": "..."
    }
  ],
  "meta": {
    "traceId": "..."
  }
}
```

### Error categories

- Validation: `422 Unprocessable Entity`
- Authentication: `401 Unauthorized`
- Authorization: `403 Forbidden`
- Not Found: `404 Not Found`
- Conflict: `409 Conflict`
- Rate Limit: `429 Too Many Requests`
- Server: `500 Internal Server Error`
- Provider: `502/503/504` for AI provider upstream failures
- Workflow: `409 Conflict` or `422` for invalid workflow transitions

### Validation errors

- Provide one error object per invalid field.
- Example:

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": [
    {
      "code": "validation.required",
      "field": "name",
      "message": "The name field is required."
    }
  ],
  "meta": {}
}
```

### Provider errors

- Use normalized provider error codes and map to HTTP error semantics.
- Example:
  - `provider.authentication_failed`
  - `provider.rate_limited`
  - `provider.unavailable`
  - `provider.timeout`
- Do not leak provider secrets.

### Workflow errors

- Use explicit workflow codes for invalid lifecycle operations.
- Example: `workflow.already_finished`, `workflow.invalid_transition`, `workflow.not_found`.

## 9 Pagination Standards

### Default behavior

- Use cursor or page-based pagination depending on resource semantics.
- For standard list endpoints, use page-based pagination.
- Default page size: `15`.
- Maximum page size: `100`.

### Query parameters

- `page`: integer, default `1`
- `perPage`: integer, default `15`
- `limit`: alias for `perPage` if required
- `cursor`: optional future support for cursor pagination on high-volume resources

### Metadata

- Return `page`, `perPage`, `total`, `lastPage`, `hasMore`, `nextPageUrl`, `prevPageUrl` when applicable.

### Example

```json
{
  "success": true,
  "message": "Projects retrieved successfully.",
  "data": [...],
  "meta": {
    "page": 1,
    "perPage": 15,
    "total": 45,
    "lastPage": 3,
    "hasMore": true
  }
}
```

## 10 Filtering Standards

### Query-based filtering

- Use query parameters for filters on collection endpoints.
- Use clear and consistent names: `status`, `type`, `platform`, `provider`, `userId`, `createdAfter`, `createdBefore`.

### Filter syntax

- Single value: `?status=active`
- Multiple values: `?status=active,inactive`
- Range filters: `?createdAfter=2026-01-01&createdBefore=2026-12-31`
- Boolean flags: `?archived=true`

### Reserved filters

- `status` for lifecycle state
- `type` for content and asset categories
- `owner` or `userUuid` for user-scoped data
- `projectUuid` for child resources within a project

### Implementation note

- The backend should support sane defaults and ignore unsupported filters rather than failing.

## 11 Sorting Standards

### Query parameter

- Use `sort` parameter with comma-separated fields.
- Prefix with `-` for descending order.

### Examples

- `?sort=created_at` — ascending created date
- `?sort=-created_at` — descending created date
- `?sort=status,-name`

### Supported fields

- `created_at`, `updated_at`, `name`, `status`, `priority`, `title`

### Default sort

- Collections default to stable order defined per resource:
  - `projects`: `updated_at desc`
  - `workflow-runs`: `started_at desc`
  - `generated-contents`: `created_at desc`
  - `generated-assets`: `created_at desc`

## 12 Search Standards

### Search parameter

- Use `search` for single-field free-text search.
- Use resource-specific search fields only when needed.

### Example

- `/api/v1/projects?search=launch`
- `/api/v1/generated-contents?search=blog`

### Advanced search

- Support search across multiple searchable attributes internally.
- Do not overload `search` with structured queries.

## 13 Validation Standards

### Request validation

- Validate request payloads at the API boundary.
- Use explicit rules for all fields.
- Normalize incoming strings and trim whitespace.
- Enforce UUID format for route parameters.
- Use `nullable` only when the field can legitimately be omitted.

### Response validation

- API responses should be validated against the documented contract.
- Keep response shape stable across revisions.
- Avoid returning raw model attributes unless explicitly documented.

### Common field rules

- `uuid`: required, `uuid`
- `name`: required for create operations, string, max length 191
- `email`: required, email format, unique for user creation
- `status`: required when changing state, must match domain enum values
- `locale`: optional, must follow BCP 47-style locale tag
- `timezone`: optional, must be valid IANA timezone string

### Enum validation

- Use domain enums for `status`, `type`, `workflow_key`, `deliverable_type`, `platform`, `asset type`, `provider`, etc.
- Return `422` for invalid enum values with descriptive error codes.

### UUID usage

- Use UUIDs in API models for stable external references.
- Internally, integer IDs may continue to exist, but they must not be exposed unless explicitly required.
- Example route parameter: `/projects/{project_uuid}`.

## 14 Security Standards

### Authentication

- All non-public API requests must require bearer token authentication.
- Personal access tokens must be stored securely and rotated as needed.

### Authorization

- Enforce resource-level authorization via policies.
- Users may only access their own projects and workflows unless they hold admin scope.
- Provider management and system endpoints must be restricted to admins.

### Rate limiting

- Apply API rate limiting at the gateway / middleware layer.
- Use shared and route-specific limits for burst control.
- Return `429 Too Many Requests` for throttled clients.

### Sensitive data

- Never return credentials, API keys, or secret provider settings in API responses.
- Mask secret values when provider settings are exposed.
- Do not expose `ProviderSetting.value` for secret rows.

### Headers

- Enforce `Content-Type: application/json`.
- Support `Accept: application/json`.
- Use strict CORS policy for allowed frontend domains.
- Apply `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` as platform defaults.

### CORS

- Permit only trusted origins for the React dashboard and future Flutter web host if applicable.
- Deny others by default.

### CSRF policy

- API endpoints using bearer tokens do not require CSRF.
- If first-party SPA auth via stateful Sanctum cookies is used, maintain CSRF protection for cookie-based flows.

### API versioning security

- Treat `/api/v1` as a stable contract with backward compatibility.
- Do not expose debug or internal status endpoints under `/api/v1`.

## 15 Module-wise Endpoint Catalog

This catalog defines design-only endpoint names, supported operations, request expectations, and response semantics for the existing backend scope.

### 15.1 Authentication

- `POST /api/v1/auth/login`
  - Request: `{ "email": "...", "password": "..." }`
  - Response: token metadata and user info

- `POST /api/v1/auth/logout`
  - Request: none
  - Response: revocation confirmation

- `POST /api/v1/auth/refresh`
  - Request: optional refresh token
  - Response: new token set

- `GET /api/v1/auth/user`
  - Request: bearer token
  - Response: authenticated user profile

- `PATCH /api/v1/auth/password`
  - Request: `{ "current_password": "...", "new_password": "..." }`
  - Response: confirmation

- `PATCH /api/v1/auth/profile`
  - Request: profile fields like `name`, `avatar_path`, `locale`, `timezone`
  - Response: updated profile

### 15.2 Users

- `GET /api/v1/users`
  - List users; admin-only.
  - Supports `search`, `status`, `role`, `sort`, `page`, `perPage`.

- `GET /api/v1/users/{user_uuid}`
  - Get user details.

- `PATCH /api/v1/users/{user_uuid}`
  - Update user metadata and permissions.

- `DELETE /api/v1/users/{user_uuid}`
  - Soft-delete user.

- `POST /api/v1/users/{user_uuid}/restore`
  - Restore soft-deleted user.

### 15.3 Projects

- `GET /api/v1/projects`
  - List projects for current user or admin.
  - Supports `search`, `status`, `type`, `userUuid`, `sort`, `page`, `perPage`.

- `POST /api/v1/projects`
  - Create a project.
  - Body: `name`, `description`, `type`, `settings`, `metadata`.

- `GET /api/v1/projects/{project_uuid}`
  - Retrieve project details.

- `PATCH /api/v1/projects/{project_uuid}`
  - Update project metadata, name, slug, status.

- `DELETE /api/v1/projects/{project_uuid}`
  - Soft-delete project.

- `POST /api/v1/projects/{project_uuid}/restore`
  - Restore soft-deleted project.

- `POST /api/v1/projects/{project_uuid}/status`
  - Change project status with guarded transitions.
  - Body: `{ "status": "..." }`

### 15.4 Brand Brain

`Brand Brain` is the concept layer for creative prompts and brand-aware generation guidance. It is represented by project settings and prompt input.

- `GET /api/v1/brand-brain`
  - Retrieve brand guidance metadata for current user/project context.

- `PATCH /api/v1/brand-brain`
  - Update brand guidance defaults.
  - Body: `tone`, `voice`, `audience`, `guidelines`, `metadata`.

- `POST /api/v1/projects/{project_uuid}/brand-insights`
  - Generate brand prompt suggestions or recommendations.
  - Body: `context`, `goals`, `platform`, `language`.

### 15.5 Prompt Engine

The prompt engine is backed by `ProjectInput` and generation request DTOs.

- `GET /api/v1/projects/{project_uuid}/prompts`
  - List generation requests / prompt briefs.

- `POST /api/v1/projects/{project_uuid}/prompts`
  - Submit a generation request.
  - Body: `type`, `deliverable_type`, `platform`, `language`, `topic`, `goal`, `payload`, `source`.

- `GET /api/v1/projects/{project_uuid}/prompts/{prompt_uuid}`
  - Retrieve a prompt submission.

- `DELETE /api/v1/projects/{project_uuid}/prompts/{prompt_uuid}`
  - Soft-delete a prompt.

### 15.6 Agents

The agent subsystem is represented by `AgentExecution` and `WorkflowRun`.

- `GET /api/v1/agents`
  - List agent executions.
  - Supports `workflowUuid`, `provider`, `status`, `sort`, `page`, `perPage`.

- `GET /api/v1/agents/{agent_uuid}`
  - Retrieve agent execution details.

- `GET /api/v1/workflows/{workflow_uuid}/agents`
  - List agents for a workflow.

- `GET /api/v1/projects/{project_uuid}/agents`
  - List agents for a project.

### 15.7 Generation

The generation module spans creation of content and assets.

- `POST /api/v1/projects/{project_uuid}/generate`
  - Record a `ProjectInput` and execute the generation pipeline through `ExecutionOrchestrator`.
  - Body: `type`, `deliverable_type`, `platform`, `language`, `topic`, `goal`, `payload`, `source`, optional `workflowKey`.
  - `deliverable_type` doubles as the prompt template key and must be one of the prompt catalog keys (`blog`, `caption`, `email`, `image`, `instagram`, `linkedin`, `reel`, `seo`, `script`, `storyboard`, `thumbnail`, `video`, `voice`, `website`, `youtube`).
  - `payload` entries become prompt template variables, and are how a caller fills placeholders the brand context does not source (for `blog`: `keywords`, `keywords_focus`, `service`, `cta`, `seo_rules`, `brand_rules`, `word_count`, `key_points`).
  - `201` → `data.input`, `data.content`, `data.asset`, `data.dispatch`.
  - `409 generation_project_not_editable` · `422 generation_unsupported_deliverable` · `422 generation_unsupported_language` · `422 generation_missing_prompt_variable` · `502 ai_all_providers_failed`.
  - Runs synchronously; the response waits on the provider call.

- `POST /api/v1/projects/{project_uuid}/generate/preview`
  - Generate a preview request payload, optional for UI scaffolding.

- `GET /api/v1/projects/{project_uuid}/generation-history`
  - Retrieve generation history across prompts, contents, and assets.

Implemented in Sprint 5.4.4 under the path segment `generated-content` (singular), not the
`generated-contents` this section originally planned. The sprint brief specified the singular
form; these entries were reconciled to the implemented routes.

- `GET /api/v1/generated-content` — **implemented**
  - List generated content artifacts, scoped to the projects the caller owns. Administrators list across every project.
  - Filters: `project` (project uuid), `status`, `provider`, `template`, `date` (`YYYY-MM-DD`, on `created_at`), `type`, `channel`, `language`, `favorite`.
  - Also supports `search` (title, body, uuid), `sort` (`created_at`, `updated_at`, `title`, `status`, `type`, `version`), `order` (`asc`/`desc`), `page`, `perPage`.
  - `provider` and `template` are matched inside the recorded `metadata->provider` and `structured->template_key` payloads, as neither is a column.
  - Meta envelope: `page`, `perPage`, `total`, `lastPage`.

- `GET /api/v1/generated-content/{content_uuid}` — **implemented**
  - Retrieve generated content details.

- `DELETE /api/v1/generated-content/{content_uuid}` — **implemented**
  - Soft-delete a content record. `204`.

- `POST /api/v1/generated-content/{content_uuid}/favorite` — **implemented**
  - Flag as favourite. Idempotent. Returns the updated resource.

- `DELETE /api/v1/generated-content/{content_uuid}/favorite` — **implemented**
  - Clear the favourite flag. Idempotent. Returns the updated resource.

- `POST /api/v1/generated-content/{content_uuid}/regenerate` — **implemented**
  - Re-run the pipeline for existing content. `201` with `content`, `asset`, `dispatch`, `regenerated_from`.
  - Optional body: `template_key`, `model`, `topic`, `goal`, `payload`, `variables`. An empty body reuses the template and variables the original render was recorded with.
  - The original record is left untouched; a new content row is produced.

- `PATCH /api/v1/generated-content/{content_uuid}` — *not yet implemented*
  - Update content metadata, title, status.

- `POST /api/v1/generated-content/{content_uuid}/approve` — *not yet implemented*
  - Approve a generated content item.

- `GET /api/v1/generated-assets`
  - List generated assets.
  - Supports `projectUuid`, `type`, `provider`, `status`, `search`, `sort`, `page`, `perPage`.

- `GET /api/v1/generated-assets/{asset_uuid}`
  - Retrieve generated asset details.

- `PATCH /api/v1/generated-assets/{asset_uuid}`
  - Update asset metadata and status.

- `POST /api/v1/generated-assets/{asset_uuid}/cancel`
  - Cancel pending asset generation.

### 15.8 Assets

The asset module is focused on media files and delivery metadata.

- `GET /api/v1/assets`
  - List asset records and public asset metadata.

- `POST /api/v1/assets/upload`
  - Upload an asset file or link.
  - Body: `projectUuid`, `type`, `metadata`, `fileUrl` or multipart support in future.

- `GET /api/v1/assets/{asset_uuid}`
  - Retrieve asset metadata.

- `DELETE /api/v1/assets/{asset_uuid}`
  - Soft-delete asset record.

- `POST /api/v1/assets/{asset_uuid}/restore`
  - Restore asset.

- `GET /api/v1/assets/{asset_uuid}/download`
  - Download URL or redirect to storage.

- `GET /api/v1/assets/folders`
  - List asset folders / organizational categories.

- `GET /api/v1/assets/search`
  - Search assets by metadata and tags.

### 15.9 Providers

- `GET /api/v1/providers`
  - List registered AI providers.

- `GET /api/v1/providers/{provider_uuid}`
  - Retrieve provider details.

- `GET /api/v1/providers/{provider_uuid}/settings`
  - Retrieve provider configuration metadata.

- `PATCH /api/v1/providers/{provider_uuid}/settings`
  - Update provider settings metadata.

- `GET /api/v1/providers/{provider_uuid}/health`
  - Retrieve provider health summary.

- `GET /api/v1/providers/{provider_uuid}/usage`
  - Retrieve usage and cost summary for the provider.

- `GET /api/v1/providers/capabilities`
  - Retrieve global provider capability catalog.

### 15.10 Dashboard

- `GET /api/v1/dashboard/summary`
  - High-level project, generation, and provider usage metrics.

- `GET /api/v1/dashboard/projects`
  - Project KPIs and status distribution.

- `GET /api/v1/dashboard/usage`
  - Generation and asset usage trends.

- `GET /api/v1/dashboard/costs`
  - Provider cost and billing trends.

- `GET /api/v1/dashboard/notifications`
  - User notifications and activity alerts.

### 15.11 Analytics

- `GET /api/v1/analytics/usage`
  - Query usage and volume by date, project, provider.

- `GET /api/v1/analytics/performance`
  - Query provider and workflow performance metrics.

### 15.12 Settings

- `GET /api/v1/settings`
  - Retrieve current user or tenant settings.

- `PATCH /api/v1/settings`
  - Update profile and workspace defaults.

- `GET /api/v1/settings/notifications`
  - Retrieve notification preferences.

- `PATCH /api/v1/settings/notifications`
  - Update notification preferences.

### 15.13 Workflow

- `GET /api/v1/workflows`
  - List workflow runs.

- `GET /api/v1/workflows/{workflow_uuid}`
  - Retrieve workflow run details.

- `POST /api/v1/projects/{project_uuid}/workflows`
  - Create workflow run record.
  - Body: `workflowKey`, `context`, `projectUuid`, `userUuid`.

- `PATCH /api/v1/workflows/{workflow_uuid}`
  - Update workflow metadata and status.

- `POST /api/v1/workflows/{workflow_uuid}/approve`
  - Approve workflow state.

- `POST /api/v1/workflows/{workflow_uuid}/retry`
  - Retry a failed workflow run.

- `POST /api/v1/workflows/{workflow_uuid}/resume`
  - Resume a paused workflow.

- `POST /api/v1/workflows/{workflow_uuid}/cancel`
  - Cancel an active workflow.

- `GET /api/v1/workflows/{workflow_uuid}/history`
  - Retrieve workflow execution history and audit trail.

### 15.14 Exports

- `GET /api/v1/exports`
  - List export jobs.

- `POST /api/v1/exports`
  - Create an export job for generated content or assets.

- `GET /api/v1/exports/{export_uuid}`
  - Retrieve export job status.

- `GET /api/v1/exports/{export_uuid}/download`
  - Download completed export bundle.

### 15.15 Health

- `GET /api/v1/health`
  - Platform health status and core dependency probes.

- `GET /api/v1/health/providers`
  - Health summary of AI providers.

- `GET /api/v1/health/system`
  - Optional system health and readiness information for internal use.

### 15.16 System

- `GET /api/v1/system/metrics`
  - System-level metrics for internal operations.

- `GET /api/v1/system/activity-logs`
  - Audit and activity logs, admin-only.

- `GET /api/v1/system/traces`
  - Optional troubleshooting traces and diagnostics.

## 16 Future Compatibility

### React

- JSON envelope is frontend-friendly.
- Standardized pagination, filtering, sorting, and search support list views.
- Auth endpoints support dashboard login, profile, and token handling.

### Flutter

- Simple REST resource design avoids browser-only assumptions.
- JSON responses and envelope structure map cleanly to mobile models.
- Authentication bearer token pattern is compatible with mobile secure storage.

### SaaS

- Versioned API and stable contract support tenant expansion.
- Resource boundaries support admin scope and per-user isolation.
- `metadata` fields enable tenant-specific extensions without schema churn.

### Public API

- Clear separation of public and authenticated routes.
- Stable `/api/v1` contract supports future third-party developer access.
- Provider and generation resource design can be published safely.

### n8n / automation

- Resource-based endpoints with explicit actions are compatible with workflow tools.
- Webhook-ready status endpoints and job polling patterns work well for automation.
- Search, filtering, and pagination enable large dataset integration.

## 17 Risks & Recommendations

### Risks

- Current backend contract is minimal; this blueprint must be validated against the actual implementation when coding begins.
- Some named modules (Brand Brain, Prompt Engine, Agents) are conceptual and depend on future implementation details in backend services.
- Provider management and health semantics must be carefully isolated from user-facing data to avoid exposing secrets.
- Workflow execution semantics may evolve as queue and engine work continues; the API should version workflow actions separately if needed.

### Recommendations

- Keep `v1` immutable once implemented; use `v2` for any breaking workflow or authentication contract changes.
- Implement API response envelopes and error envelopes consistently at the middleware/exception layer.
- Use UUID route keys for all public resource identifiers to decouple from internal integer IDs.
- Keep provider secrets masked in any provider settings payload.
- Validate all `status` and enum fields against backend domain values.
- Keep API endpoints thin: orchestration only, business rules in services.
- Add API documentation and contract tests once implementation begins.

---

This document is the authoritative Sprint 5.4 API design.
