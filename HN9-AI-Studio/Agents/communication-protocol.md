# Communication Protocol

All agents communicate through **typed JSON contracts** and a **shared context** object. This
document defines those standards so any agent is independently replaceable.

## Input Contract

Every agent job receives:

```
{
  "requestId": "req_2026_0001",        // stable id for the whole pipeline
  "stepId": "step-05",                 // this step
  "agent": "script-agent",
  "language": "en",                    // en | hi | gu
  "platform": "instagram",             // target channel
  "deliverableType": "reel",           // maps to a Prompt Engine template
  "goal": "lead",                      // funnel goal
  "input": { ... },                    // agent-specific payload
  "contextRef": "ctx://req_2026_0001", // pointer to shared context
  "brandRef": "../Brand",              // read-only source of truth
  "promptRef": "../Prompts",           // read-only source of truth
  "versions": { "agent": "1.0.0", "prompt": "1.0.0", "workflow": "1.0.0" }
}
```

## Output Contract

Every agent job returns:

```
{
  "requestId": "req_2026_0001",
  "stepId": "step-05",
  "agent": "script-agent",
  "status": "completed",               // completed | failed | skipped | needs_review
  "output": { ... },                   // agent-specific result
  "artifacts": ["script.json"],        // files written (relative to /Output or context)
  "validations": [ { "rule": "brand", "passed": true }, ... ],
  "warnings": [],
  "metrics": { "durationMs": 1234, "tokensIn": 900, "tokensOut": 700, "retries": 0 },
  "versions": { "agent": "1.0.0", "prompt": "1.0.0" },
  "timestamp": "<iso8601>"
}
```

## Shared Context

- A single per-request object (`ctx://<requestId>`) stored in the memory layer (`memory.md`).
- **Append-only** during a run: each agent adds a namespaced section (e.g. `context.script`).
- Read scope: an agent reads only the sections it declares in "Reads From".
- Contains request metadata, resolved runtime variables, and each completed agent's output.

## Runtime Variables

- Runtime variables (`{{topic}}`, `{{goal}}`, `{{platform}}`, `{{language}}`, `{{duration}}`, …)
  are defined in `../Prompts/variables.md` and passed via `input`.
- **Brand variables** (`{{company_name}}`, `{{cta}}`, `{{primary_color}}`, …) are **resolved from
  `../Brand`**, never passed literally or duplicated.

## JSON Standards

- UTF-8, valid JSON, no comments (comments only in Markdown).
- Enums are lowercase strings; timestamps are ISO-8601 UTC.
- Money/date/locale values are explicit; no ambiguous formats.
- Unknown fields are ignored (forward-compatible), missing required fields = contract error.

## Naming Standards

- `snake_case` for variables, `kebab-case` for agent ids and files, `camelCase` for JSON keys.
- Agent id = file name without `.md` (e.g. `script-agent`).
- Events: `PascalCase` past-tense (`ScriptGenerated`, `Published`).

## Validation Standards

- Each contract is validated on **ingress** (input) and **egress** (output).
- Egress validation runs the relevant checks from `validation.md`.
- A failed egress validation sets `status = failed` (or `needs_review`) and blocks handoff.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
