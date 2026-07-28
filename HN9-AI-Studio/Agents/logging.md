# Logging & Observability

Every agent invocation is fully observable. Logs are structured JSON, correlated by `requestId` and
`stepId`, and shipped to a central log store + the Dashboard.

## Every agent must log

| Field | Description |
|-------|-------------|
| `requestId` / `stepId` | Correlation ids across the pipeline |
| `agent` / `agentVersion` | Which agent and version ran |
| `promptVersion` | Prompt template version used |
| `provider` / `model` | AI provider + model (abstraction layer) |
| `input` | Sanitized input payload (secrets redacted) |
| `output` | Sanitized output (or artifact refs) |
| `executionTime` | Duration in ms |
| `tokens` | `tokensIn` / `tokensOut` (cost tracking) |
| `errors` | Error class + message (if any) |
| `retries` | Number of retry attempts |
| `validations` | Per-rule pass/fail |
| `status` | completed / failed / skipped / needs_review |
| `timestamp` | ISO-8601 UTC |

## Log levels
- `DEBUG` — prompt assembly details, resolved variables (redacted).
- `INFO` — step start/complete, handoffs, skips.
- `WARN` — retries, fallbacks, soft validation warnings.
- `ERROR` — failures, escalations, safety blocks.

## Correlation & tracing
- A single `requestId` links every step of a pipeline run.
- Each step has a `traceId`/`spanId` for distributed tracing across queue workers.
- Events (`ScriptGenerated`, `Published`, …) are logged with the same correlation ids.

## Redaction & privacy
- API keys, tokens and PII are **never** logged (`security.md`).
- Inputs/outputs are passed through a redaction step before persistence.

## Metrics (derived from logs)
- Per-agent: success rate, p50/p95 latency, retry rate, token cost.
- Per-pipeline: end-to-end duration, human-approval turnaround, failure hotspots.
- Surfaced on the Dashboard and used for `performance.md` targets.

## Retention
- Operational logs: short/medium retention.
- Audit logs (approvals, publishes, escalations): long retention for compliance.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
