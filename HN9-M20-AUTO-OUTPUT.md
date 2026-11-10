# HN9 M20 AUTO EXECUTION

CURRENT_SPRINT: M20
STATUS: PASS — MERGED
ACTION:

### M20

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS — MERGED
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY

The studio now has a setup guide, a product roadmap, and a click-through checklist. The guide is HN9-AI-Studio/README.md. A short pointer sits at the repository root. The roadmap is HN9-AI-Studio/ROADMAP.md. The checklist is HN9-AI-Studio/docs/QA-CHECKLIST.md.

The automated pass ran the full test suite, the earlier story filters one at a time, the production build, and a route check. No screen bug turned up in that pass, because the screens themselves are for you to click. Two small gaps that were already listed are now done, each with a test. An approved export zip includes the latest subtitle files when a track exists. The spend summary counts generated reference pictures, and it leaves uploaded pictures out. When no image rate is set, those pictures stay "not priced" and the spent total does not change.

Still open: queued video estimates that were never sent stay out of the public total, plan text has no price until a text rate is set, Luma and Seedance cannot be test-connected, and Claude and OpenRouter cannot be edited in AI services. The checklist covers the screens that were not click-tested in earlier sprints.

From the repository root:
1. Open HN9-AI-Studio/docs/QA-CHECKLIST.md and click through it. Start with the free-only smoke path.
2. If you have not yet: from HN9-AI-Studio/Backend, php artisan hn9:user-role test@example.com admin, then log out and log in. Save and test the OpenAI key in AI services.
3. Optional cleanup: cd C:\laragon\www\Hn9-Studio; git worktree remove --force ..\Hn9-Studio-m19
4. php artisan migrate is not required. This sprint added no migration.

#### FULL TECHNICAL RESULT

Preflight: git fetch. Branch was main. main and origin/main were 6f06e8a759a73420f29caa8ac5c0fb128e709ddb, the M18 merge. git log origin/main --grep feat(m18) shows dbbf8f3. HN9-M18-AUTO-OUTPUT.md on that commit is STATUS: PASS — MERGED. Working tree was only untracked logs/. HN9-M20-AUTO-OUTPUT.md did not exist. git worktree list was this folder and the idle C:\laragon\www\Hn9-Studio-m19 at 566dfe3 on m19/versions-voice-render. That worktree was not opened. Branch m20/docs-roadmap-qa was created from origin/main. phpunit.xml keeps DB_CONNECTION=sqlite and DB_DATABASE=:memory:. Tests did not use MySQL.

Docs: HN9-AI-Studio/README.md rewritten from the code (setup, run, roles, AI services, env names, FFmpeg, M13–M19 tour, tests, troubleshooting, history). Root README.md points at it. HN9-AI-Studio/ROADMAP.md lists Done, open issues, and later work. Documentation/Project Roadmap.md is now a pointer. Documentation/README.md points at the roadmap. Documentation/API.md states Sanctum auth and lists the M13–M19 routes taken from routes/api/v1.php. Commands were checked against the artisan command classes, config defaults, package.json, vite.config.js port 5173, and Frontend/.env.example. No schedule() call exists in bootstrap/app.php or routes/console.php, so the recovery commands are documented as manual. M12 history uses merge 7243a04 and security commit 4559a5e, confirmed with git log.

QA Automated:
- php artisan test: 1112 passed, 1 skipped, 17833 assertions. The skip is StoryProductionPlanSchemaTest ("SQLite cannot add CHECK constraints to an existing table").
- Story filter: 521 passed, 1 skipped, 14941 assertions (was 518 / 1 / 14767; the three new tests match this filter).
- Provider: 346 passed, 1681 assertions.
- StoryCostBudgetTest: 12 passed, 78 assertions (was 11 / 68).
- StoryPlanner: 10 passed, 62 assertions.
- StoryQuickStart|StoryTemplateCatalogTest: 15 passed, 155 assertions.
- StoryVoiceTiming|StorySubtitle|ElevenLabsTimestamps: 22 passed, 268 assertions.
- StoryVersionRestore|StoryVoiceLanguage|StoryRenderQueue|NotificationsApi: 27 passed, 176 assertions.
- StoryCharacterLock|StoryPromptPreview|StoryLockedGeneration|StoryVideoPromptComposer: 20 passed, 172 assertions.
- StoryProductionUnit|StoryBulkApprove|StoryUnitRetry|StoryProgress: 47 passed, 528 assertions.
- StoryExport|StorySpend|StoryRouteSmoke: 6 passed, 279 assertions. Before the subtitle change, StoryExportApiTest was 4 tests and all of those assertions still pass. After: 5 export tests plus StoryRouteSmokeTest.
- npm run build: passed (built in 10.12s, existing chunk-size warning).
- node --test src/services/productionClips.test.js: 13 passed, 0 failed.
- php artisan migrate:status: every migration Ran. Latest batch is 7 (2026_11_14). Nothing pending. No migrate was run.
- StoryRouteSmokeTest asserts the M13–M19 route names from routes/api/v1.php, auth:sanctum, throttle:generation on quick start, subtitle burn, unit retry, and provider test, numeric throttles where the route defines one, and 401 for anonymous estimate, progress, retry, voice languages, and read-all.

QA Findings:

| # | Area | Finding | Severity | Fixed? | Test | File |
| --- | --- | --- | --- | --- | --- | --- |
| — | — | No product bug found in the automated pass. Browser steps are in the checklist. | — | — | — | — |

Optional Fixes:
- D1 done. StoryExportService adds the latest subtitle track's .srt and .vtt under subtitles/subtitles.srt and subtitles/subtitles.vtt. Disk paths are not written into the manifest. A render with no track is unchanged. Test: StoryExportApiTest::test_export_includes_the_latest_subtitle_files_without_disk_paths.
- D2 done. StorySpendService counts generated character and style reference pictures. Uploaded pictures are ignored. A missing STORY_COST_IMAGE_PER_IMAGE increments estimate_unavailable_jobs and does not change spent_total_usd. A numeric rate adds that many times the rate. Test: StoryCostBudgetTest::test_summary_prices_generated_reference_pictures_and_skips_uploads. Existing spend assertions still pass.

.env.example: appended names with the config defaults or empty values. Additions only (git diff has no removed lines). Groups: voice language, voice timing, subtitles, render queue, video queue, retry limit. No values were copied from .env. STORY_SUBTITLE_FONT_FILE is empty so the config default applies.

Files: New: README.md, HN9-AI-Studio/ROADMAP.md, HN9-AI-Studio/docs/QA-CHECKLIST.md, HN9-AI-Studio/Backend/tests/Feature/StoryRouteSmokeTest.php, HN9-M20-AUTO-OUTPUT.md. Modified: HN9-AI-Studio/README.md, Documentation/API.md, Documentation/Project Roadmap.md, Documentation/README.md, Backend/.env.example, StoryExportService.php, StorySpendService.php, StoryExportApiTest.php, StoryCostBudgetTest.php.

Tests: StoryRouteSmokeTest 1 test, 130 assertions. Export subtitle test and image-spend test as above. Full suite 1112 passed, 1 skipped, 17833 assertions.

Build: npm run build passed in 10.12s. node --test 13 passed.

Security: Secret scan of the sprint docs, .env.example, and the two services found no sk- value, xi-api-key value, private key, AKIA key, or C:\Users\ path. README paths are C:\laragon\www\Hn9-Studio and C:\Windows\Fonts\Nirmala.ttc, plus a C:\path\to\ffmpeg.exe placeholder. Provider/API calls during this sprint: 0. No destructive git commands. The m19 worktree was not entered.

Regression: Baseline 1109 passed, 1 skipped, 17659 assertions. After: 1112 passed, 1 skipped, 17833 assertions. Filter totals are listed under QA Automated. Filters ran one process at a time after the full suite. The full suite was one process.

Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: Queued unsent video estimates stay in reserved_usd. Plan text is unpriced until STORY_COST_TEXT_PER_1K_TOKENS. Luma and Seedance Test connection is not verifiable. Claude and OpenRouter are not editable. eleven_v3 may bill differently from the single audio rate. Older voiceovers still use approximate subtitle timing. M15–M19 screens still need the checklist click-through. Generated pictures are counted only when an image rate is set; otherwise they are not priced. Later: face-match, seeds, reported image cost, queued estimates in the public total, Claude/OpenRouter editing, Luma/Seedance verification, FFmpeg percent progress, forced alignment.

#### GIT INTEGRATION

Sprint Branch: m20/docs-roadmap-qa
Commit: be6c51d75425433d8d439d2f8ebb34789621c7d3
Commit Message: docs(m20): add README, roadmap and QA checklist
Files Committed: the files listed above. logs/ and Frontend/dist were not staged.
Secret Scan: names and placeholders only
Branch Push: origin/m20/docs-roadmap-qa at be6c51d75425433d8d439d2f8ebb34789621c7d3
Origin/Main Re-sync: origin/main was still 6f06e8a759a73420f29caa8ac5c0fb128e709ddb. No re-sync merge.
Post-Sync Tests: not required. origin/main had not moved.
Merge: Merge branch 'm20/docs-roadmap-qa'
Main Push: origin/main after this merge
Local Main: equal to origin/main after the main push
Origin/Main: equal to local main after the main push
MySQL Migrate: not required
Working Tree: logs/ untracked only
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: NONE (M13–M20 plan complete; await user review)
