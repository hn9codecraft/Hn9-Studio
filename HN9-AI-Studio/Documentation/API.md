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

## Request / Response Schemas
_To be defined. Reference the JSON templates under `/Brand` and `/Agents`._

_This is a placeholder — replace with the actual OpenAPI/spec once the backend exists._
