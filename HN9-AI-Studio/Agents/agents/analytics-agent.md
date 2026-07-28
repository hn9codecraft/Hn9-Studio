# Analytics Agent

## Purpose
Close the loop: collect performance metrics for published content and turn them into learnings that
improve future strategy.

## Responsibilities
- Ingest platform metrics (reach, engagement, clicks, conversions) post-publish.
- Attribute performance to content decisions (angle, hook, CTA, format, time).
- Write learnings to Persistent Context for the Strategy Agent.

## Inputs
Publish result + post refs; platform analytics (via integrations); time window.

## Outputs
`analytics`: metrics per asset + learnings/recommendations.

## Reads From
`context.publish`; platform analytics sources; Persistent Context.

## Writes To
Persistent Context / Project Memory; `../Output` (reports); emits `AnalyticsRecorded`.

## Brand Files Required
`../Brand/audience.json`, `../Brand/cta.json` (attribute CTA performance).

## Prompt Templates Required
None (analysis). May feed a future `report-agent`.

## Dependencies
Publisher Agent. Runs after a performance window elapses (scheduled/queued).

## Decision Rules
- Aggregate by platform, format, angle, CTA, language.
- Flag over/under-performers; recommend next-best actions.

## Validation Rules
- Metrics tied to correct `requestId`/post ref; no fabricated numbers (report gaps honestly).

## Retry Rules
Transient analytics API error → 3 retries, backoff. Missing data → retry after next window.

## Failure Conditions
Analytics source unavailable; unattributable metrics.

## Recovery Strategy
Retry on schedule; store partial metrics; flag data gaps.

## Escalation Rules
Escalate persistent integration outages to ops.

## Performance Requirements
Batch/scheduled; not latency-critical.

## Logging Requirements
Log metrics collected, learnings written, data gaps, version.

## Security Considerations
Read-only analytics scopes; no PII stored; credentials via secret manager.

## Example Input
```
{ "postRef":"ig_1234","window":"7d" }
```

## Example Output
```
{ "analytics": { "reach":12000,"engagementRate":0.061,"clicks":320,
  "learnings":["'time saved' hook outperformed feature-led by 40%"] } }
```

## Next Agent
Feedback loop → **Strategy Agent** (next request) / end of pipeline
