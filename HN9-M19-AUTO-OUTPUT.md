# HN9 M19 AUTO EXECUTION

CURRENT_SPRINT: M19
STATUS: PASS — AWAITING M15 MERGE
ACTION:

### M19

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY
On a scene's production panel, Versions lists the clip versions and the assembled scene versions. Restore on an older assembled version points the timeline at that version. The trim is kept when it still fits, and shortened when the older version is shorter. Nothing is deleted: the newer version, its file, and any finished video stay. Restore again on the newer version puts the timeline back. Doing it on the version that is already current changes nothing. A restore is refused while a video is being built.

Sound can be English, Hindi, or Gujarati. The story language is the starting choice, and the sound step can override it. English uses the configured ElevenLabs model. Hindi uses eleven_multilingual_v2 and does not send a language code. Gujarati uses eleven_v3 and does send language code gu. If Gujarati is pointed at a model that does not support it, the request stops before any charge with: Gujarati needs eleven_v3 or newer; set ELEVENLABS_MODEL_GU. The price is the same M13 sound estimate. Optional voice ids come from ELEVENLABS_VOICE_GU and ELEVENLABS_VOICE_HI. Until those are set, the default voice is used and the screen says a language voice is not configured. Voice ids are not shown.

Building the final video stays immediate unless STORY_RENDER_QUEUE_ENABLED is true. With that flag, Build returns at once, the button stays disabled while that reel is building, and the bell tells the person who clicked Build when it finishes or fails. If the queue cannot accept the job, the video is built in the same request. A stuck build can be closed with php artisan hn9:story-recover-renders. That command is not on a schedule.

From HN9-AI-Studio/Backend, with Laragon PHP on PATH:
1. If STATUS is "PASS — AWAITING M15 MERGE": wait until M15 says PASS — MERGED, then start a new agent run with "CONTINUATION: M19 MERGE GATE" and this same prompt file.
2. After PASS — MERGED: php artisan migrate (the agent may already have run it; migrate:status must show Ran).
3. Optional voices: add ELEVENLABS_VOICE_GU=<voice id>, ELEVENLABS_VOICE_HI=<voice id> to Backend/.env (voice ids from the ElevenLabs Voice Library).
4. Optional background render: add STORY_RENDER_QUEUE_ENABLED=true to Backend/.env, restart php artisan serve, and keep a worker running in a second terminal: php artisan queue:work --queue=default --timeout=1250 (after any .env change: php artisan queue:restart).
5. When everything is merged, the extra folder can be removed: cd C:\laragon\www\Hn9-Studio; git worktree remove --force ..\Hn9-Studio-m19

No dev server was started. The screens were checked by the production build and the API tests, not by clicking the running app.

#### FULL TECHNICAL RESULT
Parallel Safety: Worktree C:\laragon\www\Hn9-Studio-m19, branch m19/versions-voice-render, base e0e9e66559e63e6e4ac048530e197fced485beaa (origin/main, "Merge branch 'm15/quick-video-wizard'"). feat(m15) was already on origin/main at the start, and HN9-M15-AUTO-OUTPUT.md already said STATUS: PASS — MERGED. This first commit still records AWAITING because the merge gate runs after the sprint commit. No DO NOT TOUCH file was edited. The main folder checkout was not edited. Shared file routes/api/v1.php has one use block after WorkflowRunController and one route block immediately after the render file route, both marked // --- M19 begin --- / // --- M19 end ---. storyService.js, studioMessages.js, storyConstants.js, StudioUi.jsx, StudioContext.jsx, ProjectStoryPage.jsx, bootstrap/app.php, config/ai.php, and config/story_video.php were not edited. Tests use sqlite :memory: (phpunit.xml DB_CONNECTION=sqlite, DB_DATABASE=:memory:). No MySQL migrate was run.

Implementation: Assemblies had no current column. selected_at on story_scene_assemblies is the pointer. With no selected_at, current stays the last completed row. A finished assembly sets selected_at and clears it on siblings. Restore sets selected_at on the target, clears siblings, and calls StoryTimelineService::retargetSceneVideo. That keeps in_ms/out_ms when they fit and clamps when in_ms is past the duration or out_ms is past it. Clip ids stay, so transitions stay. placeApprovedAssembly is not used. Review "latest version number" is not changed. Scene versions use selected_at only as the playback pointer. Unit versions keep the existing select route. History is a story_version_restores row plus activity story.version_restored (uuids only). Restoring the current version writes no history. An active render (queued, processing, or rendering) returns 409 story_render_in_progress. render_stale is true when any final render exists for the reel. Nothing is deleted.

Voice resolver order: request language, then story bible language, then default_language (en). English model is languages.en.model, else ai.providers.elevenlabs.default_model, else eleven_multilingual_v2. Hindi uses eleven_multilingual_v2. Gujarati uses eleven_v3. language_code is sent only when the model is in language_code_models (eleven_v3, not eleven_multilingual_v2). A model listed in model_languages that does not include the language throws story_voice_language_unsupported before StoryBudgetGuard and before HTTP. Unknown models are allowed with model_verified false. The profile is applied as story_model_id / story_voice_id and those keys are stripped from the ElevenLabs JSON body. A caller-supplied voice still goes through the registry. The idempotency key gains :language:model. A request with no language still resolves the same profile, so the existing double-post stays one row.

Render: STORY_RENDER_QUEUE_ENABLED defaults false. Flag off creates the row, sets processing, builds, and returns 201 with no queued key. Flag on returns 202 with queued true and dispatches ProcessStoryRenderJob afterCommit on the configured connection and queue (default). The job claims with UPDATE status=rendering WHERE status=queued. A second run finds zero rows and returns. Success and failure notify only requested_by through the database channel (story.render.completed or story.render.failed). A throw while resolving the queue connection falls back to the sync build, logs story.render.queue_fallback with the render uuid and exception class, and returns queued false and fallback sync. hn9:story-recover-renders fails rendering rows older than STORY_RENDER_STALE_MINUTES (default 30) with "Render was interrupted; please render again" and re-queues a stale queued row whose uuid is not in the jobs table. --dry-run writes nothing. The command is not scheduled, matching story:recover-video-jobs.

Files: Backend new: migrations 2026_11_11_000001 through 000004, config/story_voice.php, config/story_render.php, StoryVoiceProfile, StoryVoiceProfileResolver, StoryVersionRestore, StoryVersionRestoreService, StoryVersionRestoreController, StoryVoiceLanguageController, NotificationController, ProcessStoryRenderJob, StoryRenderFinished, RecoverStoryRenders, StoryVersionRestoreTest, StoryVoiceLanguageTest, StoryRenderQueueTest, NotificationsApiTest. Backend modified: StoryVideoJobStatus (rendering), StorySceneAssembly, StorySceneVersion, StorySceneAudio, StoryFinalRender, StorySceneAssemblyService, StoryTimelineService, StoryRenderService, StoryAudioService, ElevenLabsStoryAudioAdapter, ElevenLabsProvider, CreateStorySceneAudioRequest, StoryAudioController, StoryRenderController, DashboardController, routes/api/v1.php. Frontend new: storyVersionsService.js, storyVoiceService.js, notificationService.js, studioMessagesM19.js, NotificationBell.jsx, VersionsDrawer.jsx. Frontend modified: Header.jsx, SceneProduction.jsx, StudioSoundStep.jsx, StudioFinalStep.jsx. Control: HN9-M19-AUTO-OUTPUT.md.

API: All inside auth:sanctum. GET .../production-plans/{planUuid}/scenes/{sceneUuid}/versions throttle 60,1. Policy select on the workspace and review on the production plan. Response: units (selected, preview_url API path, no disk or path), assemblies, history (last 20). POST .../assemblies/{assemblyUuid}/restore throttle 60,1. Same policy. Response: changed, restored, previous, timeline_clips_updated, trim_adjusted, render_stale. POST .../reels/{reelUuid}/scenes/{sceneUuid}/versions/{versionUuid}/restore throttle 60,1. Policy select on the workspace and update on the reel. GET /api/v1/story/voice-languages. Any active user. default_language plus languages of code, label, model, model_verified, voice_configured, send_language_code. No voice ids. POST /api/v1/notifications/{id}/read and POST /api/v1/notifications/read-all. Own rows only; another user's id is 404. GET /api/v1/dashboard/notifications now returns notifications and unread_count. Audio store accepts language in en,hi,gu. Render store returns 202 when data.queued is true, otherwise 201. Error codes: story_version_not_restorable 422, story_render_in_progress 409, story_voice_language_unsupported 422, story_budget_exceeded 422. Anonymous 401. Another member 403. Inactive token 401.

Database: 2026_11_11_000001 creates story_version_restores and adds nullable selected_at on story_scene_assemblies and story_scene_versions. 2026_11_11_000002 adds nullable language and voice_model on story_scene_audios. 2026_11_11_000003 adds nullable requested_by, queued_at, started_at on story_final_renders. 2026_11_11_000004 creates notifications if missing. Timestamps are later than 2026_11_10_100000, the latest migration on origin/main. down() drops only what up() added. MySQL: not run — awaiting merge gate.

Tests: StoryVersionRestoreTest, StoryVoiceLanguageTest, StoryRenderQueueTest, NotificationsApiTest. 27 passed. Scenarios 1–25 pass, plus scene-version restore. Http::preventStrayRequests and Http::fake only. The database-key test uses test-key-1234 and asserts the log does not contain it. The unsupported Gujarati test sets the budget to 0 and still returns story_voice_language_unsupported, so the guard does not run first. The zero-budget English test returns story_budget_exceeded and sends no HTTP.

Build: npm run build passed (existing chunk-size warning only). node --test src/services/productionClips.test.js — 12 passed.

Security: Restore and version list use the assembly policy. Scene-version restore uses workspace select and reel update. Cross-project ids return 404. Voice languages and notifications require an active user. The M13 guard still runs inside StoryAudioService before generate, after the language check. ElevenLabs receives story_model_id only as an override that is removed from the JSON body. Preview URLs are /file API paths. Activity properties are uuids. Secret scan of the 46 candidate files found no sk- key, private key, or access-key pattern. .env was not staged. M12, M13, and M14 tests stayed green inside the full suite. M15 StoryQuickStartTest 13 passed and StoryTemplateCatalogTest 2 passed.

Regression: php artisan test — 1050 passed, 1 skipped, 16806 assertions (M15 baseline 1023 passed, 1 skipped, 16566 assertions). php artisan test --filter Story — 462 passed, 1 skipped, 13940 assertions (baseline 436 passed, 1 skipped, 13713 assertions). php artisan test --filter Provider — 343 passed, 1653 assertions (baseline 343 passed, 1653 assertions). php artisan test --filter StoryCostBudgetTest — 11 passed, 68 assertions. php artisan test --filter StoryQuickStartTest — 13 passed, 135 assertions. php artisan test --filter StoryTemplateCatalogTest — 2 passed, 20 assertions. A parallel run of two suites against the shared testing disk produced false storage failures; the sequential full suite and the sequential Story filter both passed.

Merge Gate: not run in this commit. M15 is already PASS — MERGED on origin/main, so the gate follows this commit.
Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: Picture generations are still missing from the job-based spend summary. Queued unsent estimates are omitted from the public total. The plan text rate stays unset until STORY_COST_TEXT_PER_1K_TOKENS. Luma and Seedance keys are not verifiable. Claude and OpenRouter are not editable in the keys screen. A failed unit regeneration reuses the same intent (audit 0358b87, M18). eleven_v3 may bill differently from the single configured audio rate.

#### GIT INTEGRATION
Worktree: C:\laragon\www\Hn9-Studio-m19
Sprint Branch: m19/versions-voice-render
Commit: this file is inside the sprint commit
Commit Message: feat(m19): add version restore, multilingual voice and background render queue
Files Committed: the Backend, Frontend, and control files listed above. logs/, Frontend/dist, vendor/, node_modules/, storage media, and .env were not staged.
Secret Scan: PASS
Branch Push: origin m19/versions-voice-render
M15 On Origin/Main: yes (feat(m15) present; HN9-M15-AUTO-OUTPUT.md STATUS: PASS — MERGED)
Merge origin/main Into Branch: pending this commit
Conflicts: none yet
Post-Merge Tests: pending
Merge To Main: pending
Main Push: pending
Primary Folder Fast-Forward: pending
MySQL Migrate: not run
Origin/Main: e0e9e66559e63e6e4ac048530e197fced485beaa at sprint start
Force Push: NO
Status: PASS — AWAITING M15 MERGE

Blockers:
Next Sprint: M16 (awaiting user review — do not start)
