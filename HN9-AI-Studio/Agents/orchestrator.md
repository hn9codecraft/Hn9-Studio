# Orchestrator

The **Orchestrator** is the conductor. It receives a content request, resolves which agents to run,
in what order, and with what concurrency, and it manages retries, conditional branches and the
human-approval gate. It holds no content logic itself — it only routes.

## Canonical agent order

```
Planner
   ↓
Research
   ↓
Strategy
   ↓
SEO
   ↓
Script
   ↓
Storyboard
   ↓
Image Prompt
   ↓
Video Prompt
   ↓
Voice
   ↓
Caption
   ↓
Thumbnail
   ↓
Review
   ↓
QA
   ↓
Publisher
   ↓
Analytics
```

`Blog Agent` and `Social Agent` are **content-type specialists** the Planner routes to in the
Script/writing stage depending on `deliverableType` (see conditional tasks below).

## Execution Order

1. The **Planner** decomposes the request into a **task graph** (which deliverables, which agents,
   which language, which platform).
2. The Orchestrator executes the graph respecting dependencies (`handoff-rules.md`).
3. State is tracked per `requestId`; each agent emits an event on completion.

## Sequential Tasks

These must run in order because each consumes the previous output:

- `Planner → Research → Strategy → SEO → Script → Storyboard`
- `Review → QA → (Human Approval) → Publisher → Analytics`

## Parallel Tasks

After **Storyboard** (and SEO) are ready, these run **concurrently** (no interdependency):

- `Image Prompt Agent`
- `Video Prompt Agent`
- `Voice Agent`
- `Caption Agent`
- `Thumbnail Agent`
- `Hashtags` (via Social Agent / Caption Agent)

They fan-in to the **Review Agent**.

```
                 ┌── Image Prompt ──┐
 Storyboard ─────┼── Video Prompt ──┤
   + SEO         ├── Voice ─────────┼──► Review ─► QA ─► [Human] ─► Publisher ─► Analytics
                 ├── Caption ───────┤
                 └── Thumbnail ─────┘
```

## Conditional Tasks

The Planner sets flags that gate agents:

| Condition | Effect |
|-----------|--------|
| `deliverableType = blog/website/landing/proposal/email` | Route writing to **Blog/Social/Sales** path; skip Storyboard/Voice/Video. |
| `deliverableType = reel/short/youtube` | Run full media chain (Script → Storyboard → Image/Video/Voice/Thumbnail). |
| `deliverableType = static post/carousel` | Run Image Prompt + Caption + Hashtags; skip Voice/Video. |
| `needsSEO = true` | Run SEO Agent; else skip. |
| `language ≠ en` | All writing agents produce in `{{language}}`; brand terms stay untranslated. |
| `humanApproval = required` (default) | Block Publisher until approval event. |

## Retry Strategy

- Each agent has its own **Retry Rules** (see its contract) — default **3 attempts** with
  exponential backoff (1s, 4s, 9s).
- Transient failures (timeout, provider 5xx, rate limit) → retry.
- Deterministic failures (validation failed, missing brand data) → **do not retry**; escalate.
- After max retries → mark step `failed`, halt dependents, escalate per `error-handling.md`.

## Orchestrator responsibilities

- Resolve task graph from Planner output.
- Dispatch jobs to queue workers; respect concurrency limits.
- Track state, emit/consume events, enforce timeouts.
- Manage the human-approval gate.
- Aggregate the final content bundle for Publisher.
- Never mutate Brand Brain or Prompt Engine.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
