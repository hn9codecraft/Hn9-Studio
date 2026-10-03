# API — HN9 AI Studio

> Placeholder specification for the backend API that orchestrates the content pipeline.

## Conventions
- Base URL: `/api/v1`
- Format: JSON request/response.
- Auth: Bearer token (to be defined).

## Planned Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/content/generate` | Kick off a content generation job. |
| `GET`  | `/content/{id}` | Retrieve a job's status and output. |
| `GET`  | `/agents` | List available agents. |
| `POST` | `/workflows/{id}/run` | Trigger a workflow. |
| `GET`  | `/brand` | Read the brand source of truth. |

## Story Approval

Approving a story plan version is the only way a Production Plan is created. See
[Architecture](Architecture.md#story-approval-gate-m11182) for the rules.

`POST /story/projects/{project}/plans/{plan}/versions/{version}/approve`

- **Auth:** Sanctum bearer token. The project owner or an admin may approve. Unauthenticated
  requests get `401`; another member gets `403`.
- **Input:** none. The request body is ignored: the approver, approval time and source version
  come from the session and the URL, and every identifier is checked server-side against the
  project. A plan or version from another project or story returns `404`, as do unknown or
  malformed identifiers.
- **Success:** `201 Created` when a Production Plan was created, `200 OK` when an existing one
  was returned.

```json
{
  "data": {
    "approved": true,
    "production_plan_created": true,
    "plan": { "id": "…", "current_version": { "review_status": "approved", "approved_at": "…" }, "production_plan": { "…": "…" } },
    "version": { "id": "…", "version": 1, "status": "completed", "review_status": "approved", "approved_at": "…" },
    "production_plan": { "id": "…", "revision": 1, "is_current": true, "unit_seconds": 10, "scene_count": 2, "unit_count": 6, "source_version": { "id": "…" }, "reel": { "id": "…" } }
  }
}
```

- **Already approved:** approving the same version again returns its latest Production Plan
  with `approved: false`, `production_plan_created: false` and `200`. Nothing is duplicated; a
  double click is safe.
- **Plan creation:** the version's scenes are created (or reused) from that exact version, then a
  Production Plan with 10-second Generation Units. If the story already has a plan from an
  earlier version, a new revision is created and the earlier plan is kept as `superseded`.

| Status | `error_code` | When |
|--------|--------------|------|
| `409` | `story_plan_version_not_latest` | A newer version of the story exists. |
| `422` | `story_plan_version_unfinished` | The version is still being written. |
| `422` | `story_plan_version_failed` | The version failed to generate. |
| `422` | `story_approval_invalid_plan` | Scenes are missing required fields or have invalid lengths. |
| `422` | `story_production_source_not_ready` | The version's reel is archived or has no scenes. |
| `422` | `story_production_invalid_scene` / `story_production_invalid_duration` | A scene fails Production Plan validation. |
| `500` | `story_approval_failed` | Something failed while saving. Everything was rolled back. |

Errors carry a plain `message` and never include SQL, stack traces or internal ids. Every failed
approval is listed in the project History.

`GET /story/projects/{project}/plans` and `GET …/plans/{plan}` include
`current_version.review_status` (`in_progress`, `failed`, `ready_for_review`, `approved`),
`current_version.approved_at`, and `production_plan` (the story's current Production Plan or
`null`). `GET /story/projects/{project}/history` lists `story_plan` events (`ready_for_review`,
`approved`, `approval_failed`) and `production_plan` events (`created`, `revised`, `reused`).

## Production Plans

Read-only. Only the project owner or an admin can read them; identifiers from another project
return `404`. Plans are created only by [story approval](#story-approval), not through these
endpoints. See [Architecture](Architecture.md#production-plan-m1118) for the rules.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/story/projects/{project}/production-plans` | Every plan revision for the project, newest first, with scene and unit counts. |
| `GET` | `/story/projects/{project}/production-plans/{plan}` | One plan with its scenes and 10-second units. |
| `GET` | `/story/projects/{project}/production-plans/{plan}/scenes/{scene}` | One scene of a plan with its units. `{scene}` is the story scene id. |

Each unit is returned as `{ id, sequence, start_second, duration_seconds, end_second, kind }`,
where `kind` is `standard` (a full 10 seconds) or `remainder` (the shorter final unit).

## Generation Units

Generates one Generation Unit. The scene is not generated as one job, and these endpoints do not
assemble the scene. A successful validated output is recorded as a Unit Version by the server;
review and selection use the [unit version](#unit-versions) endpoints. The server chooses one
video provider. See
[Architecture](Architecture.md#generation-engine-m11183).

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/story/projects/{project}/production-plans/{plan}/units/{unit}/generate` | Start or reuse one generation attempt. |
| `GET` | `…/units/{unit}/generations` | Every attempt for that unit, oldest first. |
| `GET` | `…/units/{unit}/generations/{job}` | Current state. This read can advance a job that is not on the queue. |
| `POST` | `…/units/{unit}/generations/{job}/cancel` | Cancel an in-flight attempt. |

- **Auth:** Sanctum bearer token. The project owner or an admin. Unauthenticated requests get
  `401`; another member gets `403`. A unit or plan from another project, or an unknown or
  malformed id, returns `404`.
- **POST body:** `capability` is required and is one of `text_to_video`, `image_to_video`,
  `reference_to_video`. Optional: `intent` (1–64 letters, numbers, `_` or `-`; omitted means
  `initial`), `instruction` (max 500 characters), `aspect_ratio`, and `inputs` of
`{ type, asset_id }`. `duration_seconds`, prompts and any `provider` field are ignored. The
server chooses the provider. Duration, scene text, characters, style and continuity are loaded
on the server from the unit.
- **Idempotency:** the same unit and intent returns the existing job with `created: false` and
  `200`. A new intent creates another attempt with `201`. Repeating a request does not submit a
  second provider job.
- **Success body:**

```json
{
  "data": {
    "created": true,
    "generation": {
      "id": "…",
      "unit_id": "…",
      "capability": "text_to_video",
      "status": "submitted",
      "output_available": false,
      "timed_out": false,
      "error_code": null,
      "error_message": null,
      "created_at": "…",
      "updated_at": "…"
    }
  }
}
```

`output_available` is true only after the file is stored on the videos disk and its length matches
the unit (within 0.5 seconds). The list endpoint returns those generation objects in `data`.

| Status | `error_code` | When |
|--------|--------------|------|
| `422` | `INVALID_INPUT` | The mode, intent, scene text, approval or reference is not valid. A picture shape no connected service supports says "The picture shape is not supported." A reference the connected services cannot use says "This video setup can't use the selected reference." |
| `422` | `VIDEO_CAPABILITY_NOT_AVAILABLE` | No eligible provider can make this request. An unsupported clip length says "None of the connected video services supports this clip length." Otherwise the message is "Video generation is not available for this scene right now." Nothing is submitted. |
| `422` | provider code such as `UPSTREAM_ERROR` | The chosen provider rejected the submit. The job stays with that provider and is `failed`. |
| `501` | `GENERATION_NOT_ENABLED` | No video service is connected. The message is "Video generation is not configured yet." No job is created. |

Image and reference inputs must already belong to the same project. A reference from another
project is `422` `INVALID_INPUT` and is not sent to a provider. Errors are a plain `message` and
`error_code`. Responses do not include provider keys, operation ids, credentials, raw provider
bodies, SQL or stack traces.

`GET /story/projects/{project}/history` includes these attempts as `kind: unit_generation` with
`version_label` `Unit N`.

Each unit on a plan scene also includes `selected_version_id` (a version uuid, or `null`).

## Unit Versions

Review and selection for one Generation Unit. These endpoints do not generate video, do not
choose a provider, and do not assemble a scene. A version exists only after a generation attempt
for that unit stored a valid video. See [Architecture](Architecture.md#unit-versions-m11185).

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `…/units/{unit}/versions` | Versions for that unit, Version A first. |
| `GET` | `…/units/{unit}/versions/{version}` | One version. |
| `GET` | `…/units/{unit}/versions/{version}/file` | The stored video, when the file is still present. |
| `POST` | `…/units/{unit}/versions/{version}/approve` | Approve a video that is ready for review. Does not select it. |
| `POST` | `…/units/{unit}/versions/{version}/request-changes` | Ask for changes. The version stays. A new version requires a new generation attempt. |
| `POST` | `…/units/{unit}/versions/{version}/select` | Use this approved video for the final scene. Any previously selected version stays approved and is no longer selected. |

- **Auth:** same as generation. Owner or admin. `401` unauthenticated, `403` another member,
  `404` for a unit, version or plan that is not in this project.
- **Bodies:** approve accepts an optional `comment` (max 2000). Request changes requires
  `comment`. Select has no body. `project_id`, `generation_unit_id`, `output_asset_id`,
  `job_id`, `provider` and `approved_by` are ignored.
- **Idempotency:** approving an approved version, requesting changes on a version that already
  needs changes, and selecting the version that is already selected each succeed and do not add
  another history entry.
- **List body:** `message` is set only when there are no versions: “No video versions yet.”,
  “Your video is still being generated.”, or “No video version was created. The generation
  failed.” Otherwise `message` is `null`.

```json
{
  "data": {
    "message": null,
    "versions": [
      {
        "id": "…",
        "unit_id": "…",
        "version": "A",
        "label": "Version A",
        "status": "approved",
        "status_label": "Approved",
        "selected": true,
        "selected_label": "Selected for the final scene",
        "approved": true,
        "duration_seconds": 10,
        "output_duration_seconds": 10,
        "preview_available": true,
        "provider": "video.runway",
        "model": "gen4.5",
        "comment": null,
        "created_at": "…",
        "updated_at": "…"
      }
    ]
  }
}
```

`duration_seconds` is the unit’s length. `output_duration_seconds` is the stored file.
`provider` and `model` are the snapshot for that attempt. The response has no operation id,
credential, storage path or internal database id.

| Status | `error_code` | When |
|--------|--------------|------|
| `422` | `story_review_invalid_transition` | The video is not ready, is already approved when changes are requested, or is not an approved video with a file when selected. |
| `422` | `INVALID_INPUT` | Request changes was sent without a comment. The message is “Say what should change.” |

`GET /story/projects/{project}/history` includes these steps as `kind: unit_version` with
`version_label` such as `Version A`. Events are `version_created`, `ready_for_review`,
`approved`, `changes_requested`, `selected` and `deselected`.

## Scene Assembly

Joins the selected unit videos for one production scene into one scene file with FFmpeg. The
server reads `selected_version_id`. The client does not send paths, commands or version ids.
See [Architecture](Architecture.md#scene-assembly-m11186).

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `…/scenes/{scene}/assemble` | Build, or reuse, the scene video for the current selection. |
| `GET` | `…/scenes/{scene}/assemblies` | Every assembly for that scene, oldest first, plus `current`. |
| `GET` | `…/scenes/{scene}/assemblies/{assembly}` | One assembly. |
| `GET` | `…/scenes/{scene}/assemblies/{assembly}/file` | The scene video, when it is ready. The download name is `scene.mp4`. |

`{scene}` is the story scene id, the same id used by the production plan scene routes.

- **Auth:** owner or admin, same as unit review. `401` unauthenticated, `403` another member or
  another project, `404` when the plan, scene or assembly is not in this project.
- **Body:** ignored. A repeated request for the same selection returns the existing row with
  `created: false` and HTTP 200. The first request for a new selection returns HTTP 201.
- **`current`:** the newest completed assembly, or `null`. A completed assembly is also placed
  on the reel timeline at the measured scene length. The timeline response does not include the
  storage disk or path. The final movie reads that stored scene file.

```json
{
  "data": {
    "created": true,
    "assembly": {
      "id": "…",
      "scene_id": "…",
      "version": "A",
      "label": "Version A",
      "status": "completed",
      "status_label": "Scene video is ready",
      "output_available": true,
      "duration_seconds": 47,
      "output_duration_seconds": 47,
      "error_message": null,
      "created_at": "…",
      "updated_at": "…"
    }
  }
}
```

`duration_seconds` is the scene length. `output_duration_seconds` is the measured file, and is
`null` until the build succeeds. `version` is `null` until a version letter is assigned.
`error_message` is present only when `status` is `failed`. The response has no storage path,
FFmpeg command, stderr, provider name or internal database id.

| Status | `error_code` | When |
|--------|--------------|------|
| `422` | `SCENE_NOT_READY` | A unit has no approved selected video. Message: “Some video parts are not ready yet.” |
| `422` | `story_media_build_failed` | A part is damaged, the wrong length, or the wrong picture shape. FFmpeg did not keep an output. |
| `422` | `SCENE_ASSEMBLY_DURATION` | The finished file does not match the scene length. Message: “The finished scene video does not match the planned length.” |
| `422` | `SCENE_ASSEMBLY_FAILED` | The build failed for another reason. Message: “The scene video could not be built.” |
| `422` | `SCENE_ASSEMBLY_NOT_READY` | The file was requested before the scene video is ready. |
| `503` | `story_media_tools_unavailable` | FFmpeg is not installed. |

`GET /story/projects/{project}/history` includes these steps as `kind: scene_assembly`. Events
are `requested`, `started`, `completed`, `failed`, `retried` and `version_created`.

A production plan scene also includes a `production` summary: clip, selected, approved, review,
changes, generating and failed counts, plus `scene_video` of `none`, `building`, `ready` or
`failed`. The clip list itself stays on `units`.

## Scene production

One read for the Creative Studio scene workspace. It does not generate, review or assemble.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `…/scenes/{scene}/production` | Clips from the plan, their versions, and scene videos. |

`{scene}` is the story scene id. Auth matches scene assembly (owner or admin). The payload has
`plan_id`, `duration_seconds`, `clips`, `current` and `assemblies`. Each clip has `sequence`,
`start_second`, `duration_seconds`, `end_second`, `message`, `versions` and, while a video is
being made, `active_generation` with `id` and `status` only. Versions omit provider and model.
Paths, commands and operation ids are not included. Generate, review and build stay on their
existing routes.

## Request / Response Schemas
_To be defined. Reference the JSON templates under `/Brand` and `/Agents`._

_This is a placeholder — replace with the actual OpenAPI/spec once the backend exists._
