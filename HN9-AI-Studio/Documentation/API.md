# API — HN9 AI Studio

> Placeholder specification for the backend API that orchestrates the content pipeline.

## Conventions
- Base URL: `/api/v1`
- Format: JSON request/response.
- Auth: Bearer token (to be defined).

## Planned Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/content/generate` | Kick off a content generation job. |
| `GET`  | `/content/{id}` | Retrieve a job's status and output. |
| `GET`  | `/agents` | List available agents. |
| `POST` | `/workflows/{id}/run` | Trigger a workflow. |
| `GET`  | `/brand` | Read the brand source of truth. |

## Production Plans

Read-only. Only the project owner or an admin can read them; identifiers from another project
return `404`. Plans are created by the backend, not through the API. See
[Architecture](Architecture.md#production-plan-m1118) for the rules.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/story/projects/{project}/production-plans` | Every plan revision for the project, newest first, with scene and unit counts. |
| `GET` | `/story/projects/{project}/production-plans/{plan}` | One plan with its scenes and 10-second units. |
| `GET` | `/story/projects/{project}/production-plans/{plan}/scenes/{scene}` | One scene of a plan with its units. `{scene}` is the story scene id. |

Each unit is returned as `{ id, sequence, start_second, duration_seconds, end_second, kind }`,
where `kind` is `standard` (a full 10 seconds) or `remainder` (the shorter final unit).

## Request / Response Schemas
_To be defined. Reference the JSON templates under `/Brand` and `/Agents`._

_This is a placeholder — replace with the actual OpenAPI/spec once the backend exists._
