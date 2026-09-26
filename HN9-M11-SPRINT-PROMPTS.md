# HN9 M11 SPRINT PROMPTS

This file is the authoritative scope for M11.6 through M11.17. It does not authorize work outside that range.

Execution procedure: `HN9-M11-AUTO-AGENT.md`
Execution state: `HN9-M11-AUTO-OUTPUT.md`

## Shared Architecture

Project Story remains a new top-level module. It is not a tab inside the M10 project workspace.

```
Project Story
├── Story Bible
├── Characters
├── Style Bible
├── Story Planner
├── Reels / Scenes
└── Video Engine
      └── Capability Router
           └── Provider Adapter
```

Provider independence is mandatory. Seedance, Higgsfield, Runway, Kling, and Veo must not be hard-coded into core business logic. Vendor names, endpoints, headers, and auth methods stay inside provider adapters.

M10 business workflows stay intact. The existing Gemini video-download fix stays intact. OpenAI and image-provider API keys are intentionally unavailable. Do not restore them. Do not add API keys.

Shared prohibitions for every sprint: no `migrate:fresh`, no git commit, push, merge, rebase, reset, clean, or stash, no fake provider success, no placeholder AI media, no secret leakage, no silent repair of broken AI output.

`LIVE_VALIDATION` values used below:

- `NOT_REQUIRED` — do not call a provider for this sprint.
- `REQUIRED` — PASS is illegal unless the specified live call actually happened.
- `PENDING_BY_INTENTIONAL_CONFIGURATION` — do not restore keys and do not invent a live result. Continuation is allowed only when this prompt says that pending state may continue.

A sprint prompt that says not to implement the next sprint means the next sprint is out of scope for that execution. The auto agent may start it later only under `HN9-M11-AUTO-AGENT.md`.

---

## M11.6 — Video Provider Engine

```
SPRINT: M11.6
TITLE: Video Provider Engine
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.7 inside this execution.
```

The approved prompt below is the M11.6 scope. Execute that text. Do not replace it with a shorter scope.

Required-section map. The approved prompt is the requirement. These names only locate it:

- Objective: M11.6 OBJECTIVE
- Scope: STRICT M11.6 SCOPE
- Out of scope: M11.6 MUST NOT IMPLEMENT
- Existing architecture to audit: PHASE 1 — AUDIT EXISTING VIDEO ARCHITECTURE
- Implementation requirements: PHASE 2 through PHASE 18
- API requirements: PHASE 19 — STORY API CATALOG
- Frontend requirements: PHASE 20 — FRONTEND FOUNDATION
- Database requirements: PHASE 24 — MIGRATION
- Security requirements: PHASE 22 — SECURITY / IDOR
- Tests: PHASE 23 — TESTS
- Migration requirements: PHASE 24 — MIGRATION
- Regression requirements: PHASE 25 — BUILD / REGRESSION
- Real-provider requirements: PHASE 26 — REAL PROVIDER POLICY. LIVE_VALIDATION is NOT_REQUIRED. Real provider calls are forbidden.
- No-compromise rules: STRICT NO-COMPROMISE RULES and CRITICAL GIT / WORKTREE SAFETY
- Stop conditions: M11.6 STOP CONDITION
- Final report format: numbered return list, items 1 through 36
- Exact PASS/BLOCKED rules: FINAL VERDICT

---

M11.6 — PROJECT STORY: VIDEO PROVIDER ENGINE
STRICT NO-COMPROMISE IMPLEMENTATION SPRINT

We are starting M11.6 of HN9 AI Studio.

APPROVED PREVIOUS STATE:

M11.0 — PASS
M11.1 — PASS
M11.2 — IMPLEMENTED / TESTS PASS / LIVE VALIDATION PENDING
M11.3 — IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING
M11.4 — IMPLEMENTATION VERIFIED / LIVE GPT VALIDATION PENDING
M11.5 — PASS

The approved Project Story architecture is:

Project Story
├── Story Bible
├── Characters
├── Style Bible
├── Story Planner
├── Reels / Scenes
└── Video Engine
      └── Provider Abstraction
           ├── Provider A
           ├── Provider B
           ├── Provider C
           └── Future Providers

==================================================
M11.6 OBJECTIVE
==================================================

Build the production-grade, provider-independent Video Provider Engine foundation for Project Story.

The goal is:

Project Story Scene
        ↓
Requested Capability
        ↓
Capability Router
        ↓
Provider Selection
        ↓
Provider Adapter
        ↓
Generation Job Contract
        ↓
Async Job Tracking
        ↓
Future M11.7 actual generation

M11.6 must create the runtime architecture that later M11.7 can use for real:

- Text-to-Video
- Image-to-Video
- Reference-to-Video
- Video Edit
- Video Extend
- Audio

M11.6 must NOT yet generate real video.

==================================================
CRITICAL GIT / WORKTREE SAFETY
==================================================

Before doing anything:

git status
git diff --stat
git diff --name-only

There are already existing dirty changes from:
- M10 Gemini download fix
- M11.0
- M11.1
- M11.2
- M11.3
- M11.4
- M11.5

PRESERVE ALL EXISTING WORK.

DO NOT:
- revert
- reset
- clean
- stash
- stage unrelated files
- commit
- push
- merge
- rebase

Do not modify existing Gemini fixes.

Do not modify M10 business logic.

At the end clearly distinguish:
A. pre-existing changes
B. new M11.6 changes

==================================================
STRICT M11.6 SCOPE
==================================================

M11.6 MUST implement:

1. Generic Video Provider Engine
2. Provider interface contracts
3. Capability definitions
4. Provider registry
5. Capability catalog
6. Capability-based routing
7. Provider/model metadata
8. Duration capability metadata
9. Aspect-ratio capability metadata
10. Input-type capability metadata
11. Audio capability metadata
12. Provider availability state
13. Provider priority/fallback metadata
14. Generation request contract
15. Generation job contract
16. Provider operation/job ID contract
17. Provider status normalization
18. Generic provider error contract
19. Provider timeout configuration contract
20. Polling/webhook capability contract
21. Download capability contract
22. Model selection abstraction
23. Feature/capability validation
24. Provider adapter boundary
25. Provider configuration boundary
26. API catalog for supported capabilities/providers
27. Frontend provider-independent capability UI foundation
28. Tests
29. Security
30. M11 regression
31. M10 regression
32. Build

==================================================
M11.6 MUST NOT IMPLEMENT
==================================================

DO NOT implement:

- actual Text-to-Video generation
- actual Image-to-Video generation
- actual Reference-to-Video generation
- actual Video Edit generation
- actual Video Extend generation
- actual audio generation
- actual Seedance generation
- actual Higgsfield generation
- actual Runway generation
- actual Kling generation
- actual Veo generation
- final media download from provider
- real generated video storage
- scene video records
- Scene review/approval
- Video Review
- Comment-based regeneration
- Timeline
- Transitions
- Final Renderer
- Export
- GPT Story Planner
- Character generation
- Style generation

M11.7 will implement actual video generation.

==================================================
PHASE 1 — AUDIT EXISTING VIDEO ARCHITECTURE
==================================================

Inspect the existing HN9 codebase carefully.

Inspect:

- M10 ProviderDispatcher
- ProviderRouter
- ExecutionOrchestrator
- TextRequest
- existing image provider architecture
- existing M10 video provider architecture
- Gemini video provider
- existing provider registry
- current StoryVideoEngineInterface
- current StoryVideoProviderAdapterInterface
- StoryCapabilityRouter
- Story Reel/Scene models
- Story Plan outputs
- Story module services
- provider configuration
- retry policies
- circuit breaker
- timeout handling
- job tracking conventions
- private storage
- existing generated asset structures

Determine what can safely be reused as generic infrastructure.

IMPORTANT:

Do NOT blindly reuse M10 video business workflow.

We may reuse generic infrastructure such as:
- provider HTTP clients
- provider configuration
- retry policy primitives
- error sanitization
- job abstractions
- storage abstractions
- queue primitives

ONLY where the abstraction is truly generic.

Do not copy/paste M10 implementations into Story.

==================================================
PHASE 2 — PROVIDER-AGNOSTIC ARCHITECTURE
==================================================

The core Story Video Engine must know only generic concepts.

Core concepts:

Capability
Provider
Model
Input
Request
Job
Operation
Status
Error
Output
Adapter
Router

The following names MUST NOT be hard-coded into core business logic:

- Seedance
- Higgsfield
- Runway
- Kling
- Veo
- specific vendor endpoints
- vendor headers
- vendor auth methods

Provider-specific logic belongs strictly inside provider adapters.

Architecture:

StoryVideoService
       ↓
StoryVideoEngine
       ↓
CapabilityRouter
       ↓
ProviderRegistry
       ↓
ProviderAdapter
       ↓
Provider API

==================================================
PHASE 3 — CAPABILITY MODEL
==================================================

Create strongly typed capability definitions for:

TEXT_TO_VIDEO
IMAGE_TO_VIDEO
REFERENCE_TO_VIDEO
VIDEO_EDIT
VIDEO_EXTEND
AUDIO

The capability model must provide future extensibility.

Each capability may expose metadata such as:

- supported
- max_duration_seconds
- min_duration_seconds
- supported_durations
- supported_aspect_ratios
- supported_resolutions
- supported_input_types
- audio_supported
- async_supported
- polling_supported
- webhook_supported
- download_supported

Do not couple capability definitions to a single provider.

==================================================
PHASE 4 — PROVIDER REGISTRY
==================================================

Create a provider registry that can contain multiple providers.

Provider metadata should support:

- provider key
- display name
- enabled/disabled
- priority
- capabilities
- supported models
- supported durations
- supported aspect ratios
- supported resolutions
- supported input types
- audio support
- generation mode
- async behavior
- polling behavior
- webhook support
- download support

IMPORTANT:

Provider registry may contain configured provider definitions, but core business logic must not depend on one provider.

A provider can be unavailable without breaking Project Story.

==================================================
PHASE 5 — MODEL REGISTRY
==================================================

Create a model abstraction.

A model record/configuration should support:

- provider key
- model key
- display name
- capabilities
- enabled
- priority
- limits
- supported input types
- duration constraints
- aspect ratios
- resolution options
- audio support

Do NOT hard-code model names in the Story business layer.

Model names should come from configuration/registry/provider adapter metadata.

==================================================
PHASE 6 — CAPABILITY ROUTING
==================================================

Implement a production-grade capability router.

Example request:

Need:
TEXT_TO_VIDEO

Router:

Capability
    ↓
Available Providers
    ↓
Eligible Models
    ↓
Priority
    ↓
Supported duration
    ↓
Supported aspect ratio
    ↓
Supported input type
    ↓
Selected Adapter

The router must not choose a provider merely because it exists.

It must verify:

1. capability supported
2. provider enabled
3. model enabled
4. duration supported
5. aspect ratio supported
6. input type supported
7. audio requirement supported
8. provider availability healthy

Return a clear routing decision.

If no eligible provider exists:

Return a sanitized structured error:

VIDEO_CAPABILITY_NOT_AVAILABLE

Do not silently fallback to a provider that cannot satisfy the request.

==================================================
PHASE 7 — PROVIDER FALLBACK
==================================================

Create generic fallback capability.

Example:

Primary provider:
Priority 100

Secondary:
Priority 90

Tertiary:
Priority 80

Fallback should only happen when:

- provider unavailable
- provider capability mismatch
- configured temporary provider failure

Do NOT automatically fallback after a successful accepted provider job.

Once a provider accepts a generation job, the job belongs to that provider.

Do not duplicate generations accidentally.

==================================================
PHASE 8 — REQUEST CONTRACT
==================================================

Create a provider-neutral generation request.

It must support future modes.

Concept:

VideoGenerationRequest

Fields should include:

- project/story workspace reference
- reel reference
- scene reference
- capability
- text prompt
- image/reference inputs
- duration
- aspect ratio
- resolution
- audio requested
- negative prompt if supported
- model preference
- provider preference
- metadata
- idempotency key

IMPORTANT:

The request contract must not contain provider-specific fields unless represented through a generic extensibility mechanism.

Do not leak provider implementation details into Scene entities.

==================================================
PHASE 9 — INPUT MODEL
==================================================

Create a generic input abstraction.

Support future input types:

- text
- image
- video
- reference image
- reference video
- audio

Each input should have:

- type
- internal asset identifier
- safe metadata
- optional role
- ordering

Do NOT pass private storage URLs directly to providers unless the provider adapter specifically requires an upload/reference operation.

The engine must work with internal asset references.

==================================================
PHASE 10 — JOB CONTRACT
==================================================

Create a generic VideoGenerationJob abstraction.

Status normalization should support:

queued
submitted
processing
completed
failed
cancelled

Provider-specific statuses should be mapped into these generic states.

Job must track:

- UUID
- capability
- provider
- model
- operation/job ID
- status
- submitted_at
- started_at
- completed_at
- failed_at
- provider metadata
- sanitized error
- retry count
- timeout state
- timestamps

Do not yet create final Scene video assets.

This is the engine job contract.

==================================================
PHASE 11 — OPERATION / POLLING CONTRACT
==================================================

Define a generic asynchronous operation contract.

Support:

- synchronous response
- async operation
- polling
- webhook
- provider callback where available

Do not assume every provider uses polling.

The engine should ask the adapter:

How does this provider track generation?

Possible normalized behaviors:

SYNC
ASYNC_POLL
ASYNC_WEBHOOK

Do not implement actual provider polling in M11.6.

Create the abstraction only.

==================================================
PHASE 12 — DOWNLOAD CONTRACT
==================================================

Define the generic completion/download contract.

A completed provider job may return:

- binary data
- downloadable URL
- provider reference
- uploaded object

The engine should normalize the result into a generic:

VideoGenerationOutput

Support:

- media reference
- MIME type
- file metadata
- provider output ID
- download strategy
- checksum where available

Do NOT download real provider media in M11.6.

==================================================
PHASE 13 — ERROR MODEL
==================================================

Create normalized provider errors.

Examples:

PROVIDER_UNAVAILABLE
CAPABILITY_UNSUPPORTED
MODEL_UNAVAILABLE
INVALID_INPUT
AUTHENTICATION_FAILED
QUOTA_EXCEEDED
RATE_LIMITED
TIMEOUT
UPSTREAM_ERROR
DOWNLOAD_FAILED
INVALID_PROVIDER_RESPONSE
UNKNOWN_PROVIDER_ERROR

Requirements:

- provider-specific raw messages remain internal/sanitized
- no API keys
- no authenticated URLs
- no headers
- no secret request data
- stable application error codes

Reuse existing error sanitization primitives where safe.

==================================================
PHASE 14 — TIMEOUT / RETRY CONTRACT
==================================================

Create provider-neutral timeout/retry policy support.

Support:

- connect timeout
- request timeout
- total generation deadline
- retryable/non-retryable errors
- max attempts
- backoff
- jitter

Do not create conflicting second retry systems.

Reuse generic infrastructure if already available.

The engine must not retry an accepted async generation as a new generation.

==================================================
PHASE 15 — IDEMPOTENCY
==================================================

Support an idempotency key for generation requests.

Requirements:

- identical generation request should not accidentally create duplicate provider jobs
- idempotency must be scoped safely
- provider job identity must be preserved
- completed accepted jobs must not be duplicated

Do not implement provider-specific idempotency yet.

The engine-level contract must exist.

==================================================
PHASE 16 — PROVIDER ADAPTER INTERFACE
==================================================

Create a strict adapter interface.

Conceptual methods:

- capabilities()
- models()
- validate(request)
- submit(request)
- status(job)
- cancel(job) where supported
- result(job) where supported

The exact interface should match existing HN9 architecture where possible.

Provider adapters must not leak vendor-specific response structures into core Story business services.

==================================================
PHASE 17 — PROVIDER CONFIGURATION
==================================================

Provider configuration must be externalized.

Support configuration concepts:

provider enabled
provider priority
models
capabilities
timeouts
retry policy
availability

Do NOT put secrets in database records.

Do NOT expose credentials to frontend.

Do NOT add real API keys.

==================================================
PHASE 18 — INITIAL PROVIDER ADAPTER FOUNDATION
==================================================

Create provider adapter boundaries for future providers.

At minimum, the architecture must demonstrate that different providers can be registered without changing core engine logic.

You may create non-calling catalog/fake adapters for:

- provider A
- provider B
- provider C

These MUST be generic/vendor-neutral test adapters unless a real provider integration is required by the existing codebase.

Do not create real provider API calls in M11.6.

Do not hard-code provider names in Story business logic.

==================================================
PHASE 19 — STORY API CATALOG
==================================================

Expose a frontend-safe capability catalog.

Example:

GET /api/v1/story/video/capabilities

Response should tell frontend:

Capability
Availability
Supported durations
Aspect ratios
Input types
Audio support

Do NOT expose:
- API keys
- private provider URLs
- secrets
- internal credentials
- unsafe internal configuration

Also provide:

GET /api/v1/story/video/providers

with safe public metadata only.

Do not expose unnecessary vendor internals.

==================================================
PHASE 20 — FRONTEND FOUNDATION
==================================================

Inside Project Story, add a provider-independent Video Engine foundation.

The frontend should think in terms of:

Video Mode:
- Text to Video
- Image to Video
- Reference to Video
- Edit
- Extend
- Audio

NOT:

"Use Seedance"

or:

"Use Higgsfield"

Provider/model selection may be exposed later only where useful.

M11.6 UI should primarily show capability availability.

Example:

Text to Video
Available

Image to Video
Available

Reference to Video
Available

Video Edit
Available

Video Extend
Available

Audio
Available

Do NOT show fake generation previews.

Do NOT add generate buttons that call providers.

==================================================
PHASE 21 — SCENE COMPATIBILITY VALIDATION
==================================================

The engine must be able to validate a future Scene request.

Example:

Scene:
30 seconds
9:16
text-to-video

Validation checks:

- capability exists
- duration supported
- aspect ratio supported
- required input available
- audio requirement supported

Return a structured compatibility result.

Do NOT generate video.

==================================================
PHASE 22 — SECURITY / IDOR
==================================================

Any engine API must remain project-scoped.

Owner:
- can view capability catalog
- can validate generation compatibility for their project
- can access their provider metadata

Non-owner:
- cannot inspect another project's private scene inputs
- cannot create engine jobs in another project
- cannot validate another project's private input references

Unauthenticated:
- 401

No secret leakage.

==================================================
PHASE 23 — TESTS
==================================================

Add comprehensive tests for:

CAPABILITIES:
1. all six capabilities represented
2. capability metadata
3. capability availability
4. duration constraints
5. aspect ratios
6. input types
7. audio support

REGISTRY:
8. provider registration
9. provider enable/disable
10. model registration
11. provider priority
12. model priority
13. capability registration
14. provider independence

ROUTING:
15. correct capability selection
16. duration filtering
17. aspect ratio filtering
18. input type filtering
19. audio filtering
20. model filtering
21. provider priority
22. fallback
23. no eligible provider
24. provider unavailable

REQUEST:
25. request validation
26. generic inputs
27. scene compatibility
28. idempotency contract

JOB:
29. job creation
30. status normalization
31. provider operation ID
32. async mode
33. sync mode
34. timeout state
35. sanitized errors

ERRORS:
36. auth error
37. rate limit
38. quota
39. timeout
40. invalid input
41. provider unavailable
42. download failure

SECURITY:
43. owner
44. non-owner
45. unauthenticated
46. IDOR
47. no secret leakage

REGRESSION:
48. M11.0
49. M11.1
50. M11.2
51. M11.3
52. M11.4
53. M11.5
54. M10

Use existing HN9 test conventions.

==================================================
PHASE 24 — MIGRATION
==================================================

Only create DB tables if the engine genuinely requires persistent provider/job metadata at this stage.

Do NOT create unnecessary video/media tables.

If job persistence is required, create a generic Story Video Generation Job table only.

Do NOT create:
- actual Scene Video table
- final media table
- timeline tables

Those belong to later modules.

Use:

php artisan migrate

Never:

php artisan migrate:fresh

Verify all existing Story and M10 data remains intact.

==================================================
PHASE 25 — BUILD / REGRESSION
==================================================

Run:

- Video engine tests
- capability router tests
- provider registry tests
- job contract tests
- security tests
- M11.0 regression
- M11.1 regression
- M11.2 regression
- M11.3 regression
- M11.4 regression
- M11.5 regression
- relevant M10 regression
- migration status
- route verification
- npm run build

==================================================
PHASE 26 — REAL PROVIDER POLICY
==================================================

NO REAL VIDEO PROVIDER CALLS IN M11.6.

Do NOT:

- call Seedance
- call Higgsfield
- call Runway
- call Kling
- call Veo
- call any real video provider

No API keys should be added.

No real video should be generated.

This sprint is the architecture/foundation gate.

Real provider integration and actual generation begin in M11.7.

==================================================
STRICT NO-COMPROMISE RULES
==================================================

DO NOT:

- modify M10 business logic
- modify M10 video workflow
- modify M10 Gemini fixes
- restore removed API keys
- add OpenAI API keys
- call GPT
- call image providers
- call video providers
- generate media
- implement Story Planner
- implement Characters
- implement Styles
- implement Timeline
- implement Audio generation
- implement Voice
- implement Music
- implement SFX
- implement Transitions
- implement Final Renderer
- implement Final Review
- implement Export
- implement video generation
- hard-code Seedance into core
- hard-code Higgsfield into core
- hard-code Runway into core
- hard-code Kling into core
- hard-code Veo into core
- create duplicate AI clients
- create duplicate provider registries
- create duplicate capability routers
- create duplicate global UI shell
- create fake video output
- create fake successful provider jobs
- expose secrets
- expose private URLs
- run migrate:fresh
- delete existing M11/M10 data
- commit
- push
- merge
- rebase
- reset
- clean
- stash

==================================================
M11.6 STOP CONDITION
==================================================

STOP after:

1. Video provider engine architecture is complete.
2. Capability model is complete.
3. Provider registry is complete.
4. Model registry is complete.
5. Capability router is complete.
6. Request/job contracts are complete.
7. Error model is complete.
8. Timeout/retry contract is complete.
9. Idempotency contract is complete.
10. Provider adapter interface is complete.
11. Safe capability/provider catalog APIs work.
12. Frontend foundation works.
13. Security passes.
14. Migrations pass.
15. Tests pass.
16. Build passes.
17. M11.0–M11.5 regressions pass.
18. M10 regression passes.
19. ZERO real AI/provider calls occurred.

Do NOT continue to M11.7.

==================================================
FINAL REPORT
==================================================

Return:

1. Pre-existing dirty files
2. New M11.6 files
3. Architecture diagram/summary
4. Provider abstraction interfaces
5. Capability definitions
6. Provider registry
7. Model registry
8. Capability routing rules
9. Fallback rules
10. Request contract
11. Input contract
12. Job contract
13. Operation/polling contract
14. Download/output contract
15. Error normalization
16. Timeout/retry contract
17. Idempotency behavior
18. Provider adapter interface
19. Configuration structure
20. API catalog endpoints
21. Frontend Video Engine foundation
22. Scene compatibility validation
23. Security/IDOR results
24. Database changes
25. Tests/results
26. Migration result
27. Frontend build result
28. M11.0 regression
29. M11.1 regression
30. M11.2 regression
31. M11.3 regression
32. M11.4 regression
33. M11.5 regression
34. M10 regression
35. Confirmation:
    - GPT calls: 0
    - image provider calls: 0
    - video provider calls: 0
    - .env unchanged
    - API keys unchanged
    - existing Gemini fixes untouched
    - no Git commit/push/merge
36. Risks/blockers

FINAL VERDICT:

Only report:

M11.6 — PASS

if all implementation, tests, security, migrations, build, and regressions pass and ZERO real provider calls occurred.

Otherwise:

M11.6 — BLOCKED

with the exact failed stage/reason.

STOP.

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.7 — Real Video Generation

```
SPRINT: M11.7
TITLE: Real Video Generation
LIVE_VALIDATION: REQUIRED
CONTINUATION: ALLOWED_ONLY_AFTER_PASS
SCOPE_LOCK: Do not implement M11.8 inside this execution.
```

### Objective

Use the M11.6 engine to perform real video generation for Project Story scenes. This is the first sprint allowed to integrate real video providers and to generate real video.

Modes in this sprint:

- Text-to-Video
- Image-to-Video
- Reference-to-Video

Long video is assembled from 30-second generation units into 60 seconds, 90 seconds, 2 minutes, 5 minutes, and custom durations. A long video is multiple scenes of one continuous story. Continuity rules themselves are M11.8. M11.7 must accept the scene inputs the engine already has and must not invent missing bible context.

### Scope

- Register real video-provider adapters behind the M11.6 adapter interface.
- Submit, poll or receive, and download through that adapter.
- Persist the real media with the existing private-storage pattern.
- Link the stored output to the Story scene job created by the M11.6 contract.
- Support 30-second units and the duration targets above as multiple units, not as one hard-coded vendor duration.
- Keep vendor names inside adapters.

### Out Of Scope

Video Edit, Video Extend, audio generation, timeline, transitions, final renderer, final review, export, usage ledger, and queue hardening. Do not rebuild M11.6. Do not modify M10 video workflow. Do not restore OpenAI or image API keys.

### Existing Architecture To Audit

M11.6 capability router, provider registry, request, job, operation, download, and error contracts. Story Reel and Scene models. Existing private storage and the Gemini download fix, which stays in the M10 path and is not rewritten.

### Implementation Requirements

Generation goes Scene → M11.6 request → Capability Router → selected adapter → provider job → normalized status → download → private file → scene job output.

An accepted provider job is not submitted again. Idempotency from M11.6 applies. A failed provider returns the normalized error and stops. No placeholder file. No copied connectivity-test video. No manual database insert of a finished asset.

Duration handling: each provider call uses a duration that the selected model actually supports. Longer targets are additional scene units of up to 30 seconds. Do not send an unsupported duration and then pretend the provider accepted it.

### API Requirements

Project-scoped Story endpoints to start generation for a scene, read job status, and read the stored output through an authenticated file route. `output_url` stays null when storage is private. No public storage path.

### Frontend Requirements

Project Story can start Text-to-Video, Image-to-Video, and Reference-to-Video for a scene and can show real job status. It must not offer Edit, Extend, or Audio generation yet. It must not label the primary action with a vendor name.

### Database Requirements

Persist provider, model, operation id, normalized status, and the private media file on the existing job/scene structures. Add a migration only for fields the M11.6 job table cannot already store. Never `migrate:fresh`.

### Security Requirements

Owner-only start, status, and file read. Non-owner and anonymous callers are rejected. IDOR tests use a second user. Errors are sanitized. Download URLs that contain keys are not stored or returned.

### Tests

Adapter routing for the three modes; unsupported capability does not call a provider; idempotency does not double-submit; failed provider stores no placeholder; authenticated file read; IDOR; secret redaction. Use HTTP fakes for deterministic tests. Live validation is separate and is not faked.

### Migration Requirements

`php artisan migrate` only if a migration is required. Verify existing Story and M10 rows remain.

### Regression Requirements

M11.0–M11.6 and relevant M10 tests. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: REQUIRED`

A real video provider call is allowed only through the M11.6 adapter, for this sprint's three modes.

OpenAI and image API keys are intentionally unavailable. Do not restore them. Do not use an image provider as a stand-in for video.

If no video-provider credential is configured, do not call a provider, do not create media, and do not write PASS. The only allowed verdict is `IMPLEMENTATION VERIFIED / LIVE VIDEO VALIDATION PENDING`. Then stop. Do not start M11.8.

If a video-provider credential is already configured, run the real generation the prompt requires, wait for the terminal provider state, store the real file, and record provider, model, operation id, file key, and size. One failure stops the sprint. No automatic retry beyond the existing retry policy.

### No-Compromise Rules

No fake video. No reused M10 connectivity-test file presented as this scene's output. No vendor name in core services. No `.env` edits unless this prompt is later changed by the user to permit a specific key. No commit.

### Stop Conditions

Stop on the first failed required test, build, migration, security failure, provider failure, or missing video credential. Do not implement M11.8 in this execution.

### Final Report Format

Pre-existing dirty files, M11.7 files, adapters added, modes exercised, live-validation verdict, provider and model if a live call happened, operation id, storage key, file size, tests, build, migrations, regressions, security, confirmation that OpenAI and image keys were not restored, and Git state.

### Exact PASS / BLOCKED Rules

`M11.7 — PASS` only when implementation, tests, build, migrations, regressions, and security pass and a real video file from a real provider call is stored for this sprint.

`M11.7 — IMPLEMENTATION VERIFIED / LIVE VIDEO VALIDATION PENDING` when everything non-live passed and no video credential exists. This is not PASS. The auto agent stops.

`M11.7 — BLOCKED` for any other failure, with the exact stage and sanitized error.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.8 — Continuity Engine

```
SPRINT: M11.8
TITLE: Continuity Engine
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.9 inside this execution.
```

### Objective

Build the context each scene generation must carry so later scenes stay in one story.

### Scope

Assemble, for a scene request, the Story Bible, Character Bible, character references, Style Bible, style references, and previous-scene context. Pass that package through the generic M11.6 request metadata. Do not call a provider.

### Out Of Scope

New video generation, edit, extend, audio, timeline, review, render, and export. Do not restore API keys.

### Existing Architecture To Audit

Story Bible, characters, character references, Style Bible, style references, Story Planner output, Reel and Scene order from M11.5, and the M11.6 request contract.

### Implementation Requirements

Context is derived from persisted Story records. Previous scene means the prior scene in the same reel, not an arbitrary project. Missing bible data is a structured validation result, not a generated substitute. Character and style references stay internal asset ids.

### API Requirements

Project-scoped read of the resolved continuity package for a scene, and validation that a scene is ready to generate. No provider submit endpoint in this sprint.

### Frontend Requirements

Project Story shows the resolved continuity summary for a scene: bible, characters, style, and previous scene. It does not show raw secrets or private file URLs.

### Database Requirements

Persist continuity snapshots only if regeneration would otherwise change history underneath an existing scene. Do not duplicate bible tables.

### Security Requirements

Owner-only. Non-owner cannot read another project's references. Unauthenticated requests return 401. IDOR tests required.

### Tests

Package includes each source when present; previous scene is the immediate prior scene; missing source returns a stable code; cross-project references are rejected; no HTTP client is called.

### Migration Requirements

`php artisan migrate` only if a snapshot table is required. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.7 and relevant M10. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call any provider.

### No-Compromise Rules

Do not invent bible text. Do not generate a reference image or a video to fill a gap. Do not hard-code a vendor.

### Stop Conditions

Stop on failed tests, build, migration, or IDOR. Do not implement M11.9 in this execution.

### Final Report Format

Sources assembled, previous-scene rule, API, UI, tests, regressions, confirmation of zero provider calls, Git state.

### Exact PASS / BLOCKED Rules

`M11.8 — PASS` only when the continuity package is correct, tests and regressions pass, and zero provider calls occurred.

Otherwise `M11.8 — BLOCKED` with the exact stage.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.9 — Scene Review / Comment / Versioning

```
SPRINT: M11.9
TITLE: Scene Review / Comment / Versioning
LIVE_VALIDATION: PENDING_BY_INTENTIONAL_CONFIGURATION
CONTINUATION: ALLOWED_AFTER_PASS_WHEN_REGENERATE_DOES_NOT_FAKE_MEDIA
SCOPE_LOCK: Do not implement M11.10 inside this execution.
```

### Objective

Let the owner preview a scene, comment on it, keep versions, and regenerate only the affected scene.

### Scope

Scene preview of the stored output. Comments. Version history. A regenerate action that creates a new version for that scene only. Approval state for the scene version using the existing review style: submit, then approve. Do not approve by writing status in SQL.

### Out Of Scope

Edit and extend provider modes, timeline, transitions, final render, export, and regenerating untouched scenes. Do not restore OpenAI or image keys.

### Existing Architecture To Audit

M11.5 scenes, M11.7 stored outputs, M11.8 continuity package, and the M10 review pattern of submit then approve. Reuse that review shape. Do not invent a second review framework.

### Implementation Requirements

Comments attach to a scene version. Regenerating one scene does not create a new version of any other scene. The new version starts from the same continuity package plus the comment. If no video credential is configured, regenerate returns the existing sanitized unavailable error and stores no file.

### API Requirements

Project-scoped preview, comment, version list, submit-review, approve, and regenerate-scene. Preview and file routes require authentication.

### Frontend Requirements

Scene preview, comment entry, version list, and a regenerate control for that scene. No control that regenerates the whole reel.

### Database Requirements

Scene version and comment records. Parent version id on regeneration. Never `migrate:fresh`.

### Security Requirements

Owner-only. Non-owner cannot comment, approve, or regenerate. Unauthenticated requests return 401. IDOR tests required. Comments are stored as text, not executed.

### Tests

Comment persists; approval follows submit then approve; regenerate of scene N leaves other scenes' current versions unchanged; missing credentials create no file; IDOR; no secret in API output.

### Migration Requirements

`php artisan migrate` for version and comment tables only.

### Regression Requirements

M11.0–M11.8 and relevant M10 review tests. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: PENDING_BY_INTENTIONAL_CONFIGURATION`

Review, comment, and versioning do not require a live provider. A live regenerate is not required for PASS. If credentials are absent, do not call a provider and do not invent a regenerated video. Tests use the application error path, not a fake file.

### No-Compromise Rules

Do not mark a scene approved in the database without the review API. Do not copy another scene's file into the new version.

### Stop Conditions

Stop on failed tests, a fake regenerated file, IDOR, or a review bypass. Do not implement M11.10 in this execution.

### Final Report Format

Version model, review events, regenerate scope test, live-validation line, tests, regressions, Git state.

### Exact PASS / BLOCKED Rules

`M11.9 — PASS` when review, comments, versions, and single-scene regenerate behavior pass tests and no fake media was written.

`M11.9 — BLOCKED` otherwise.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.10 — Video Edit + Video Extend

```
SPRINT: M11.10
TITLE: Video Edit + Video Extend
LIVE_VALIDATION: REQUIRED
CONTINUATION: ALLOWED_ONLY_AFTER_PASS
SCOPE_LOCK: Do not implement M11.11 inside this execution.
```

### Objective

Add the Video Edit and Video Extend capabilities on the M11.6 engine, using the stored scene video as input.

### Scope

`VIDEO_EDIT` changes an existing scene video from an edit instruction. `VIDEO_EXTEND` continues that video. Both go through the capability router. The result is a new scene version, not an overwrite of the approved version.

### Out Of Scope

Audio studio, timeline, transitions, final renderer, export. Do not restore OpenAI or image keys. Do not reimplement Text-to-Video.

### Existing Architecture To Audit

M11.6 edit and extend capabilities, M11.7 download and storage path, M11.9 versions.

### Implementation Requirements

Edit and extend require an existing stored scene video. The adapter receives an internal asset reference and performs any provider upload inside the adapter. Core code does not contain vendor endpoints. An accepted job is not resubmitted.

### API Requirements

Project-scoped edit and extend actions on a scene version, plus status and authenticated file read for the new version.

### Frontend Requirements

Edit and Extend actions on a scene that already has video. They are capability names, not vendor names.

### Database Requirements

Reuse scene versions and generation jobs. Add columns only if edit/extend cannot be represented by the existing capability field.

### Security Requirements

Owner-only. IDOR tests. Sanitized provider errors. No key in stored URLs.

### Tests

Router rejects edit/extend when the provider capability is disabled. Missing source video does not call a provider. New version does not replace the previous file. IDOR. HTTP fakes for deterministic tests.

### Migration Requirements

`php artisan migrate` only if required. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.9 and relevant M10. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: REQUIRED`

Same credential rule as M11.7. If no video-provider credential exists, do not call a provider and do not write PASS. Verdict: `IMPLEMENTATION VERIFIED / LIVE VIDEO VALIDATION PENDING`. Then stop.

If a credential exists, one real edit and one real extend are required for PASS, each stored as its own version. A provider failure stops the sprint. No automatic retry beyond the existing policy.

### No-Compromise Rules

No placeholder video. No trimming local bytes and calling it a provider extend. No vendor hard-coding in core.

### Stop Conditions

Stop on test, build, security, or provider failure, or on missing credentials after recording the pending verdict. Do not implement M11.11 in this execution.

### Final Report Format

Capabilities wired, live verdict, operation ids if a live call happened, storage keys, tests, regressions, Git state.

### Exact PASS / BLOCKED Rules

`M11.10 — PASS` only with real stored edit and extend outputs plus passing non-live gates.

Pending live validation is not PASS. The auto agent stops.

`M11.10 — BLOCKED` for any other failure.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.11 — Audio Studio

```
SPRINT: M11.11
TITLE: Audio Studio
LIVE_VALIDATION: REQUIRED
CONTINUATION: ALLOWED_ONLY_AFTER_PASS
SCOPE_LOCK: Do not implement M11.12 inside this execution.
```

### Objective

Add scene audio through the `AUDIO` capability: voice, narration, dialogue, music, SFX, ambient, and generated audio.

### Scope

Audio types are data on the generic audio request, not separate vendors in core code. Store audio in private storage. Attach it to the scene version.

### Out Of Scope

Timeline placement, transitions, final mix-down render, export packaging, and new video generation. Do not restore OpenAI or image keys.

### Existing Architecture To Audit

M11.6 `AUDIO` capability, provider adapters that declare audio, private storage, and scene versions.

### Implementation Requirements

The router selects an adapter that supports `AUDIO` and the requested audio role. Unsupported role returns a normalized error. No silent substitution of music for dialogue.

### API Requirements

Project-scoped create, status, and authenticated file read for scene audio. List audio by role.

### Frontend Requirements

Audio Studio in Project Story lists voice, narration, dialogue, music, SFX, ambient, and generated audio. Actions use those role names.

### Database Requirements

Generic scene-audio records: role, job, private file, status. Do not create one table per vendor.

### Security Requirements

Owner-only file read. IDOR. Sanitized errors. No public audio URLs.

### Tests

Role routing, unsupported role, missing credential stores nothing, IDOR, authenticated read. HTTP fakes for deterministic tests.

### Migration Requirements

`php artisan migrate` for the audio records only if needed. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.10 and relevant M10. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: REQUIRED`

Do not restore OpenAI or image keys. If no audio-provider credential is configured, do not call a provider and do not write PASS. Verdict: `IMPLEMENTATION VERIFIED / LIVE AUDIO VALIDATION PENDING`. Then stop.

If a credential exists, one real generated audio file must be stored for PASS. A provider failure stops the sprint.

### No-Compromise Rules

No silent audio file. No hard-coded vendor in the Audio Studio UI or core service.

### Stop Conditions

Stop on test, build, security, or provider failure, or on missing audio credentials after the pending verdict. Do not implement M11.12 in this execution.

### Final Report Format

Roles supported, live verdict, storage key if a live file exists, tests, regressions, Git state.

### Exact PASS / BLOCKED Rules

`M11.11 — PASS` only with a real stored audio file and passing non-live gates.

Pending live validation is not PASS. The auto agent stops.

`M11.11 — BLOCKED` otherwise.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.12 — Timeline + Transitions

```
SPRINT: M11.12
TITLE: Timeline + Transitions
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.13 inside this execution.
```

### Objective

Let the owner arrange stored scene videos and audio on a timeline.

### Scope

Reorder, trim, split, replace, duplicate, delete, and transitions between adjacent clips. Operations edit timeline data and references to existing private files. They do not call a provider.

### Out Of Scope

New generation, new audio synthesis, final render, and export. Trim and split do not mean a provider extend.

### Existing Architecture To Audit

Scene versions, stored video and audio from prior sprints, reel order from M11.5.

### Implementation Requirements

Timeline order is explicit. Trim stores in and out points. Split creates two clips that reference the same source file with different points. Replace swaps the source version. Duplicate copies the clip record, not the media bytes, unless a later render needs a copy. Delete removes the clip from the timeline and does not delete the source version. Transitions are typed records between two clips.

### API Requirements

Project-scoped timeline read and the operations above.

### Frontend Requirements

Timeline controls for reorder, trim, split, replace, duplicate, delete, and transition choice.

### Database Requirements

Timeline clip and transition records. Never `migrate:fresh`.

### Security Requirements

Owner-only. A clip cannot reference another project's file. IDOR tests required.

### Tests

Each operation. Cross-project replace is rejected. No HTTP client is called.

### Migration Requirements

`php artisan migrate` for timeline tables. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.11 and relevant M10. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call any provider.

### No-Compromise Rules

Do not generate media to perform a trim. Do not hard-code a vendor transition pack.

### Stop Conditions

Stop on failed tests, IDOR, or a provider call. Do not implement M11.13 in this execution.

### Final Report Format

Operations implemented, data model, tests, confirmation of zero provider calls, Git state.

### Exact PASS / BLOCKED Rules

`M11.12 — PASS` only when every timeline operation passes tests and zero provider calls occurred.

Otherwise `M11.12 — BLOCKED`.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.13 — Final Renderer

```
SPRINT: M11.13
TITLE: Final Renderer
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.14 inside this execution.
```

### Objective

Render the timeline into one private final video file from stored clips, trims, and transitions.

### Scope

A render job reads the timeline and writes one final media file to private storage. It uses local media already stored. It does not call a video or audio provider.

### Out Of Scope

New generation, review workflow, export ZIP, and queue hardening beyond the render job's own status.

### Existing Architecture To Audit

M11.12 timeline, private video and audio files, and existing job-status names.

### Implementation Requirements

Render status uses the normalized job states. A failed render leaves no partial file marked final. The render references the timeline version it used. Missing source files fail with a stable code.

### API Requirements

Project-scoped start render, read render status, and authenticated download of the final file. No public URL.

### Frontend Requirements

A render action and status in Project Story. It does not offer provider generation.

### Database Requirements

Final render record: timeline reference, status, private file. Never `migrate:fresh`.

### Security Requirements

Owner-only. Non-owner cannot read the final file. IDOR tests required.

### Tests

Render from stored clips; missing file fails cleanly; output is readable video bytes; IDOR; no provider HTTP.

### Migration Requirements

`php artisan migrate` only if the render record needs a table. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.12 and relevant M10. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call any provider. Rendering local files is not a provider call.

### No-Compromise Rules

Do not upload the timeline to a vendor to render it. Do not mark a source scene file as the final render without running the renderer.

### Stop Conditions

Stop on failed tests, an unreadable output, or a provider call. Do not implement M11.14 in this execution.

### Final Report Format

Render input, output key, file type and size, tests, confirmation of zero provider calls, Git state.

### Exact PASS / BLOCKED Rules

`M11.13 — PASS` only when a real local render file is stored and tests pass.

Otherwise `M11.13 — BLOCKED`.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.14 — Final Review + Rework

```
SPRINT: M11.14
TITLE: Final Review + Rework
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.15 inside this execution.
```

### Objective

Review the final render and send it back for rework without bypassing the review workflow.

### Scope

Submit the final render for review. Approve it, or request rework with a comment. Rework points at the timeline or scene version that must change. Approving does not export.

### Out Of Scope

ZIP export, new provider generation, and automatic regeneration of every scene.

### Existing Architecture To Audit

M11.9 scene review and M11.13 final render. Reuse the submit-then-approve flow.

### Implementation Requirements

Only a completed render can be submitted. Rework records the comment and the target. Approval does not delete the render file. Status changes go through the review API.

### API Requirements

Submit, approve, and needs-rework on the final render. Owner submits. Approval follows the existing permission rule used by Story review. Unauthenticated requests return 401.

### Frontend Requirements

Final preview, submit, approve, and rework comment.

### Database Requirements

Review events for the final render. Never `migrate:fresh`.

### Security Requirements

Non-owner cannot approve another project's render. IDOR tests required.

### Tests

Submit from completed; approve; rework comment required; file still readable after approval; IDOR; no provider HTTP.

### Migration Requirements

`php artisan migrate` only for review events if they are not already generic. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.13 and relevant M10 review tests. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call any provider.

### No-Compromise Rules

Do not set the render to approved with a direct database update. Do not generate a replacement video during rework in this sprint.

### Stop Conditions

Stop on failed tests or a review bypass. Do not implement M11.15 in this execution.

### Final Report Format

Review transitions, events, file check, tests, confirmation of zero provider calls, Git state.

### Exact PASS / BLOCKED Rules

`M11.14 — PASS` only when submit, approve, and rework pass through the API and zero provider calls occurred.

Otherwise `M11.14 — BLOCKED`.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.15 — Export + Secure Delivery

```
SPRINT: M11.15
TITLE: Export + Secure Delivery
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.16 inside this execution.
```

### Objective

Export the approved final Story render and its scene package, and deliver it only to the owner.

### Scope

A real ZIP built by the application. Authenticated download. Unauthorized rejection. Private storage. No public path in the API payload.

### Out Of Scope

New generation, cost ledger, and queue recovery. Do not replace the M10 project export. This export is the Story final package.

### Existing Architecture To Audit

M11.14 approved final render, scene versions, private storage, and the M10 export pattern for ZIP creation and authorized download. Reuse that security pattern. Do not fork a second download auth model.

### Implementation Requirements

Export requires an approved final render. The ZIP contains the final video, the approved scene script text, scene media, and a metadata JSON. Paths inside the ZIP are package paths, not server paths. The export record stores disk, relative key, size, and completed status.

### API Requirements

Create export, read export status, owner download. Non-owner and anonymous downloads are rejected. The JSON resource does not include the absolute storage path.

### Frontend Requirements

Export action and download action on an approved final render.

### Database Requirements

Story export records. Never `migrate:fresh`.

### Security Requirements

Owner download succeeds. Other users and anonymous callers are rejected. IDOR tests required. No secret in the ZIP metadata.

### Tests

Export refused before approval. ZIP opens and contains the expected entries. File bytes match the stored final render. Unauthorized download rejected. No provider HTTP.

### Migration Requirements

`php artisan migrate` for the Story export table if required. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.14 and M10 export tests. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call any provider. The ZIP is built from stored files.

### No-Compromise Rules

Do not hand-build a ZIP outside the application and insert an export row. Do not mark the project exported without a completed ZIP.

### Stop Conditions

Stop if the ZIP is missing, empty, or fails to open, or if unauthorized download succeeds. Do not implement M11.16 in this execution.

### Final Report Format

Export id, status, relative storage key, ZIP size, entry list, owner download result, unauthorized result, tests, Git state.

### Exact PASS / BLOCKED Rules

`M11.15 — PASS` only when a real ZIP is stored, opens, matches stored media, and unauthorized download is rejected.

Otherwise `M11.15 — BLOCKED`.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.16 — Usage / Cost / Generation History

```
SPRINT: M11.16
TITLE: Usage / Cost / Generation History
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: ALLOWED_AFTER_PASS
SCOPE_LOCK: Do not implement M11.17 inside this execution.
```

### Objective

Record a per-project history of Story generation jobs, including provider, model, capability, status, and cost when the provider actually returned a cost.

### Scope

A read model over existing Story jobs plus a ledger row written when a job reaches a terminal state. Cost stays null when the provider did not report a billable cost. Do not invent token counts or prices.

### Out Of Scope

New generation, new provider calls, billing checkout, and queue workers.

### Existing Architecture To Audit

M11.6 job records, M11.7–M11.11 terminal jobs, and the existing usage-ledger pattern. Reuse cost rules: store a cost only when the provider response includes one.

### Implementation Requirements

History lists jobs for the owning project in created order. Each row shows capability, provider key, model, status, timestamps, and cost or null. Sanitized error may be shown. Operation ids are shown. Authenticated download URLs are not.

### API Requirements

Project-scoped history list. No create endpoint that starts generation.

### Frontend Requirements

A history view in Project Story. Empty cost is shown as not reported, not as zero, unless the provider reported zero.

### Database Requirements

Ledger table or equivalent columns if jobs cannot already store cost. Never `migrate:fresh`. Do not rewrite historical rows with guessed costs.

### Security Requirements

Owner-only history. IDOR tests. No secrets in the payload.

### Tests

Terminal job appears once. Missing cost stays null. A second project cannot read the history. No provider HTTP.

### Migration Requirements

`php artisan migrate` only if a ledger table is required. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.15 and relevant M10 usage tests. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call a provider to obtain a cost. Do not restore API keys.

### No-Compromise Rules

Do not hard-code a price table for Seedance, Higgsfield, Runway, Kling, or Veo. Do not backfill fake costs onto M11.7 jobs.

### Stop Conditions

Stop on a fake cost, an IDOR, or a provider call. Do not implement M11.17 in this execution.

### Final Report Format

Fields recorded, null-cost behavior, tests, confirmation of zero provider calls, Git state.

### Exact PASS / BLOCKED Rules

`M11.16 — PASS` only when history is owner-scoped, costs are not invented, and tests pass.

Otherwise `M11.16 — BLOCKED`.

---

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


## M11.17 — Queue / Recovery / Production Hardening

```
SPRINT: M11.17
TITLE: Queue / Recovery / Production Hardening
LIVE_VALIDATION: NOT_REQUIRED
CONTINUATION: NONE
SCOPE_LOCK: This is the last sprint. Do not invent M11.18.
```

### Objective

Make Story generation jobs safe to run on the queue: retries, recovery, idempotency, and provider-failure handling.

### Scope

Queue dispatch for submit, poll, and download steps that M11.6 already defined. Recovery resumes an accepted job. It does not submit a second provider job. Provider failures stay on the normalized error. Idempotency keys remain in force.

### Out Of Scope

New capabilities, new providers, new UI features, and any sprint after M11.17. Do not restore API keys. Do not call a provider.

### Existing Architecture To Audit

M11.6 retry, timeout, and idempotency contracts. Existing Laravel queue jobs. M10 video polling behavior, including the rule that an accepted operation is polled rather than restarted.

### Implementation Requirements

A retryable error may retry the same operation. A non-retryable error stops. Recovery loads the stored operation id and continues that operation. Crash recovery does not duplicate media rows. Sync and queued modes both honor idempotency.

### API Requirements

Existing status endpoints show recovered state. Add an owner-only recover action only if status polling cannot resume a stuck accepted job. That action must not call `predictLongRunning` or any create-generation endpoint.

### Frontend Requirements

Show failed and recovering states from normalized status. No new generate mode.

### Database Requirements

Store attempt count and last sanitized error on the existing job. Never `migrate:fresh`.

### Security Requirements

Owner-only recover. IDOR tests. Logs and API payloads contain no keys and no authenticated URLs.

### Tests

Duplicate submit returns the original job. Recovery does not create a second provider operation. Non-retryable failure stays failed. Retryable failure increments the attempt and keeps the same operation id. IDOR. No outbound provider HTTP in these tests. Use fakes.

### Migration Requirements

`php artisan migrate` only if attempt fields need a migration. Never `migrate:fresh`.

### Regression Requirements

M11.0–M11.16 and relevant M10 queue and video tests. `npm run build`.

### Real-Provider Requirements

`LIVE_VALIDATION: NOT_REQUIRED`

Do not call any provider. Do not describe a fake live recovery as PASS.

### No-Compromise Rules

Do not start a new generation to heal a failed one. Do not add M11.18. Do not commit.

### Stop Conditions

Stop on a duplicate provider submit, a failed test, or a real provider call.

### Final Report Format

Retry matrix, recovery behavior, idempotency evidence, tests, regressions, confirmation of zero provider calls, Git state.

### Exact PASS / BLOCKED Rules

`M11.17 — PASS` only when queue, retry, recovery, and idempotency tests pass and zero provider calls occurred.

Otherwise `M11.17 — BLOCKED`.

## GIT INTEGRATION GATE

The sprint is not fully complete until:

1. Required implementation/test/build/security/regression gate passes.
2. Secret scan passes.
3. Intended sprint changes are staged only.
4. Exactly one sprint commit is created.
5. Sprint branch is pushed.
6. Remote main is safely synchronized.
7. Sprint branch is merged into main.
8. Main is pushed.
9. local main == origin/main.
10. working tree is verified.
11. Git result is written to HN9-M11-AUTO-OUTPUT.md.

If the sprint is BLOCKED:
- no commit
- no push
- no merge
- stop

If the sprint is IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING and that sprint's rules permit continuation:
- Git integration may proceed for the verified implementation
- clearly record live validation as pending
- do not claim live validation success

Do not modify any other sprint scope.


`M11 — COMPLETE` is not written by this prompt. The auto agent may write it only after M11.6 through M11.17 each have a verdict that their own prompt accepts as complete.
