# QA Agent

## Purpose
Run the full automated validation pipeline against the consolidated bundle and produce a pass/fail
gate report before human approval.

## Responsibilities
- Execute all applicable validation gates (`validation.md`).
- Produce a structured QA report with per-rule results.
- Block progression on any failure; route failures to correction/escalation.

## Inputs
Consolidated bundle from Review Agent.

## Outputs
`qaReport`: per-gate results, overall verdict, blocking issues.

## Reads From
`context.bundle`.

## Writes To
Runtime Context (`context.qa`); emits `QAPassed` or `QAFailed`.

## Brand Files Required
`../Brand/content-rules.json`, `../Brand/ai-rules.json`, `../Brand/seo.json`,
`../Brand/social-media.json`, `../Brand/video-style.json`.

## Prompt Templates Required
None; enforces `validation.md`.

## Dependencies
Review Agent.

## Decision Rules
- Run only gates relevant to the deliverable type.
- Any `passed=false` → overall `fail`.
- Safety/brand failures are non-retryable → escalate.

## Validation Rules
- Full pipeline: Contract → Brand → Content → Grammar → Platform → SEO → Media → Prompt.

## Retry Rules
No blind retry. On fixable fail → route to owning agent for one correction, then re-QA.

## Failure Conditions
Any blocking gate fails after correction attempt.

## Recovery Strategy
Targeted regeneration; if still failing → escalate to human with the QA report.

## Escalation Rules
Escalate on repeated or safety-critical failures; never auto-publish a failing asset.

## Performance Requirements
p95 < 6s.

## Logging Requirements
Log every gate result, blocking issues, verdict, version.

## Security Considerations
Final automated check for injection artifacts, banned content, brand tampering.

## Example Input
```
{ "bundle": {...}, "deliverableType":"reel" }
```

## Example Output
```
{ "qaReport": { "gates":[ {"rule":"brand","passed":true}, {"rule":"platform","passed":true} ],
  "verdict":"pass" }, "status":"awaiting_human_approval" }
```

## Next Agent
**Human Approval Gate → Publisher Agent**
