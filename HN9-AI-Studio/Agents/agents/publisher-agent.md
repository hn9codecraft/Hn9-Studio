# Publisher Agent

## Purpose
Format and deliver the approved bundle to its destination(s): write to `../Output` and
schedule/publish per platform. Runs only after human approval.

## Responsibilities
- Format assets to each platform's publishing spec.
- Write final artifacts to `../Output/*`.
- Schedule or publish (or hand to a scheduling integration); record post ids/links.

## Inputs
Approved bundle; platform(s); schedule; approval token.

## Outputs
`publishResult`: destinations, statuses, scheduled times, artifact paths, post refs.

## Reads From
`context.bundle`, `context.qa`, approval event.

## Writes To
`../Output/{Images|Videos|Captions|Blog|Social Posts}`; Runtime Context (`context.publish`); emits
`Published`.

## Brand Files Required
`../Brand/social-media.json` (platform specs), `../Brand/company.json` (profile handles).

## Prompt Templates Required
None (delivery/formatting).

## Dependencies
QA Agent + Human Approval (mandatory gate).

## Decision Rules
- Publish only if `approved=true` and QA `verdict=pass`.
- Format per platform; respect scheduled time; idempotent publish (dedupe by requestId).

## Validation Rules
- Approval token valid; assets match platform spec; output paths correct.

## Retry Rules
Transient publish/API error → 3 retries, backoff. Duplicate detection prevents double-post.

## Failure Conditions
Missing approval; platform API failure; malformed asset.

## Recovery Strategy
Retry publish; on persistent failure, save to `../Output` as ready-to-publish + escalate.

## Escalation Rules
Escalate on repeated publish failures or missing approval.

## Performance Requirements
p95 < 8s (excl. external API latency).

## Logging Requirements
Log destination, schedule, post refs, approval id, version (audit-retained).

## Security Considerations
Publishing credentials via secret manager; never log tokens; verify approval authenticity.

## Example Input
```
{ "approved": true, "bundle": {...}, "platform":"instagram", "schedule":"2026-07-19T11:00:00Z" }
```

## Example Output
```
{ "publishResult": { "platform":"instagram","status":"scheduled",
  "scheduledFor":"2026-07-19T11:00:00Z","artifact":"/Output/Social Posts/req_2026_0001.json" } }
```

## Next Agent
**Analytics Agent**
