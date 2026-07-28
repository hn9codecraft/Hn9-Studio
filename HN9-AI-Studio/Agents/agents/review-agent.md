# Review Agent

## Purpose
Fan-in agent. Consolidates all parallel outputs into one coherent deliverable bundle and performs an
editorial + brand review before automated QA.

## Responsibilities
- Assemble script, media prompts, voice, caption, hashtags, thumbnail into one bundle.
- Check cross-asset consistency (message, tone, language, CTA alignment).
- Perform an editorial pass; flag issues for correction.

## Inputs
All parallel agent outputs for the request.

## Outputs
`bundle` (consolidated deliverable) + review notes + consistency verdict.

## Reads From
`context.script`, `context.storyboard`, `context.images`, `context.videos`, `context.voice`,
`context.caption`, `context.thumbnail`, `context.social`, `context.blog`.

## Writes To
Runtime Context (`context.bundle`); emits `ReviewComplete`.

## Brand Files Required
`../Brand/tone.json`, `../Brand/cta.json`, `../Brand/content-rules.json`, `../Brand/ai-rules.json`.

## Prompt Templates Required
None (review logic); references rules across `../Brand`.

## Dependencies
Fan-in from all dispatched parallel agents (barrier).

## Decision Rules
- All assets share one language, one message, one primary CTA.
- If an asset is inconsistent/off-brand → route back to its owning agent (max 2 cycles).

## Validation Rules
- Cross-asset consistency; brand + content compliance; single primary CTA.

## Retry Rules
No model retry; instead routes specific assets back for regeneration (bounded loop).

## Failure Conditions
Irreconcilable inconsistency; repeated off-brand assets.

## Recovery Strategy
Targeted regeneration of the offending asset; escalate after 2 cycles.

## Escalation Rules
Escalate to human when consistency can't be achieved automatically.

## Performance Requirements
p95 < 8s.

## Logging Requirements
Log assets reviewed, issues found, reroutes, verdict, version.

## Security Considerations
Confirm no injected/off-brand content slipped through any asset.

## Example Input
```
{ "script":{...},"images":[...],"voice":{...},"caption":{...},"thumbnail":{...} }
```

## Example Output
```
{ "bundle": {...}, "consistency":"pass", "notes":[], "status":"ready_for_qa" }
```

## Next Agent
**QA Agent**
