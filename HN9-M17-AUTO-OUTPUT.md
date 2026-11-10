# HN9 M17 AUTO EXECUTION

CURRENT_SPRINT: M17
STATUS: PASS — MERGED
ACTION:

### M17

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY
In the Cast step, approve one reference picture for a character, then click Lock look. The lock freezes that character's look. New clips for scenes that name the character include a short identity card in the prompt. Image-to-video sends the locked picture when you did not choose one yourself. Reference-to-video sends locked pictures up to what that provider accepts. Text-to-video, including Runway text-to-video, uses only the text lock and says so in the preview. Unlock lets you edit the look again. The lock history stays.

Generate on a clip, and Regenerate scene, open a preview first. The preview shows the provider, model, mode, the final prompt, the pictures, the price estimate, and the remaining budget. The preview is free. On a clip you can edit the scene part, save it, or reset it to the automatic text. The lock text stays even if you edit the scene part. Generate sends the preview along. If the prompt, picture, price, or provider changed, the app says the prompt changed since your preview and keeps the preview open. A clip shows Lock applied, Made without lock, or Lock changed since.

From HN9-AI-Studio/Backend, with Laragon PHP on PATH:
1. php artisan migrate (the merge gate runs this; migrate:status must show the three 2026_11_14 tables as Ran).
2. Cast step: approve one reference picture per main character, then click Lock look on each.
3. Before paying for a clip, read the preview. Generate only when the prompt and price look right.
4. Optional cleanup (M19 is merged): cd C:\laragon\www\Hn9-Studio; git worktree remove --force ..\Hn9-Studio-m19

No dev server was started. The screens were checked by the production build and the API tests, not by clicking the running app.

#### FULL TECHNICAL RESULT
Start Check: Pre-step `git diff --ignore-cr-at-eol --stat` showed no content change. `git checkout -- .` and deletion of the untracked HN9-M17-AUTO-OUTPUT.md and HN9-M18-AUTO-OUTPUT.md left only `?? logs/`. Branch `m17/character-lock-prompt-preview` was created from origin/main `79ee6d7728a83873e03ee825b41693a9ea9030cd` (Merge branch 'm19/versions-voice-render'). Worktree list is the main folder and the idle `C:\laragon\www\Hn9-Studio-m19` on `m19/versions-voice-render`. That worktree was not opened. phpunit.xml sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:. Tests did not use MySQL.

Implementation: A character locks only with an approved reference. Locking does not approve a picture. The identity card is name, appearance, face, hair, clothing, and special details, empties skipped, control characters stripped, cut at a word boundary at 300 characters. Scene matching uses `story_scenes.characters` names, case-insensitive and trimmed, in list order. More than 4 locked characters (config `max_locked_per_scene`) stay in the preview as `not_sent` / `too_many`. Look fields and the name return 409 `story_character_locked` until unlock. Personality stays editable. Archive of a locked character, and reject or archive of the locked reference, return 409. Unlock deletes the active row after writing `story_character_lock_events`.

The composer builds the unit base as visual, motion, story, and Instruction joined by blank lines. A saved edit replaces that base and cannot remove the lock block. Scene regenerate base is the user prompt, otherwise visual, story, or title. Provider limits: Runway 1000, Luma 6000, Seedance and Gemini no text limit. Over the limit, the lock block is kept and the base is shortened story, then motion, then visual. If the lock block alone is over the limit, cards are shortened evenly and the warning is `lock_too_long`. Image-to-video adds the first locked reference only when the user gave no image (Runway and Luma limit 1). Reference-to-video adds locked references up to the adapter limit (Seedance 9, Gemini 1, Runway and Luma 0). Text-to-video adds no picture and warns `references_not_supported_by_mode`. With no lock and no edit, the stored prompt equals the old join and the request is not rebuilt.

Preview calls `draft()` / `regenerateDraft()` only. Those route and estimate. They do not call `StoryBudgetGuard`, do not open a job or version, and do not call a provider. The first `assets->resolve()` is skipped when image or reference mode has no picture yet, so a lock picture can be added before the ownership check. The preview hash is sha256 of provider, model, capability, duration, aspect, final prompt, input asset ids, and the M13 price (`number_format` to 4 decimals, or `unavailable`). Price is included so a rate change invalidates the preview. A present `preview_hash` that does not match returns 409 `story_prompt_preview_stale` before `precheckBudget()` and before `regenerateScene()` calls the guard. Absent hash keeps the old generate path. The M13 guard still runs in `precheckBudget()`, in `regenerateScene()` after the hash check, and inside `StoryVideoEngine::prepareJob` before the job row. A new job stores `lock_provenance` (locks, prompt hash, edited, references sent). `lock_status` is `applied`, `none`, or `changed_since`. `editScene` and `extendScene` were left on the existing cost confirm path.

Files: Backend new: config/story_character_lock.php; migrations 2026_11_14_000001, 000002, 000003; StoryCharacterLock, StoryCharacterLockEvent, StoryUnitPromptEdit; StoryCharacterLockException, StoryPromptPreviewException; StoryCharacterLockService, StoryPromptPreviewService; StoryCharacterIdentityCard, StoryComposedPrompt, StoryLockStatus, StoryPromptHash, StoryVideoPromptComposer; StoryCharacterLockController, StoryPromptPreviewController; StoryCharacterLockTest, StoryPromptPreviewTest, StoryLockedGenerationTest, StoryCharacterIdentityCardTest, StoryVideoPromptComposerTest. Backend modified: StoryCharacter, StoryProductionUnit, StoryCharacterService, StoryCharacterReferenceService, StoryProductionUnitGenerationService, StoryProductionUnitVersionService, StoryReviewService, StoryProductionUnitGenerationController, StoryReviewController, GenerateStoryProductionUnitRequest, StoryCharacterResource, StoryProductionUnitGenerationResource, Runway/Luma/Seedance/Gemini/Http adapters (maxPromptLength and referenceImageLimit only), routes/api/v1.php, ContractStoryVideoAdapter. Frontend new: CharacterLockControl.jsx, PromptPreviewModal.jsx, storyCharacterLockService.js, storyPromptPreviewService.js, studioMessagesM17.js. Frontend modified: StudioCastStep.jsx, SceneProduction.jsx, StudioScenesStep.jsx, storyService.js (storyGet and storyWrite exported; regenerateStoryScene sends preview_hash), studioMessages.js. Control: HN9-M17-AUTO-OUTPUT.md. No file from section 0.1 was edited.

API: All inside auth:sanctum. Names are prefixed api.v1. POST/DELETE `story/projects/{uuid}/characters/{characterUuid}/lock` throttle 30,1. Policy select on the workspace. GET `story/projects/{uuid}/character-locks` throttle 60,1. POST `.../production-plans/{planUuid}/units/{unitUuid}/prompt-preview` throttle 60,1. Policy select plus generate on the plan. PUT/DELETE `.../units/{unitUuid}/prompt` throttle 30,1. POST `.../reels/{reelUuid}/scenes/{sceneUuid}/regenerate/prompt-preview` throttle 60,1. Policy select plus review on the scene. Generate accepts optional preview_hash size 64. Preview returns provider, model, capability, duration, aspect, prompt, locks, inputs, warnings, estimate, budget (including would_exceed), routing, preview_hash, idempotent_repeat. Unmatched routing is 200 with matched false. Error codes: story_character_lock_needs_reference 422, story_character_locked 409, story_character_reference_locked 409, story_prompt_preview_stale 409, story_unit_prompt_invalid 422, story_budget_exceeded 422. A reference from another character is 404. A reference from another project is 422. Anonymous 401. Another member 403.

Database: 2026_11_14_000001 story_character_locks (uuid unique, workspace cascade, character unique cascade, reference restrict, identity_text, identity_hash 64, locked_fields json, locked_by nullOnDelete, locked_at, timestamps). 2026_11_14_000002 story_character_lock_events (character cascade, action, identity_hash, reference_id, actor nullOnDelete, created_at). 2026_11_14_000003 story_unit_prompt_edits (unit unique cascade, prompt, base_hash, edited_by nullOnDelete, timestamps). down() drops only these tables. Timestamps are after M19 2026_11_13_000004. Existing migrations were not edited. MySQL evidence is in GIT INTEGRATION.

Tests: Five new files, 22 tests, all inside the full suite. Scenarios 1–17 pass. Byte-identical: with no lock and no edit, the job prompt equals the visual/motion/story join and does not contain "Keep these characters". The composer unit test matches that join. Existing Runway, Luma, and Seedance adapter tests passed in the full suite. Http::preventStrayRequests is on the base TestCase. Http::fake only. A 0.01 INR budget with a configured contract video rate makes preview.would_exceed true and a matching hash return 422 story_budget_exceeded with zero jobs and no HTTP. A bad hash on that budget returns 409, so the guard is not first.

Build: npm run build passed (existing chunk-size warning only). node --test src/services/productionClips.test.js — 12 passed.

Migration: Pretend listed only the three 2026_11_14 CREATE TABLE statements plus their foreign keys, unique indexes, and the lock-events index. php artisan migrate ran those three (batch 7). migrate:status shows them Ran. No Pending rows.

Security: Owner or admin through the existing story policy. Preview creates no job, unit version, scene version, or activity row and does not call the budget guard. The guard still runs before any job row or provider HTTP. Activity logs store character uuid, reference uuid, and identity hash, not the identity text. Prompt text is stripped of control characters. Lock inputs go through ownedInputs and assets->resolve. Secret scan of the candidate files found no sk- key, private key, Bearer token, or local user path. .env was not staged. Section 0.1 files were not edited. M12–M16 and M19 tests stayed green inside the full suite.

Regression: Baseline after M19 was php artisan test — 1073 passed, 1 skipped, 17162 assertions. After M17: 1095 passed, 1 skipped, 17418 assertions. Story — 504 passed, 1 skipped, 14526 assertions (baseline 482 passed, 1 skipped, 14270 assertions). Provider filter — 346 passed, 1681 assertions (baseline 343; the filter also matches new test names that contain "provider"). StoryCostBudgetTest — 11 passed, 68 assertions. StoryPlanner — 10 passed, 62 assertions. StoryQuickStart|StoryTemplateCatalogTest — 15 passed, 155 assertions. StoryVoiceTiming|StorySubtitle|ElevenLabsTimestamps — 22 passed, 268 assertions. StoryVersionRestore|StoryVoiceLanguage|StoryRenderQueue|NotificationsApi — 27 passed, 176 assertions. StoryCharacter|StoryProductionUnit|StoryReview — 59 passed, 528 assertions.

Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: Picture generations are still missing from the job-based spend summary. Queued unsent estimates are omitted from the public total. The plan text rate stays unset until STORY_COST_TEXT_PER_1K_TOKENS. Luma and Seedance keys are not verifiable. Claude and OpenRouter are not editable in the keys screen. A failed unit regeneration reuses the same intent (M18). eleven_v3 may bill differently from the single configured audio rate (M19). The M15 wizard, M16 panels, and this M17 lock and preview UI were not click-tested. Existing voiceovers only approximate subtitle timing. Subtitles are not in the export zip. An AI face-match after a clip, and seeds, stay later improvements and were not added.

#### GIT INTEGRATION
Sprint Branch: m17/character-lock-prompt-preview
Commit: 99859f9 feat(m17): add character lock and prompt preview before generation
Commit Message: feat(m17): add character lock and prompt preview before generation
Files Committed: the Backend, Frontend, and control files listed above. logs/, Frontend/dist, vendor/, node_modules/, storage media, and .env were not staged.
Secret Scan: PASS. The only "sk-" hits were the word "task-based" and this report's own "no sk- key" sentence.
Branch Push: origin m17/character-lock-prompt-preview at 99859f9
Origin/Main Re-sync: origin/main stayed at 79ee6d7728a83873e03ee825b41693a9ea9030cd. No merge into the branch. No conflicts.
Post-Sync Tests: not rerun. origin/main did not move after the suite.
Merge: no-ff merge of m17/character-lock-prompt-preview into main. Message: Merge branch 'm17/character-lock-prompt-preview'
Main Push: origin main, this merge commit
Local Main / Origin/Main: equal after the push
MySQL Migrate: pretend was CREATE TABLE only for story_character_locks, story_character_lock_events, and story_unit_prompt_edits, plus the foreign keys and indexes those creates add. migrate DONE, batch 7. migrate:status Ran. No Pending.
Working Tree: clean except ?? logs/
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: M18 (awaiting user review — do not start)
