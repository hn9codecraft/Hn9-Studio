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
7. **A unit has no review status of its own.** Review and selection live on Unit Versions
   (M11.18.5). The unit stores only which version is selected, if any.
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
- Production Plans keep their own `active` / `superseded` status. A generation job has its own
  status (M11.18.3). Review status lives on the Unit Version (M11.18.5).

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

## Generation Engine (M11.18.3)

One Generation Unit is generated at a time. A scene is never sent to a provider as one job.

```
Production Plan → Scene → Generation Unit → Generation job (one attempt)
    → Provider execution contract → Validated output on the videos disk
```

`StoryProductionUnitGenerationService` builds one normalized request from server-side data.
`StoryVideoProviderOrchestrator` chooses one provider before the job is created. The existing
video engine (`StoryVideoEngine`, `StoryVideoJobRunner`, and that provider's adapter) then
submits it. Joining the selected clips into one scene video belongs to M11.18.6. A successful
validated output is recorded as a Unit Version by M11.18.5; the generation service does not
review or select it.

### Unit and attempt

A Generation Unit is the stable slot from M11.18.1: at most `StoryGenerationUnitCalculator::UNIT_SECONDS`
(10) seconds, with a shorter remainder allowed. A generation job is one attempt to fill that slot.
The same unit can have many jobs. A job is not a unit, and a successful job is not the selected
unit version.

The unit's stored `duration_seconds` is the length requested from the provider. A 10-second unit
asks for 10 seconds; a 7-second remainder asks for 7. The engine does not round 7 up to 10, does
not ask for 30 seconds, and does not create another unit when the connected provider cannot make
that exact length. It returns `VIDEO_CAPABILITY_NOT_AVAILABLE` and makes no provider call.
`StoryVideoUnitPlanner` still chunks the older scene-level generate endpoint; the unit engine
does not use it.

### Provider orchestration (M11.18.4)

Unit generation asks the orchestrator once, and only when no job exists for that unit and intent.
A status poll, a refresh, an HTTP retry and a worker retry do not route again.

```
Generation Unit → Generation request → Provider Orchestrator
    → Candidate evaluation → Routing decision → One provider and model
    → Generation engine → That provider's adapter
```

The decision is capability-first. A provider is eligible only when it is enabled, configured,
not Gemini (`video.live` is left on the older scene engine and is omitted from unit routing),
supports the mode (`text_to_video`, `image_to_video` or `reference_to_video`), supports the
unit's exact duration, supports the aspect ratio and input types, has an enabled matching model,
passes the existing circuit breaker, and accepts `validate()` before any submit. Runway, Luma
and Seedance 2.0 are the production providers. Their duration tables stay in the adapters:
Runway 2–10 seconds, Luma 5 and 10 for text-to-video (image-to-video and extend are 5 seconds),
Seedance 4–15 seconds (or 30 for a 2.5 model). A 7-second unit cannot use Luma. A reference
video can use Seedance. The unit row is never changed to fit a
provider, and FFmpeg trimming is not a substitute.

Policy lives in `story_video.routing`, not in provider `if` branches. `preferred_order`
defaults to `video.runway`, `video.luma`, `video.seedance` (`STORY_VIDEO_PREFERRED_PROVIDERS`).
Among eligible providers the order is preferred rank, then configured priority descending, then
provider key. There is no quality score and no random or model-based choice. Cost and latency
are not ranked because this build does not have a reliable per-video cost or latency series.
`fallback_enabled` (`STORY_VIDEO_ROUTING_FALLBACK`, default true) allows the next eligible
provider only before a job is submitted. When it is false, only the first preferred provider
may be chosen.

Fallback is a routing-time choice: not configured, disabled, missing capability, unsupported
duration or ratio, missing model, open circuit, or `validate()` rejecting the request before
`submit()`. Once a provider returns an operation id, that attempt stays with that provider even
if it later fails. A claimed submit with no operation id is not sent again and is not moved to
another provider. A different provider requires a new intent. One intent is one decision, one
provider, one attempt and one job.

The snapshot is stored on the job as `provider_metadata.routing_decision` and copied to
`routing`: policy version (`m11.18.4`), capability, requested duration, selected provider,
selected model, reason, the candidates that were considered, and `selected_at`. It has no
credentials. The public unit generation response does not include it. The older scene generate
endpoint still uses `StoryVideoDispatchService` and highest-priority live adapter selection;
that path is not the unit orchestrator.

Polling, callback and synchronous adapters still return a normalized status. The engine updates
the HN9 job. Adapters are not rewritten for routing.

### Snapshot and continuity

On submit, `request_payload.metadata.context` stores the inputs that were actually used: unit,
scene text, plan, source version, story, style, characters, mode, aspect ratio, instruction,
requested duration, the previous scene, and the previous unit when this is not the first unit.
Later edits to the story do not change that snapshot.

Unit 1 has no previous unit. Unit 2 and later record the previous unit's selected, approved
version when that file is still on the videos disk. A completed attempt that was not selected is
not used. If no selected version is available, the snapshot says the previous output is
unavailable and generation still proceeds. Unrelated project media is not used as continuity.

### Job lifecycle

Jobs use the existing statuses: `queued`, `submitted`, `processing`, `completed`, `failed`,
`cancelled`. `timed_out` stays a boolean. There is no review status on the job. "Submitting" is
the move from `queued` to `submitted`.

The HTTP request creates or reuses the job and submits it. It does not wait for the provider to
finish. When `story_video.queue.enabled` is on, the worker continues the job. When it is off, a
status read advances one step. A submit that was claimed (`started_at` set) but never stored an
operation id is failed by the existing recovery path and is not sent again. `story:recover-video-jobs`
polls stored operations and does not submit.

### Idempotency

The key is `production-unit:{unit uuid}:{intent}`. The intent defaults to `initial`. The same
intent, including a double click, HTTP retry or worker retry, reuses that job. A new intent
string (`^[A-Za-z0-9_-]{1,64}$`) is an intentional new attempt and does not replace the unit or
the earlier job. The unique key is `(story_workspace_id, idempotency_key)`. A concurrent insert
that loses the race returns the job that won.

### Output

A completed provider file is accepted only when it is on the `videos` disk, is a non-empty
`video/*` file, and its duration is within `StoryProductionUnitGenerationService::DURATION_TOLERANCE_SECONDS`
(0.5 seconds) of the unit. That tolerance is container timing drift, not permission to accept a
different length. A missing file, a bad type, an empty file, a download failure, a storage
failure, or a length outside that tolerance fails the job. The unit row is not rewritten. No
placeholder media is stored, and a provider download URL is not the asset identity.

### History and responses

Activity actions are `story.unit_generation.requested`, `submitted`, `completed`, `failed` and
`retried`, once per job and action. Project History lists these jobs as `unit_generation` with
the label `Unit N`. The generation API returns the job uuid, unit uuid, capability, status,
output availability, timeout flag, sanitized error and timestamps. It does not return provider
keys, model keys, operation ids, credentials or raw provider bodies.

## Unit Versions (M11.18.5)

A Unit Version is one successful candidate for a Generation Unit. It is not a generation attempt,
and it is not a new unit.

```
Generation Unit (stable slot)
    → Generation Attempt / job
    → Successful validated output
    → Unit Version A, B, C …
    → Review
    → Approved version
    → One selected version
```

Failed, cancelled and in-progress attempts never become versions. The server creates a version
only inside output acceptance, after the existing videos-disk checks and the 0.5-second duration
tolerance. There is no API that turns an arbitrary file into a version. The same job cannot
create a second version.

### Numbering and selection

Version letters come from `StoryAudioService::versionLabel`. The first successful output for a
unit is Version A, the next is Version B, and so on. The unit row is locked while the number is
assigned. Unique keys are `(unit, version number)` and one version per generation job.

Review uses the existing statuses: `pending_review` (“Ready for review”), `approved`
(“Approved”) and `needs_rework` (“Changes requested”). Approving a version does not select it.
The unit’s `selected_version_id` is the only selection. It may point at one approved version
whose file still exists, or at nothing. Selecting another approved version leaves the earlier
versions and their files in place.

Requesting changes does not edit the version and does not start a new attempt. A later
generation intent, through the existing unit engine, creates the next version on success.

### Traceability

Each version keeps the job it came from and a snapshot of the provider, model, capability,
requested duration, produced duration and the routing decision (policy version, reason, selected
provider and model). It does not store credentials, operation ids or provider URLs. The permanent
file is the path on the `videos` disk.

`StoryProductionUnitVersionService::assemblySource` answers, for one unit, whether a selected
approved file is ready for a later scene assembly: version, sequence, start, unit duration,
output duration and storage path. `continuityOutput` is what the next unit may use. Neither
reads the latest job.

`assemblySource` is the only input M11.18.6 may use. It does not read the latest job or the
newest file.

### What this sprint does not do

Creative Studio’s scene workspace is M11.18.7. End-to-end provider QA is M11.18.8.
Provider choice stays in M11.18.4.

## Scene Assembly (M11.18.6)

A scene video is the ordered join of that scene’s selected unit versions. It is not a generation
job and it is not a story-script version (`StorySceneVersion`). The final movie
(`StoryFinalRender`) still joins scenes on the timeline; this step only produces one scene file.

```
Scene
    → Generation Units, in persisted sequence
    → selected_version_id
    → assemblySource()
    → Scene Assembly job
    → FFmpeg (StoryMediaToolkit)
    → Validated Scene Assembly Version
```

Scene length stays the story length. Unit length stays the production slot (10 seconds, or a
shorter remainder). A 47-second scene is 10 + 10 + 10 + 10 + 7. The provider file must already
be within 0.5 seconds of its unit. The finished file must be within 0.5 seconds of the scene.
FFmpeg does not rewrite either contract.

### Inputs

Every unit must have one selected, approved, readable video on the `videos` disk. If any unit
is missing, unapproved, needs changes, empty, or stored outside that disk, assembly stops before
FFmpeg with “Some video parts are not ready yet.” There is no partial scene video. Order is the
unit sequence from the production plan.

A clip whose measured length is outside the 0.5-second tolerance, or whose picture shape does
not match the story bible aspect ratio, fails before the encode. The toolkit scales and pads
without stretching, uses the project frame rate, and fills a missing audio track with silence so
the scene file always has video and audio. Unit joins use a hard cut, so the durations add.
Dissolves stay on the later timeline render. They are not invented here.

### Versions and jobs

`story_scene_assemblies` is the job and, once it succeeds, the historical scene-assembly
version. The same scene, plan revision, selected versions, aspect ratio and cut produce one
idempotency key. A repeat reuses the in-progress or completed row. A different selection is a
new key and, on success, the next version letter. Older files stay. A failed build can be tried
again on the same row.

The row stores a snapshot of the scene, plan revision, and each unit’s version and storage
reference. Later changes to `selected_version_id` do not rewrite a finished assembly. The public
API and history do not return paths, commands or FFmpeg output.

A completed assembly is placed on that scene’s reel timeline. The clip stores the assembly
file and the measured length (the scene length if the file cannot be probed). A later assembly
for the same scene replaces that clip and leaves the earlier file on disk. Repeating the same
assembly does not reset a trim. The public timeline payload does not include the disk or path.
`StoryFinalRender` still reads the clip’s stored file, so the final movie uses the scene video
rather than the individual unit files.

When `story_video.queue.enabled` is on, `ProcessStorySceneAssembly` runs the build. Otherwise
the request runs it inline, the same way local renders do. `story:recover-video-jobs` marks a
queued or processing assembly as failed after the FFmpeg timeout and deletes a partial file. It
does not start a second encode.

Output is stored on the `videos` disk at `assemblies/{uuid}.mp4` through the storage disk, after
FFprobe accepts the file. Assembly does not change units, versions, generation jobs or routing.

### What this sprint does not do

The production screen is M11.18.7. End-to-end provider QA is M11.18.8. This step does
not call a video provider and does not build the final movie.

## Scene production screen (M11.18.7)

Creative Studio → Scenes is the production workflow. A scene opens into one workspace. The
screen does not split a scene into clips and does not choose a provider.

```
Scene
    → Production clips (the plan’s units)
    → Version review
    → Selection
    → Scene assembly
    → Scene preview
```

The scene card reads the plan’s production summary. The open scene reads
`GET …/scenes/{scene}/production`, which returns the plan’s clips, their versions and the scene
videos. Generate, approve, request changes, select and build call the existing unit and assembly
routes. Active generation and an in-progress build are polled until they finish, then the
workspace is loaded again. Provider names, operation ids and file paths are not shown.

_Diagrams and component details are placeholders — expand as the system is built._
