# Sprint 5.1 — Backend Foundation Report

**Project:** HN9 AI Studio
**Sprint:** 5.1 — Backend Foundation (infrastructure only)
**Date:** 2026-07-22
**Stack:** Laravel 12.64.0 · PHP 8.3.30 · MySQL 8.4 · Sanctum 4 · Redis-ready · Queue-ready
**Scope:** Backend infrastructure only. No AI providers, no generation logic, no business workflows.

---

## 1. Summary

Sprint 5.1 establishes the production-ready backend foundation for HN9 AI Studio inside the
existing `Backend/` folder (previously a reserved placeholder). The Laravel 12 application was
installed **into the existing folder structure** — no folders were duplicated and no existing
documentation was modified. Everything is verified end-to-end: migrations run and roll back
cleanly, models and relationships resolve, Sanctum tokens issue and authenticate, the health
endpoint responds over HTTP against real MySQL, and all quality gates pass.

The build deliberately stops at infrastructure. There is **no** AI-provider integration code, **no**
generation logic and **no** pipeline/business orchestration — those are later sprints. Where the
domain requires it (e.g. provider registry, workflow-run tracking), only the schema, models and
relationships were created, not the behavior.

---

## 2. Files Created

### Application configuration
| File | Purpose |
|------|---------|
| `config/hn9.php` | **New** domain config: API versioning, disk map, log channel, supported locales. |
| `bootstrap/app.php` | Rewritten: `/api/v1` versioned routing, JSON exception rendering, JSON middleware. |
| `.env` / `.env.example` | Reconfigured for HN9 identity, MySQL, Redis-ready queue/cache, Sanctum domains. |

### HTTP layer
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/V1/HealthController.php` | `GET /api/v1/health` — status/version/environment/timestamp + DB probe. |
| `app/Http/Middleware/ForceJsonResponse.php` | Forces JSON `Accept` on all API requests. |
| `routes/api/v1.php` | v1 route contract (health + authenticated user route). |
| `routes/api.php` | Repurposed as a documented pointer to versioned route files. |

### Models (`app/Models/`)
`Project`, `ProjectInput`, `WorkflowRun`, `AgentExecution`, `PromptExecution`,
`GeneratedContent`, `GeneratedAsset`, `MediaFile`, `PublishJob`, `AiProvider`,
`ProviderSetting`, `ActivityLog`, plus `Concerns/HasUuid` (UUID auto-fill + route-key trait).

### Migrations (`database/migrations/`)
12 new migrations (`2026_07_22_160001` … `160012`), one per domain table (see §4).

### Factories (`database/factories/`)
12 new factories — one per domain model — for testing/seeding.

### Policies (`app/Policies/`)
`ProjectPolicy` (ownership-based reference pattern) and `AiProviderPolicy` (admin-based pattern).

### Quality & tests
| File | Purpose |
|------|---------|
| `phpstan.neon` | Larastan (PHPStan level 5) configuration. |
| `tests/Feature/HealthEndpointTest.php` | Health endpoint contract. |
| `tests/Feature/AuthTokenTest.php` | Sanctum token issuance & guard. |
| `tests/Feature/ModelRelationshipsTest.php` | Relationships, UUID, encryption at rest, soft deletes. |

---

## 3. Files Modified

| File | Change |
|------|--------|
| `config/app.php` | `name` default → "HN9 AI Studio"; added `version`; timezone now env-driven. |
| `config/filesystems.php` | Added 6 domain disks (projects/images/videos/voice/exports/logs) + `exports` symlink. |
| `config/logging.php` | Added dedicated `hn9` daily log channel (30-day retention). |
| `app/Models/User.php` | Sanctum `HasApiTokens`, `SoftDeletes`, new fillable/casts, role/permission helpers, relationships. |
| `database/migrations/0001_01_01_000000_create_users_table.php` | Extended `users` with role, permissions, status, avatar, locale, timezone, last_login_at, soft deletes (no duplicate migration). |
| `database/factories/UserFactory.php` | New columns + `admin()` state. |
| `public/.gitignore` | Ignore `/exports` symlink. |

> Existing project documentation (`README.md`, `Documentation/`, `Backend/README.md`) was **not**
> modified, per the sprint rules. The Laravel-generated `README.md` was discarded to preserve the
> original HN9 `Backend/README.md`.

---

## 4. Database Tables

13 domain tables (12 new + extended `users`), plus Laravel framework tables
(`cache`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`,
`personal_access_tokens`, `migrations`).

| # | Table | Key columns | Soft delete | Notes |
|---|-------|-------------|:-----------:|-------|
| 1 | `users` | role, permissions(json), status, locale, timezone | ✅ | Role & permission ready. |
| 2 | `ai_providers` | slug, category, status, priority, capabilities(json) | ✅ | Provider registry (schema only). |
| 3 | `provider_settings` | ai_provider_id→, key, value (encrypted), is_secret, environment | — | Unique (provider,key,env). Values encrypted at rest. |
| 4 | `projects` | user_id→, uuid, slug, type, status | ✅ | Unique (user_id, slug). |
| 5 | `project_inputs` | project_id→, user_id→, deliverable_type, platform, language, payload(json) | ✅ | Runtime-variable brief. |
| 6 | `workflow_runs` | project_id→, user_id→, uuid(requestId), workflow_key, status, context(json) | ✅ | Pipeline run tracking. |
| 7 | `agent_executions` | workflow_run_id→, ai_provider_id→, uuid(stepId), agent_key, status, tokens_used, cost | ✅ | Per-agent job. |
| 8 | `prompt_executions` | agent_execution_id→, ai_provider_id→, template_key, tokens, cost, latency_ms | ✅ | Prompt assembly + call record. |
| 9 | `generated_contents` | project_id→, workflow_run_id→, agent_execution_id→, type, channel, language, body | ✅ | Textual output. |
| 10 | `generated_assets` | project_id→, generated_content_id→, workflow_run_id→, type, provider | ✅ | Media output. |
| 11 | `media_files` | mediable (morph), disk, path, checksum, collection, size | ✅ | Polymorphic physical files. |
| 12 | `publish_jobs` | project_id→, generated_content_id→, generated_asset_id→, user_id→, channel, status, scheduled_at | ✅ | Publishing schedule/outcome. |
| 13 | `activity_logs` | user_id→, subject (nullable morph), action, properties(json) | — | Append-only audit (immutable by design). |

**Verified counts:** 22 foreign keys, 99 indexes across the schema.
Every migration is reversible (`down()`), uses indexes, foreign keys and timestamps, and adds soft
deletes to every mutable domain table (audit log intentionally excluded).

---

## 5. Relationships

```
User ─1:N─ Project ─1:N─ ProjectInput
 │            ├─1:N─ WorkflowRun ─1:N─ AgentExecution ─1:N─ PromptExecution
 │            │                          └─(N:1)─ AiProvider ─1:N─ ProviderSetting
 │            ├─1:N─ GeneratedContent ─1:N─ GeneratedAsset
 │            ├─1:N─ GeneratedAsset
 │            ├─1:N─ PublishJob
 │            └─morphMany─ MediaFile (mediable)
 ├─1:N─ WorkflowRun
 └─1:N─ ActivityLog (+ morphTo subject)

GeneratedContent ─morphMany─ MediaFile
GeneratedAsset   ─morphMany─ MediaFile
AiProvider       ─1:N─ AgentExecution / PromptExecution
```

- `belongsTo` / `hasMany` wired on both sides for every FK.
- Polymorphic `MediaFile.mediable` (attachable to any owner) and `ActivityLog.subject`.
- Optional FKs use `nullOnDelete`; owned children use `cascadeOnDelete`.

---

## 6. API Endpoints

Base path: **`/api/v1`** (versioned; `api` middleware group; route-name prefix `api.v1.`).

| Method | Path | Name | Auth | Description |
|--------|------|------|------|-------------|
| `GET` | `/api/v1/health` | `api.v1.health` | public | `{ status, version, environment, timestamp, services }` |
| `GET` | `/api/v1/user` | `api.v1.user` | `auth:sanctum` | Returns the authenticated user. |
| `GET` | `/up` | — | public | Framework liveness probe. |

**Live health response (HTTP 200, real MySQL):**
```json
{"status":"ok","version":"1.0.0","environment":"local","timestamp":"2026-07-22T16:19:37+00:00","services":{"database":true}}
```
Returns HTTP 503 with `"status":"degraded"` if the database probe fails.

---

## 7. Storage Structure

Six domain disks (`config/filesystems.php`), driver-agnostic (`HN9_DISK_DRIVER`, S3-ready):

| Disk | Root | Visibility |
|------|------|-----------|
| `projects` | `storage/app/hn9/projects` | private |
| `images` | `storage/app/hn9/images` | private |
| `videos` | `storage/app/hn9/videos` | private |
| `voice` | `storage/app/hn9/voice` | private |
| `exports` | `storage/app/hn9/exports` | public (served via `public/exports` symlink) |
| `logs` | `storage/app/hn9/logs` | private |

Directory tree created with `.gitignore` guards. Symlinks `public/storage` and `public/exports`
connected via `php artisan storage:link`. Domain log channel writes to `storage/logs/hn9/`.

---

## 8. Configuration Changes

- **Identity:** `APP_NAME="HN9 AI Studio"`, `APP_VERSION=1.0.0`.
- **Timezone:** env-driven (`APP_TIMEZONE`, default `UTC`).
- **Localization:** `en` default; supported content locales `en/hi/gu` (mirrors the Prompt Engine).
- **Database:** MySQL (`hn9_ai_studio`, utf8mb4).
- **Logging:** `stack`→`daily`; dedicated `hn9` channel (30-day retention).
- **Queue/Cache:** Redis-ready; `database` driver as the zero-dependency working default.
- **Auth:** Sanctum installed; `SANCTUM_STATEFUL_DOMAINS` configured; `User` uses `HasApiTokens`.
- **API:** versioned under `/api/v1`; JSON-only responses for `/api/*` including error envelopes.
- **Secrets:** `provider_settings.value` encrypted at rest (`encrypted` cast); `is_secret` drives masking.

---

## 9. Verification Results

| Gate | Command | Result |
|------|---------|--------|
| Composer manifest | `composer validate --strict` | ✅ `./composer.json is valid` |
| Migrations up | `php artisan migrate` | ✅ 16 migrations ran |
| Migrations rollback | `php artisan migrate:rollback` | ✅ full reverse, correct FK order |
| Migrations re-run | `php artisan migrate` | ✅ clean re-apply |
| Static analysis | `phpstan analyse` (level 5, Larastan) | ✅ No errors |
| Code style | `pint --test` | ✅ PSR-12 passed |
| Tests | `php artisan test` | ✅ 11 passed / 28 assertions |
| Live endpoint | `GET /api/v1/health` over HTTP | ✅ HTTP 200, DB probe true |
| Models smoke test | factories + relationships + encryption + policies (tinker) | ✅ all pass |

Tests cover: health contract, public accessibility, Sanctum unauthorized/authorized/token-issue,
pipeline relationship resolution, UUID auto-assignment, secret encryption at rest + masking, and
soft-delete behavior.

---

## 10. Known Risks

1. **OneDrive-synced path.** The project lives under a OneDrive folder, whose read-only directory
   attribute trips PHP's `is_writable()` (Laravel's `package:discover`/cache). Mitigated by clearing
   the attribute (`attrib -R`). **Recommendation:** move the repo to a non-synced path (e.g.
   `C:\dev\`) or exclude it from OneDrive for development.
2. **Redis not provisioned.** Queue/cache are Redis-*ready* but default to the `database` driver so
   the app runs without a Redis server. Switch `QUEUE_CONNECTION`/`CACHE_STORE` to `redis` once one
   is available.
3. **MySQL started manually.** For this verification, Laragon's MySQL was started manually. Ensure
   MySQL runs as a service in shared/CI environments.
4. **Policy coverage is intentionally partial.** Two reference policies exist (owner-based,
   admin-based). Remaining per-resource policies follow the same pattern and are added when their
   endpoints are built (avoids speculative business logic this sprint).
5. **Windows symlinks.** `storage:link` succeeded here; on locked-down Windows hosts symlink
   creation may require Developer Mode or elevation.

---

## 11. Next Sprint Preparation

The foundation is ready for feature work. Suggested entry points for the next sprint(s):

- **Repositories & Services:** schema and models are Repository/Service-ready — introduce
  `app/Repositories` and `app/Services` layers over the existing models.
- **AI Providers (5.2+):** `ai_providers` + `provider_settings` tables/models exist; add the actual
  provider client integrations and a resolver keyed off the registry.
- **Generation logic:** `workflow_runs`, `agent_executions`, `prompt_executions` exist to record
  execution; wire the Prompt Engine and agent pipeline into them.
- **API surface:** extend `routes/api/v1.php` with resource controllers, Form Requests and API
  Resources; per-resource policies follow `ProjectPolicy`/`AiProviderPolicy`.
- **Seeders:** factories are in place; add `DatabaseSeeder` entries (admin user, provider catalog).
- **CI:** the four gates (composer validate, Pint, PHPStan, PHPUnit) are ready to wire into a
  pipeline.

---

## Stop Condition

Sprint 5.1 is **complete**. No Sprint 5.2 work (AI providers, generation, business workflows) has
been started. Awaiting the next instruction.
