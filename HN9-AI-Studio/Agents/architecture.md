# Architecture — Multi-Agent AI System

## Why Multi-Agent?

A single monolithic prompt that tries to research, write, optimize, storyboard, narrate and publish
is brittle, hard to test and impossible to reason about. HN9 instead uses a **fleet of
single-responsibility agents** chained by an orchestrator. Each agent does one thing, is tested in
isolation, and can be upgraded or swapped without touching the rest of the system.

## Advantages

- **Specialization** — each agent has a tight prompt, tuned model and focused validation.
- **Testability** — small contracts are easy to unit-test and regression-test.
- **Observability** — every step logs its own input/output/timing/errors.
- **Resilience** — one agent failing does not collapse the pipeline; it retries or escalates.
- **Parallelism** — independent agents (e.g. image-prompt + voice + hashtags) run concurrently.
- **Cost control** — cheap agents use small models; only hard steps use large models.

## Scalability

- **Horizontal**: agents are stateless workers; run N instances behind a queue.
- **Vertical**: add new agents (e.g. `translation-agent`, `ads-agent`) without redesign.
- **Content types**: new deliverables map to a template in `../Prompts` + an agent or agent config.

## Loose Coupling

- Agents communicate only through a **shared context object** and **typed contracts**
  (`communication-protocol.md`), never by reaching into each other's internals.
- Any agent is **independently replaceable** as long as it honors its input/output contract.
- The AI provider is behind an **abstraction layer** — agents request a capability
  ("generate text", "generate image prompt"), not a vendor.

## High Cohesion

- Each agent owns exactly one concern (plan, research, write script, validate…).
- Brand and prompt logic live in `../Brand` and `../Prompts`; agents orchestrate, they don't
  redefine.

## Event-Driven Flow

- The pipeline is modeled as **events**: `RequestReceived → PlanCreated → ResearchReady → … →
  Published → AnalyticsRecorded`.
- Agents subscribe to the events they consume and emit events they produce.
- Enables fan-out (one event → many agents) and fan-in (many outputs → one review step).

## Queue Ready

- Every agent invocation is a **job** with a payload (shared context slice) and a result.
- Jobs are idempotent and carry a `requestId` + `version` for safe retries (`performance.md`).
- Long or parallel work is dispatched to queue workers; the orchestrator tracks state.

## API Ready

- The system is designed to sit behind a future API: `POST /content/generate` starts a pipeline;
  `GET /content/{requestId}` returns status/output (see `../Documentation/API.md`).
- Contracts are JSON, versioned and stable — safe for external consumers.

## Cloud Ready

- Stateless agents + external memory store (`memory.md`) + object storage for media (`../Output`,
  `../Assets`) → deployable to any cloud, autoscaled by queue depth.
- Secrets (API keys) come from a secret manager, never from Brand/Prompt files (`security.md`).

## AI Provider Abstraction

- A provider-agnostic interface exposes capabilities: `text.generate`, `image.prompt`,
  `video.prompt`, `speech.synthesize`, `embed`.
- Providers (multiple Claude models and others) are configured per agent via its `agent.json`
  `model` field; switching providers is a config change, not a code change.

## Human Approval

- A mandatory approval gate sits between **QA Agent** and **Publisher Agent**.
- Nothing public-facing is published without human sign-off (`../Brand/ai-rules.json > humanReview`).

## Future Expansion

- Planned agents: `translation-agent`, `ads-agent`, `email-sequence-agent`, `report-agent`.
- Planned surfaces: multi-brand/multi-client (parameterize the Brand Brain path per tenant).
- The architecture requires **no structural change** to add these — only new contracts + configs.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
