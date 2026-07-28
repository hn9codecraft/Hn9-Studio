# Handoff Rules

Defines how one agent passes work to the next. Handoffs are **event-driven** and carry a slice of
the **shared context** (`communication-protocol.md`). No agent calls another directly; the
Orchestrator mediates every handoff.

## Principles

1. **Contract-first** — an agent may hand off only if its output validates against its Output
   Contract.
2. **Additive context** — an agent appends its result to shared context; it never deletes or
   overwrites another agent's output.
3. **Traceable** — every handoff emits an event with `requestId`, `fromAgent`, `toAgent`,
   `stepId`, `version`, `timestamp`.
4. **Fail-closed** — if output is invalid, the handoff is blocked and the error path runs
   (`error-handling.md`).

## Handoff record (event payload)

```
{
  "requestId": "...",
  "fromAgent": "script-agent",
  "toAgent": "storyboard-agent",
  "stepId": "step-05",
  "status": "completed",
  "contextRef": "ctx://<requestId>",
  "artifacts": ["script.json"],
  "agentVersion": "1.0.0",
  "promptVersion": "1.0.0",
  "timestamp": "<iso8601>"
}
```

## Sequential handoffs

`Planner → Research → Strategy → SEO → Script → Storyboard` each require the previous step
`status = completed` and validated output.

## Parallel handoffs (fan-out / fan-in)

- After Storyboard + SEO complete, the Orchestrator fans out to Image/Video/Voice/Caption/Thumbnail.
- Each parallel agent writes an independent artifact.
- **Fan-in barrier**: Review Agent starts only when **all** dispatched parallel agents report
  `completed` (or are conditionally skipped).

## Conditional handoffs

- Planner flags decide which downstream agents receive a handoff (see `orchestrator.md`).
- A skipped agent emits a `skipped` event so the fan-in barrier can account for it.

## Human-approval handoff

- QA → Human Approval → Publisher.
- The approval gate produces an `approved` or `rejected` event.
- `rejected` routes back to the Review Agent with reviewer notes (a controlled loop, max 2 cycles
  before escalation).

## Handoff failure

- Invalid output → no handoff; step marked `failed`; dependents halted; escalation per
  `error-handling.md`.
- Timeout waiting for a parallel agent → orchestrator retries that agent, not the whole fan-out.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
