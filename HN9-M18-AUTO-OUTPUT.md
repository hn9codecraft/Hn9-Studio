# HN9 M18 AUTO EXECUTION

CURRENT_SPRINT: M18
STATUS: PASS — MERGED
ACTION:

### M18

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS — MERGED
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY

A failed or cancelled clip now shows why it failed, which attempt it was, and how many retries are left. Retry opens the same preview as Generate: the prompt, the pictures that will be sent, and the price. Confirming starts one new attempt and is charged like any other clip. The same failed attempt cannot be billed twice. The limit is 5 retries per unit. If the service blocked the prompt, Retry stays off and the scene text has to change first.

In the production clips, the sound list, and the character and look pictures, tick the items you want and choose Approve selected. Nothing is ticked for you. Select all eligible only ticks items that are ready, and you still confirm. The confirm list says what will be approved and what will be skipped. If a later paid step is the natural next step, its estimate is shown with “Not charged now”. Approving does not generate, lock, or render anything.

The Studio header shows Story, Cast, Style, Plan, Production, Sound, Timeline, Render, and Review, each with a done/total count, plus one overall percent. A render that is waiting says Queued. A render that is running says Rendering and how long it has been going. A failed render says to open the Final step. The bar only reads status. It does not start work.

From HN9-AI-Studio/Backend, with Laragon PHP on PATH:
1. No migration was added, so php artisan migrate is not required.
2. Refresh the Studio page (Ctrl+F5) so the new progress bar and buttons load.
3. The retry limit is 5 per unit (config/story_retry.php, max_user_retries_per_unit).

#### FULL TECHNICAL RESULT

Preflight: origin/main was 7ec5609394afdc1f3881d2bbc5d11a761a4def5c, the M17 merge. git log origin/main --grep feat(m17) shows 99859f9. HN9-M17-AUTO-OUTPUT.md on that commit is STATUS: PASS — MERGED. The working tree before the branch was only untracked logs/. phpunit.xml keeps DB_CONNECTION=sqlite and DB_DATABASE=:memory:. Branch m18/retry-bulk-progress was created from that commit. The idle worktree C:\laragon\www\Hn9-Studio-m19 was not used.

Implementation: Every user-started generation is an attempt, ordered by job id. retries_used counts jobs whose metadata has retry_of. The runner’s retry_count is exposed as provider_retries. A failed or cancelled latest job with a non-retry intent returns 409 story_unit_generation_failed instead of the old job. retry() checks that the latest job is failed or cancelled, that the reason is retryable, and that retries_used is under max_user_retries_per_unit, then calls generate() with intent retry- plus the first 24 hex characters of the failed job uuid. generate() checks preview_hash before the M13 budget guard, then the M17 composer and lock. A matching hash on a tiny budget returns 422 story_budget_exceeded with no job and no HTTP. A stale hash returns 409 story_prompt_preview_stale before the guard. The retry preview is the M17 unit preview with that intent and adds retry.allowed, attempt_number_next, and retries_left. It writes nothing. Two calls for the same failed job share one idempotency key. A later failure of that retry gets a new intent. StoryFailureReason maps known codes to plain sentences. Content-policy text and InvalidInput, CapabilityUnsupported, CapabilityNotAvailable, AuthenticationFailed, and GenerationNotEnabled are not retryable. Raw provider bodies are not returned.

Bulk kinds are unit_version, scene_audio, character_reference, and style_reference. plan_version, reel, and render return 422 story_bulk_kind_invalid. preview() does not write. Two versions of the same unit are conflict and neither is approved. selection_hash is sha256 of kind, scope, and the sorted eligible ids. A mismatch is 409 story_bulk_selection_changed. More than 50 or an empty list is 422 story_bulk_selection_invalid. A held lock is 409 story_bulk_in_progress. Each eligible id is approved by the existing approve method in its own try/catch. next_paid_step is informational. Character locking says it is free. Without a video rate the unit estimate is available false. Nothing calls the budget guard or a provider.

Progress stages use weights 8, 8, 8, 8, 40, 7, 7, 7, 7. A failed or cancelled latest job forces Production to blocked with the hint “N units need a retry”. The render block reports status, elapsed seconds while queued, rendering, or processing, and a sanitized error. Paths are replaced with “The render failed.” build() is uncached for the query-count test and stayed at or under 45 queries. The HTTP response is cached for 5 seconds. The header bar polls every 10 seconds while active is true and every 60 seconds otherwise, and again when the window is focused. The existing stepper still uses its own stepProgress().

Files: Backend new: StoryFailureReason, StoryUnitRetryException, StoryBulkApproveException, StoryBulkApproveService, StoryProgressService, StoryBulkApproveController, StoryProgressController, config/story_retry.php, config/story_bulk.php, config/story_progress.php, StoryUnitRetryTest, StoryBulkApproveTest, StoryProgressApiTest, StoryFailureReasonTest. Backend modified: StoryProductionUnitGenerationService (retry and the failed-intent check only), StoryProductionUnitGenerationController, StoryPromptPreviewController, routes/api/v1.php. Frontend new: BulkApproveBar, StudioProgressBar, storyRetryService, storyBulkApproveService, storyProgressService, studioMessagesM18. Frontend modified: SceneProduction, PromptPreviewModal, StudioCastStep, StudioReferencePictures, StudioSoundStep, ProjectStoryPage, productionClips, productionClips.test, studioMessages.

API:
- GET story/projects/{uuid}/production-plans/{planUuid}/units/{unitUuid}/attempts throttle 60,1. Owner or admin via the story generate policy. Newest 20 attempts.
- POST .../units/{unitUuid}/retry throttle generation. Optional capability, aspect_ratio, instruction, inputs, preview_hash. 201 when created, 200 when the same retry job already exists.
- POST .../units/{unitUuid}/retry/prompt-preview throttle 60,1. No writes.
- POST story/projects/{uuid}/bulk-approve/preview throttle 60,1 and POST .../bulk-approve throttle 30,1. select on the workspace. unit_version also review on the plan. scene_audio also review on the reel when it is found.
- GET story/projects/{uuid}/progress throttle 120,1. Read only.
Anonymous requests are 401. Another member is 403. Error codes: story_unit_generation_failed, story_unit_retry_not_allowed, story_unit_retry_not_retryable, story_unit_retry_limit, story_prompt_preview_stale, story_budget_exceeded, story_bulk_kind_invalid, story_bulk_selection_invalid, story_bulk_selection_changed, story_bulk_in_progress.

Database: none. story_video_jobs_unit_created_index on (story_production_unit_id, created_at) already exists. Attempts are ordered by id. Migration not required.

Tests: StoryUnitRetryTest 6, StoryBulkApproveTest 5, StoryProgressApiTest 2, StoryFailureReasonTest 1. Scenarios 1–15 passed in those tests: same-intent failure is 409, completed and in-flight repeats stay the same job, retry creates one new job and a later failure gets a new intent, budget and stale hash stop before HTTP, retry preview does not call the guard, non-retryable and limit errors, attempts hide raw provider text, an existing retry key is returned, bulk eligibility and hash and lock, invalid kinds, cross-project ids skipped, progress counts and the render block, auth and throttles. Http::fake and Http::preventStrayRequests. No existing assertion was weakened. The cancel action on the generation controller was restored after a bad edit removed the cancel call; StoryProductionUnitGenerationTest covers it again.

Build: npm run build passed (built in 18.00s). node --test src/services/productionClips.test.js: 13 passed (was 12). The new helper is unitAttemptState.

Migration: not required. MySQL was not migrated.

Security: New routes use the existing story policy. Bulk items also pass Gate review. Cross-project ids are not_in_scope. Retry spends only inside generate() after the preview hash check. Retry preview, bulk preview, bulk approve, and progress do not call the guard or a provider. Failure text and the progress error do not include raw provider bodies or file paths. Activity story.unit_generation.retry_requested stores unit uuid, from job uuid, attempt number, and reason code. Secret scan of the sprint files found only the test placeholder sk-live-secret, which the assertion checks is absent from the reason. No xi-api-key value, private key, or C:\Users\ path was added.

Regression: Full suite before 1095 passed, 1 skipped, 17418 assertions. After: 1109 passed, 1 skipped, 17659 assertions. Story filter 518 passed, 1 skipped, 14767 assertions (was 504 / 1 / 14526). Provider 346 passed, 1681 assertions (unchanged). StoryCostBudgetTest 11 / 68. StoryPlanner 10 / 62. StoryQuickStart|StoryTemplateCatalogTest 15 / 155. StoryVoiceTiming|StorySubtitle|ElevenLabsTimestamps 22 / 268. StoryVersionRestore|StoryVoiceLanguage|StoryRenderQueue|NotificationsApi 27 / 176. StoryCharacterLock|StoryPromptPreview|StoryLockedGeneration|StoryVideoPromptComposer 20 / 172 (identity-card tests stay in the full suite). StoryProductionUnit|StoryBulkApprove|StoryUnitRetry|StoryProgress 47 / 528. A first overlapping run of these filters collided on shared media files and was discarded. The numbers above are from isolated runs. The full suite was one process.

Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: The failed-unit regeneration that reused the same intent is CLOSED. Picture generations are still missing from the job-based spend summary. Queued unsent estimates are omitted from the public total. The plan text rate stays unset until STORY_COST_TEXT_PER_1K_TOKENS. Luma and Seedance keys are not verifiable. Claude and OpenRouter are not editable in the keys screen. eleven_v3 may bill differently from the single configured audio rate. The M15 wizard, M16 panels, M17 lock and preview UI, and this M18 retry, bulk approve, and progress bar were not click-tested (no browser tools; npm run build and node --test were run). Existing voiceovers only approximate subtitle timing. Subtitles are not in the export zip. An AI face-match after a clip, and seeds, stay later improvements. FFmpeg percent-progress is a later improvement. The render pill shows status and elapsed time only.

#### GIT INTEGRATION

Sprint Branch: m18/retry-bulk-progress
Commit Message: feat(m18): add failed-unit retry, bulk approve and progress bar
Files Committed: the M18 backend and frontend files listed above, plus this output file. logs/ was not staged.
Secret Scan: placeholders only
Branch Push: recorded after the push
Origin/Main Re-sync: recorded after fetch
Post-Sync Tests: not required when origin/main had not moved
Merge: Merge branch 'm18/retry-bulk-progress'
Main Push: recorded after the push
Local Main / Origin/Main: recorded after the push
MySQL Migrate: not required
Working Tree: logs/ untracked only
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: M20 (awaiting user review — do not start)
