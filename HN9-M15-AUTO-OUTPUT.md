# HN9 M15 AUTO EXECUTION

CURRENT_SPRINT: M15
STATUS: PASS — MERGED
ACTION:

### M15

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY
A person can open an empty Story project and use Quick start. They pick Company journey, Product ad, or Reel, type a topic, and choose 40 seconds, 1 minute, or 3 minutes, plus aspect ratio, language, tone, and style. Create draft fills the story details, the look, and draft characters, then writes one story plan and generates exactly one plan version. The plan stops at Ready for review. Nothing is approved, and no pictures, video, audio, or renders are made. Before that click, the review step shows the plan-step price, or "Price estimate not available" until a text rate is set, plus a rough full-video estimate that is not charged and does not change the budget. If no text AI is connected, the draft is still saved and the plan stays a draft. If the plan would pass the project budget, the draft stays and the plan is not generated.

From HN9-AI-Studio/Backend, with Laragon PHP on PATH:
1. If not done yet: php artisan hn9:user-role test@example.com admin, then log out/in.
2. In "AI services", save and test the OpenAI key (needed for the plan step).
3. Optional, to see the plan-step price: add STORY_COST_TEXT_PER_1K_TOKENS=<rate> to Backend/.env, then restart php artisan serve.
4. Open the Hn9 Journey project → Quick start → Company journey → 40 sec → Create draft → review the plan.

The Story step also has Apply template, which fills only empty fields and does not save. Browser tools were not available in this session, so the wizard was checked through the API tests and the frontend production build, not by clicking through the running app.

#### FULL TECHNICAL RESULT
Implementation: StoryQuickStartService writes through StoryBibleService, StoryStyleBibleService, StoryCharacterService, and StoryPlannerService::create, then calls StoryPlannerService::generate(). The M13 StoryBudgetGuard runs inside generate(), before the version row and before the text dispatch. A Cache lock story-quick-start:{workspace_id} lasts 120 seconds and returns 409 story_quick_start_in_progress when it is already held. An idempotency_key is cached for 30 minutes as story-quick-start-result:{workspace}:{key}; the same key returns the first response and does not generate again. A 422 budget response is not cached. Without overwrite, a non-empty concept or any non-archived plan returns 409 story_quick_start_conflict and the transaction rolls back. Overwrite updates the listed bible fields, writes the style, skips character names that already exist (including archived names and approved references), creates a new plan, and leaves old plans and characters in place. Planner failure on quick start is HTTP 200 with plan_generation "failed" so the draft stays visible. A direct plan generate call still returns 502. Budget stays 422 story_budget_exceeded, with drafts_saved added to the existing exception context. The handler in bootstrap/app.php was not changed. After a successful plan, names in the scenes that are not already characters are created as draft, at most 8 new names, with no extra provider call. The activity log story.quick_start stores the template key, duration, outcome, and at most 80 characters of the topic. It does not store the prompt.
Templates:

| key | default duration | aspect | tone | fields | characters |
| --- | --- | --- | --- | --- | --- |
| company_journey | 180 | 16:9 | Inspiring | company_name required, milestones, vision | Founder, Team |
| product_ad | 40 | 16:9 | Energetic | product_name required, key_benefits required, call_to_action | Customer |
| reel | 40 | 9:16 | Punchy | hook, call_to_action | none |

Files: Backend new: story_templates.php, StoryTemplateCatalog.php, StoryQuickStartException.php, StoryQuickStartService.php, QuickStartStoryRequest.php, StoryQuickStartController.php, StoryQuickStartTest.php, StoryTemplateCatalogTest.php. Backend modified: StoryCostEstimator.php (production_preview only), routes/api/v1.php. Frontend new: QuickVideoWizard.jsx. Frontend modified: storyService.js, studioMessages.js, StudioStoryStep.jsx (Quick start, Apply template, 40 seconds). Control: HN9-M15-AUTO-OUTPUT.md.
API: Inside auth:sanctum. GET /api/v1/story/templates (api.v1.story.templates.index) returns templates without idea_scaffold, durations [40, 60, 180], aspects [16:9, 9:16], languages ["", en, hi, gu]. POST /api/v1/story/projects/{uuid}/quick-start/estimate (api.v1.story.projects.quick-start.estimate) throttle:60,1, policy select on the project. POST /api/v1/story/projects/{uuid}/quick-start (api.v1.story.projects.quick-start.store) throttle:generation, same policy. Request: template, topic 3–2000, duration_seconds in 40/60/180, optional aspect_ratio, language, tone, style, audience, up to 4 characters, template fields, overwrite, idempotency_key. Unknown template, duration 45, aspect 1:1, language xx, 5 characters, a missing required field, or an unknown field key is 422. Success data: template, template_key, bible, style, characters_created, characters_skipped, plan (id, status, current_version_id, review_status), plan_generation, plan_estimate, error, next. Estimate data: plan_estimate, full_video_preview (label "rough full-video estimate"), budget, text_connected. Error codes: story_quick_start_conflict 409, story_quick_start_in_progress 409, story_template_not_found 404, story_budget_exceeded 422. Anonymous 401. Another member 403.
Database: No migration.
Tests: StoryQuickStartTest.php and StoryTemplateCatalogTest.php. 15 passed, 155 assertions, Http::fake only. Scenarios 1–14 pass. A direct generate() on a second project created the same reel and scene counts as quick start (zero). With no text rate, plan_estimate.available is false and generation is not blocked. With the rate set and a 0.01 USD budget, generation returns 422, drafts_saved true, zero dispatches, and no version row.
Build: npm run build passed (existing chunk-size warning only). node --test src/services/productionClips.test.js — 12 passed. No browser tool was available, so the wizard click-path was not exercised in a browser.
Migration: not required
Security: Anonymous 401 and another member 403 are asserted. Both POST routes carry their throttle middleware. The budget guard is the unchanged M13 guard inside StoryPlannerService::generate(). The activity-log assertion shows the topic is at most 80 characters and the prompt marker is absent. The failure response does not contain sk-test-openai-key. M12, M13, and M14 tests stayed green. Secret scan of the sprint files found only the existing placeholder sk-test-openai-key. .env was not edited.
Regression: php artisan test — 1023 passed, 1 skipped, 16566 assertions (M14 baseline 1008 passed, 1 skipped, 16387 assertions). php artisan test --filter Story — 436 passed, 1 skipped, 13713 assertions (baseline 421 passed, 1 skipped, 13534 assertions). php artisan test --filter Provider — 343 passed, 1653 assertions (baseline 341 passed, 1634 assertions; the two extra matches are quick-start tests whose names contain "provider"). php artisan test --filter StoryCostBudgetTest — 11 passed, 68 assertions. php artisan test --filter StoryPlanner — 10 passed, 62 assertions.
Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: Plan text uses ceil(characters/4) and stays unavailable until STORY_COST_TEXT_PER_1K_TOKENS. Image generations are still missing from the job-based spend summary. Queued jobs that have not been sent are omitted from the public estimated total. Luma and Seedance key checks are not verifiable. Claude and OpenRouter are not editable in the keys screen.

#### GIT INTEGRATION
Sprint Branch: m15/quick-video-wizard
Commit: this file is inside the single sprint commit. Identify it with `git log main --grep "feat(m15)"`.
Commit Message: feat(m15): add quick video wizard and story templates
Files Committed: the Backend, Frontend, and control files listed above. logs/, Frontend/dist, and .env were not staged.
Secret Scan: PASS
Push: origin m15/quick-video-wizard
Main Sync: origin/main a8e01346f4c260711c11c4a9a98f1ada6c424b7f, fast-forward already current
Merge: --no-ff into main
Main Push: origin main
Local Main: no-ff merge of m15/quick-video-wizard; git rev-parse main equals origin/main
Origin/Main: equal to local main
Working Tree: clean except pre-existing untracked logs/
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: M16 (awaiting user review — do not start)
