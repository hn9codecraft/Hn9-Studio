# Architecture — HN9 AI Studio

## High-Level Overview
HN9 AI Studio is organized as a **content supply chain** driven by a fleet of single-responsibility
AI agents, fed by a central brand source of truth, and orchestrated by automation workflows.

```
Brand (source of truth)
        │
        ▼
Prompts ──► Agents ──► Workflows ──► Output
   ▲           │            │           │
Templates   Characters   Assets     Publishing
```

## Layers

| Layer | Folder(s) | Responsibility |
|-------|-----------|----------------|
| Identity | `Brand`, `Characters`, `Logos` | Who HN9 is and how it looks/sounds. |
| Knowledge | `Prompts`, `Templates`, `Scripts` | Reusable instructions and structures. |
| Intelligence | `Agents` | Single-responsibility AI units. |
| Orchestration | `Workflows` | Chaining agents and tools end-to-end. |
| Media | `Images`, `Videos`, `Voice`, `Assets` | Raw and generated media. |
| Delivery | `Output` | Publish-ready deliverables. |
| Application | `Backend`, `Frontend`, `Dashboard` | Software surfaces. |

## Design Principles
- **Single source of truth** — brand data lives once, in `/Brand`.
- **Single-responsibility agents** — each agent does one thing well.
- **Composable workflows** — agents are chained, not monolithic.
- **Separation of source and output** — `/Assets` (input) vs `/Output` (delivered).
- **Scalable by convention** — new agents/channels follow existing folder patterns.

## Production Plan (M11.18)

A Production Plan turns an approved story into the work a video provider can actually do.

```
Story Plan Version ──► Production Plan ──► Plan Scenes ──► Generation Units (≤ 10 s each)
                         (revision 1, 2, 3 … per story plan)
```

### Rules
1. **Scene duration is flexible.** A scene can be any whole number of seconds from 1 to 3600
   (`StorySceneTimingNormalizer::MIN_SECONDS` / `MAX_SECONDS`). There is no 30-second scene rule;
   the planner's 30-second pacing (`StoryPlanDurationCalculator::SCENE_TARGET_SECONDS`) only
   decides how many scenes to suggest.
2. **10 seconds is the generation-unit target and maximum.** It is defined once, in
   `StoryGenerationUnitCalculator::UNIT_SECONDS`, and stored on each plan as `unit_seconds`.
3. **The final unit may be shorter than 10 seconds.** A 47-second scene is 10 + 10 + 10 + 10 + 7.
   Units start at 0, are contiguous with no gaps or overlaps, and always add up to the scene.
4. **A scene remains one logical scene.** Units never appear to the user as separate scenes;
   they exist so the scene can be produced and later assembled back together.
5. **Units are internal production slots.** A unit records its position (`sequence`,
   `start_second`, `duration_seconds`), not any media.
6. **Unit identity persists across regeneration.** A unit row is never replaced when its clip is
   regenerated; every attempt will attach to the same unit.
7. **Unit Versions are handled later** (M11.18.5). Generation and review status will live on unit
   versions, which is why units themselves carry no status.
8. **FFmpeg assembly happens later** (M11.18.6). It will join a scene's selected unit versions in
   `sequence` order using the stored offsets.
9. **Production Plans are versioned and historically preserved.** Changing the story creates a
   new revision linked to the previous one (`previous_plan_id`); earlier revisions are marked
   `superseded` and kept. Exactly one plan per story plan is current. A project can hold several
   story plans (for example Part 1 and Part 2), each with its own plan history.
10. **Provider output duration does not define HN9 scene duration.** Provider chunking
    (`StoryVideoUnitPlanner`) and provider clip lengths are delivery details; the scene's own
    duration and its 10-second units are the source of truth.

### Data integrity
- Plans, plan scenes and units are created in one transaction; if any step fails nothing is saved.
- Creating a plan twice for the same version returns the existing plan; concurrent requests are
  serialised by locking the story plan, with unique keys as the final guard.
- Story records a plan depends on (workspace, story plan, version, reel, scene, scene version)
  cannot be deleted while a plan references them. Projects are soft-deleted, so history survives.
- Deleting a plan removes only its own plan scenes and units.
- Plans are created by the backend only. The API exposes read-only, owner/admin-scoped endpoints
  (see [API](API.md#production-plans)).

## Story Approval Gate (M11.18.2)

Approval is the gate between story planning and video production. No Production Plan exists
for a version that has not been approved.

```
Story Plan Version (completed) ──► Ready for review ──► Approve ──► Scenes + Production Plan + Units
```

### States
Planning, review, Production Plan and generation status stay separate:
- `story_plan_versions.status` is the planner's (`generating`, `completed`, `failed`).
- Approval is recorded on the version as `approved_at` / `approved_by`. The review status shown
  to users (`in_progress`, `failed`, `ready_for_review`, `approved`) is derived from both and
  never stored.
- Production Plans keep their own `active` / `superseded` status; generation status will live on
  unit versions (M11.18.5).

### Rules
1. **One path.** `StoryPlanController::approve` → `StoryPlanApprovalService` →
   `StoryPlanMaterializer` + `StoryProductionPlanService`. The Production Plan service also
   refuses unapproved versions, so the gate holds even if another caller is added later.
2. **Exact version binding.** The plan is built from the approved version's own scenes
   (`source_plan_version_id`), never from the story's current state. `StoryGenerationUnitCalculator`
   remains the only place scenes are split into units.
3. **Eligibility.** The version must belong to the project's story, be `completed`, be the
   story's latest version, have scenes that pass materializer and Production Plan validation,
   and not have an archived reel. The owner or an admin approves; others get `403`, foreign
   identifiers `404`.
4. **Versions.** Approving version A creates Plan A. Approving a newer version B creates Plan B
   as the next revision; Plan A, its scenes and its units stay intact as `superseded`. An older
   version cannot be approved once a newer one exists (`409`). Approving an already-approved
   version again returns its latest plan, even if superseded, and creates nothing.

### Consistency and concurrency
- Approval, scene creation, the Production Plan, its scenes and units are written in one
  transaction. If any step fails, the version stays unapproved and nothing is saved.
- Approval, the materializer and the Production Plan service all lock the same story plan row,
  so concurrent approvals queue: the first creates everything, the next sees the approved
  version and reuses its plan. Unique keys (one reel per source version, one plan per story
  revision, one current plan per story, one successor per plan) remain the final guard, and
  deadlocks are retried.

### History
The project History shows, in plain language and without internal ids: story version ready for
review, story approved, production plan ready, production plan updated for a new version,
production plan already prepared (a repeated approval), and approval failed with its reason.
Failures are recorded after the rollback so they survive it; server logs keep only the driver
error, never SQL or bound values.

_Diagrams and component details are placeholders — expand as the system is built._
