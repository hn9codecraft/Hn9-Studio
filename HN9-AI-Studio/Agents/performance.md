# Performance

Targets and strategies to keep the pipeline fast, cheap and scalable.

## Execution Goals (targets, tune with real data)

| Metric | Target |
|--------|--------|
| Single text agent latency (p95) | < 8s |
| Media-prompt agent latency (p95) | < 6s |
| Full social-post pipeline (excl. human approval) | < 60s |
| Full video pipeline (excl. human approval) | < 3 min |
| Agent success rate (post-retry) | > 99% |
| Cache hit rate (repeat inputs) | > 40% |

## Caching Strategy
- **Brand/Prompt cache**: `../Brand` and `../Prompts` are read-heavy and rarely change → cache in
  memory with version-keyed invalidation.
- **Step result cache**: keyed by `(agent, inputHash, agentVersion, promptVersion)` → identical
  inputs return cached output (idempotency).
- **Embedding/research cache**: reuse research for the same topic within a freshness window.
- `forceRefresh` bypasses caches when needed.

## Parallel Execution
- Fan-out Image/Video/Voice/Caption/Thumbnail agents concurrently after Storyboard/SEO.
- Concurrency capped per provider rate limits and per-worker CPU.
- Fan-in barrier at Review (`handoff-rules.md`).

## Queue Strategy
- Each agent call is a queue job; workers scale horizontally by queue depth.
- Priority queues: interactive requests > batch/scheduled generation.
- Idempotent jobs + dedupe keys make redelivery safe.
- Dead-letter queue for jobs that exhaust retries (feeds escalation).

## Optimization
- Right-size models per agent (small/cheap for mechanical steps, large for hard reasoning) via
  `agent.json > model`.
- Trim prompts: reference Brand data by resolved variables, not full-file dumps.
- Batch independent generations where a provider supports it.
- Stream long outputs where the surface benefits.
- Track token cost per agent (`logging.md`) and set budgets.

## Backpressure & limits
- Per-provider rate-limit awareness; exponential backoff on 429.
- Total per-request token/time budget enforced by the orchestrator watchdog.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
