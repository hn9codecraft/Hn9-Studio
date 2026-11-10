# HN9 M13 AUTO EXECUTION

CURRENT_SPRINT: M13
STATUS: PASS — MERGED
ACTION:

### M13

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY
Before a paid Creative Studio action is sent, the project shows an estimated price in rupees and dollars. The person working on the story can set a budget for that project from Studio settings. If the next estimate would pass the budget, the studio stops it and sends nothing. Spend for the project is shown in the studio header and on the History page, split by provider, with the provider's reported cost kept separate from the estimate. Prices for Luma, Seedance, pictures, and story-plan text stay "not available" until a rate is configured, and those actions are not blocked for a missing price. No payment is collected and no provider account is charged by this change. The estimate is a guide from the rate table, with a 10 percent upper bound used only for the budget check.

#### FULL TECHNICAL RESULT
Implementation: The budget check lives in StoryBudgetGuard::assertWithinBudget. It allows the action when the project has no budget or the rate is unknown. Otherwise it compares spent reported cost, estimates already stored on jobs that reached a provider, reserved estimates on jobs not yet sent, and the new high estimate against the budget in USD. StoryVideoEngine::prepareJob locks the workspace row, skips the check when the idempotency key already has a job, stores provider_metadata.cost_estimate, and only then inserts the job. StoryVideoJobRunner::submit runs after that insert. Unit generate: StoryProductionUnitGenerationController::store and StoryProductionUnitGenerationService::precheckBudget (after the existing-intent return, before prepareJob). Scene regenerate: StoryReviewService::regenerateScene before assertLive and before a scene version or dispatch. Scene edit and extend: StoryReviewService::reviseScene after the idempotent return and before a new version. Generic video generate: StoryVideoEngineController::generate (skipped when that idempotency key already exists) then prepareJob per unit. Audio: StoryAudioService::create on the spoken text before assertLive, and again in generate before the audio row. Character and style pictures: StoryCharacterReferenceService::generate and StoryStyleReferenceService::generate immediately before ProviderDispatcher::dispatch. Story plan generate and regenerate: StoryPlannerService::runGeneration after the prompt is built and before the generating version or text dispatch. The estimator uses the routed provider when one matches, otherwise the first preferred provider (video.runway). Edit and extend use that provider's edit model (aleph2 for Runway). Audio is priced per 1,000 characters. A null rate returns available false and does not block. Assumption: plan text tokens are ceil(characters / 4), and only when STORY_COST_TEXT_PER_1K_TOKENS is set. The edit estimate uses the source duration; the provider request duration is unchanged.
Files: Backend new: config/story_cost.php, StoryCostEstimate.php, StoryBudgetExceededException.php, StoryCostEstimator.php, StorySpendService.php, StoryBudgetGuard.php, StoryCostController.php, EstimateStoryCostRequest.php, UpdateStoryBudgetRequest.php, migration 2026_11_10_100000_add_budget_to_story_workspaces_table.php, StoryCostBudgetTest.php. Backend modified: StoryWorkspace.php, StoryVideoEngine.php, StoryProductionUnitGenerationService.php, StoryProductionUnitGenerationController.php, StoryReviewService.php, StoryAudioService.php, StoryCharacterReferenceService.php, StoryStyleReferenceService.php, StoryPlannerService.php, StoryVideoEngineController.php, both job resources, routes/api/v1.php, bootstrap/app.php, .env.example. Frontend new: CostEstimate.jsx, StudioBudgetCard.jsx. Frontend modified: storyService.js, studioMessages.js, SceneProduction.jsx, StudioScenesStep.jsx, StudioSoundStep.jsx, StudioReferencePictures.jsx, StudioSettingsStep.jsx, StudioHistoryStep.jsx, ProjectStoryPage.jsx. Control: HN9-M13-AUTO-OUTPUT.md.
API: POST /api/v1/story/projects/{uuid}/cost/estimate auth:sanctum throttle:60,1. GET /api/v1/story/projects/{uuid}/cost/summary auth:sanctum. PATCH /api/v1/story/projects/{uuid}/budget auth:sanctum. Paid routes keep their URLs, payloads, and throttle:generation. Over budget returns 422 error_code story_budget_exceeded with meta and context. Successful video and unit jobs include cost_estimate amount, currency USD, and available.
Database: 2026_11_10_100000_add_budget_to_story_workspaces_table adds nullable budget_amount decimal(12,2) and budget_currency string(3) default INR on story_workspaces.
Rates Used: STORY_COST_RUNWAY_GEN45_PER_SECOND default 0.12; STORY_COST_RUNWAY_ALEPH2_PER_SECOND default 0.28; STORY_COST_LUMA_PER_SECOND null; STORY_COST_SEEDANCE_PER_SECOND null; STORY_COST_ELEVENLABS_PER_1K_CHARS default 0.08; STORY_COST_IMAGE_PER_IMAGE null; STORY_COST_TEXT_PER_1K_TOKENS null; STORY_COST_SAFETY_MARGIN default 0.10; STORY_COST_DISPLAY_CURRENCY default INR; STORY_COST_USD_INR default 94.
Tests: Backend/tests/Feature/StoryCostBudgetTest.php — 11 passed, 68 assertions. Scenarios 1–10 pass (anonymous 401 is covered with scenario 9).
Build: npm run build passed (existing chunk-size warning only). node --test src/services/productionClips.test.js — 12 passed.
Migration: php artisan migrate ran 2026_11_10_100000_add_budget_to_story_workspaces_table once. migrate:status shows it Ran. migrate:fresh was not used.
Security: Another member receives 403 on estimate, summary, and budget. A scene from another project returns 404. Anonymous estimate returns 401. auth:sanctum and throttle:generation on paid routes were left in place. Secret scan of the sprint files found no API keys, bearer tokens, or private keys. .env was not edited.
Regression: php artisan test — 994 passed, 1 skipped, 16259 assertions. php artisan test --filter Story — 421 passed, 1 skipped, 13502 assertions.
Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: Plan text uses a character-to-token assumption and stays unavailable until a text rate is set. Image generations are guarded but do not appear in the job-based spend summary because they are not video jobs. Queued jobs that have not reached a provider are reserved for the budget lock and are omitted from the public estimated total. M14 API key settings was not started.

#### GIT INTEGRATION
Sprint Branch: m13/cost-estimate-budget
Commit: 5eb37de20fdefa4232868339a53afc1e7df1d139
Commit Message: feat(m13): add cost estimate, project budget and project spend
Files Committed: the Backend, Frontend, and control files listed above. logs/ and Frontend/dist were not staged. This output file records that commit.
Secret Scan: PASS
Push: origin m13/cost-estimate-budget
Main Sync: origin/main cf8ad79ff7f100e9f7aa597570d39cbe4ac96a6d, fast-forward already current
Merge: --no-ff into main
Main Push: origin main
Local Main: no-ff merge of m13/cost-estimate-budget; git rev-parse main equals origin/main
Origin/Main: equal to local main
Working Tree: clean except pre-existing untracked logs/
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: M14 (awaiting user review — do not start)
