# Error Handling

Defines how the system detects, classifies and recovers from failures. Governing principle: **fail
safe, never fabricate, escalate when unsure** (`../Brand/ai-rules.json > onUncertainty`).

## Error classes

| Class | Retry? | Default action |
|-------|--------|----------------|
| Transient (timeout, 5xx, rate limit) | Yes | Exponential backoff, then escalate |
| Deterministic (validation failed, bad contract) | No | Fix-or-escalate, no blind retry |
| Data (missing brand data / variable) | No | Halt + human flag |
| Safety (injection, disallowed content) | No | Block + log + human review |

## Missing Brand Data
- If a required `../Brand` value cannot be resolved → **stop the step**, emit `needs_review`, and
  flag the missing key. Never guess a company name, service, color or CTA.

## Missing Variables
- Required runtime variable absent → contract error at ingress; return `failed` with the missing
  variable name; do not call the model.
- Optional variable absent → apply the template default (`../Prompts/variables.md`).

## AI Failure
- Provider error/empty/malformed output → retry up to the agent's max attempts.
- On persistent failure, try the configured **fallback provider/model** (provider abstraction) once,
  then escalate.

## Timeout
- Each agent has a per-call timeout (`performance.md`). On timeout → retry with backoff.
- Pipeline-level watchdog cancels a run that exceeds its total budget and escalates.

## Invalid Output
- Fails egress validation → attempt **one guided self-correction** (re-prompt with the specific
  failed rules), then, if still failing, mark `failed` and escalate.

## Wrong Language
- Output language ≠ requested `{{language}}` → reject, re-prompt once with an explicit language
  instruction; brand terms must remain untranslated.

## Wrong Platform
- Output shape/limits don't match `{{platform}}` → reject, re-prompt with platform constraints from
  `../Brand/social-media.json` / `video-style.json`.

## Recovery Flow

```
Detect error
   ↓
Classify (transient / deterministic / data / safety)
   ↓
Transient?  ── yes ──► retry w/ backoff ──► success? ─ yes ─► continue
   │ no                                   └─ no ─► fallback provider ─► escalate
   ↓
Deterministic? ─► guided self-correct (1x) ─► pass? ─ yes ─► continue
   │                                          └─ no ─► escalate
   ↓
Data / Safety ─► halt step ─► human review (escalation)
```

## Escalation
- Escalation creates a **review task** with full context, logs, failed rules and reviewer notes
  fields.
- Dependent steps are halted; completed artifacts are preserved for reuse on resume.
- Escalations are logged (`logging.md`) and surfaced to the Dashboard.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
