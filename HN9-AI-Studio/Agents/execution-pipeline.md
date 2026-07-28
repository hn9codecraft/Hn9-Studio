# Execution Pipeline

The complete end-to-end workflow from user request to analytics. Each box is an agent job; arrows
are event-driven handoffs carrying the **shared context** (`communication-protocol.md`).

## Full pipeline

```
User Request
   ↓
Planner Agent            → builds task graph, sets deliverableType/platform/language
   ↓
Research Agent           → gathers facts, trends, references
   ↓
Strategy Agent           → angle, hook, funnel goal, CTA selection
   ↓
SEO Agent                → keywords, titles, meta, slugs, headings
   ↓
Script Agent             → written content / script (routes to Blog/Social specialists)
   ↓
Storyboard Agent         → scene-by-scene visual plan (media deliverables only)
   ↓
┌─────────────── parallel fan-out ───────────────┐
Image Prompt Agent   Video Prompt Agent   Voice Agent   Caption Agent   Thumbnail Agent
└─────────────── fan-in to Review ───────────────┘
   ↓
Review Agent             → brand + editorial review, consolidates bundle
   ↓
QA Agent                 → automated validation gates (validation.md)
   ↓
[ Human Approval Gate ]  → required before publishing
   ↓
Publisher Agent          → formats + schedules per platform, writes to /Output
   ↓
Analytics Agent          → records performance, feeds back to Strategy
```

## Stage descriptions

| Stage | Produces | Notes |
|-------|----------|-------|
| Planner | Task graph + request metadata | Entry point; sets all flags. |
| Research | Facts, sources, trend notes | No fabrication; cite sources. |
| Strategy | Angle, hook, funnel goal, CTA id | Reads `../Brand/cta.json`, `audience.json`. |
| SEO | Keywords, meta, slug, headings | Uses `seo.md` template + `../Brand/seo.json`. |
| Script | Copy/script in `{{language}}` | Blog/Social/Sales specialists as needed. |
| Storyboard | Scenes, shots, on-screen text | Media deliverables only. |
| Image Prompt | Image-gen prompts | Parallel. |
| Video Prompt | Video-gen prompts | Parallel. |
| Voice | VO script + TTS settings | Parallel. |
| Caption | Platform caption | Parallel. |
| Thumbnail | Thumbnail image prompt | Parallel. |
| Review | Consolidated, brand-checked bundle | Fan-in. |
| QA | Pass/fail against validation pipeline | Blocks on failure. |
| Human Approval | Sign-off | Mandatory gate. |
| Publisher | Scheduled/published assets | Writes to `../Output`. |
| Analytics | Metrics + learnings | Loops back to Strategy. |

## Feedback loop

Analytics Agent stores performance in **Project Memory** (`memory.md`). The Strategy Agent reads it
on the next request to improve hooks, angles and posting decisions — a closed learning loop.

## Idempotency & state

Every job carries `requestId`, `stepId`, `version`. Re-running a completed step returns the cached
result (`performance.md`) unless `forceRefresh` is set. This makes the pipeline safe under retries
and queue redelivery.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
