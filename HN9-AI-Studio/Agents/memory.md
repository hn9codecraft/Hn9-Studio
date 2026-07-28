# Memory

The memory layer provides context to agents without duplicating the Brand Brain or Prompt Engine.
It is external to agents (agents are stateless) and addressable per request.

## Memory types

| Type | Scope | Lifetime | Store (conceptual) |
|------|-------|----------|--------------------|
| **Short-Term Memory** | One agent call | Ephemeral | In-request working set |
| **Runtime Context** | One pipeline run (`requestId`) | Until run completes | Fast cache (e.g. Redis) |
| **Conversation Memory** | Human-in-the-loop threads | Session | Cache + audit log |
| **Project Memory** | A campaign/client project | Long-lived | Database |
| **Brand Memory** | Whole brand | Persistent, read-only | `../Brand` (source of truth) |
| **Prompt Memory** | Whole engine | Persistent, read-only | `../Prompts` (source of truth) |
| **Persistent Context** | Cross-run analytics/learnings | Persistent | Database + `../Output` |

## Short-Term Memory
- Scratch space for a single agent invocation (intermediate reasoning, partial drafts).
- Never persisted; discarded after the call returns.

## Runtime Context
- The shared context object `ctx://<requestId>` (`communication-protocol.md`).
- Append-only during the run; holds request metadata, resolved variables and each agent's output.

## Brand Memory (read-only)
- **Is** the Brand Brain (`../Brand`). Agents read voice, services, colors, CTA, rules.
- Never written to by agents. A change here propagates to all future runs automatically.

## Prompt Memory (read-only)
- **Is** the Prompt Engine (`../Prompts`). Agents load templates + variable contracts.
- Never written to by agents.

## Project Memory
- Per-project/campaign store: briefs, past deliverables, approved assets, tone adjustments,
  do/don't notes learned over time.
- Read by Strategy/Planner to keep a project consistent across many requests.

## Conversation Memory
- Human review threads, approval decisions, reviewer notes, revision history.
- Feeds the approval loop and audit trail.

## Persistent Context (learning loop)
- Analytics Agent writes performance metrics and learnings here.
- Strategy Agent reads it to improve future hooks, angles and scheduling.

## Rules
- Agents **read** Brand/Prompt memory; they never copy it into other stores.
- Runtime context is namespaced per agent to prevent collisions.
- Sensitive data is never written to memory in plaintext (`security.md`).
- Memory entries carry `requestId`, `version` and timestamps for traceability.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
