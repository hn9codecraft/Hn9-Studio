# HN9-M21-AUTO-OUTPUT

Sprint: M21 — xAI Grok providers and in-app Claude and OpenRouter keys
Started: 2026-11-24
Completed: 2026-11-24
STATUS: PASS — MERGED

#### HUMAN SUMMARY

xAI (Grok) can now write story text, still pictures, 10-second clips, and English or Hindi voice, but only after you turn it on. Gujarati voice stays on ElevenLabs. Claude and OpenRouter keys can be saved, deleted, and Test-connected on the AI services page. A key already set in `.env` still wins.

Do this after the merge:

1. Create an API key on the xAI console API keys page (https://console.x.ai/) — not Cursor usage, and not Grok 4.7 Fast (Cursor-only). Optional: create a Claude key at https://platform.claude.com/settings/keys and/or an OpenRouter key at https://openrouter.ai/settings/keys.
2. From `HN9-AI-Studio/Backend`, with Laragon PHP on PATH: `php artisan db:seed --class=AiProviderSeeder`. Never run `php artisan db:seed` without `--class`.
3. If you have not yet: `php artisan hn9:user-role test@example.com admin`, then log out and log in.
4. Open AI services, save the xAI key, click Test connection. Do the same for Claude and/or OpenRouter if you want to use them. If a key is already in `.env`, leave it there — the app will keep using it.
5. Choose xAI only for the roles you want (text / image / video / English or Hindi voice). Leave Gujarati on ElevenLabs. Keep a budget set on the project.
6. `php artisan migrate` is not required. This sprint added no migration.
7. Refresh the app (Ctrl+F5).
8. Optional cleanup: `cd C:\laragon\www\Hn9-Studio; git worktree remove --force ..\Hn9-Studio-m19`

#### FULL TECHNICAL RESULT

Preflight: PASSED. M20 is merged on origin/main at `baba6f3379a2bf9993aa25daf99c8a056e763341`. `HN9-M20-AUTO-OUTPUT.md` STATUS is PASS — MERGED. The M19 worktree remains at `C:\laragon\www\Hn9-Studio-m19`, HEAD `566dfe3`, and was not read, reset, or deleted. Before this sprint the working tree had only untracked `logs/`. `phpunit.xml` forces sqlite `:memory:`. No MySQL connection was opened. No live provider call was made.

Docs verification (official pages win where they disagree with the sprint prompt, checked 2026-11-24):

- Text: `POST https://api.x.ai/v1/responses`, Bearer auth, `output_text` then `output[].content[].text`. Default model stays `grok-4.3`. `grok-4.7` is on the public API. Grok 4.7 Fast is Cursor-only and is not selectable here. Short-context price is $1.25 / 1M input and $2.50 / 1M output. The estimator stores an explicit USD amount and a null blended rate.
- Images: `POST /v1/images/generations`. Sent fields are `model`, `prompt`, `aspect_ratio`, `resolution`, `n`, `response_format`, and `quality` only for `grok-imagine-image-2.0`. Style is never sent. `grok-imagine-image` is $0.02. `grok-imagine-image-2.0` is $0.04. Temporary URLs are downloaded by the existing image store. No edits route: `ImageRequest` has no edit hook.
- Video: `POST /v1/videos/generations`, poll `GET /videos/{request_id}`. `pending` stays pending, `done` succeeds with `video.url`, `failed` and `expired` fail. `generate_audio` is false. Image-to-video sends `image.url` as a data URI and does not send `aspect_ratio`. Durations include 10 seconds. Resolutions 480p, 720p, 1080p. Default model `grok-imagine-video-1.5-lite`. Official per-second prices, used in code: lite $0.020 (the prompt’s 720p table said $0.03) and `grok-imagine-video-1.5` $0.080 (the prompt said $0.14). The price is not split by resolution. No cancel endpoint.
- Voice: `POST /v1/tts` with `text`, `voice_id` default `eve`, `language`, `with_timestamps`. Timestamps `audio_timestamps.graph_chars` and `graph_times` map onto the M16 alignment file. Gujarati is not in the official language list. `naksh` was not on the fetched voice page, so an empty voice setting means `eve`.
- Health: xAI Test connection uses `GET /v1/models` with Bearer, as the sprint specified. A models index page was not found, so that path is unconfirmed. Claude Test connection is `GET {base}/v1/models` with `x-api-key` and `anthropic-version` (`2026-10-21` when unset). It does not call `POST /v1/messages`. OpenRouter Test connection is `GET {base}/key` with Bearer. Usage, `limit_remaining`, and label are not copied into the response. The OpenRouter keys URL used in the UI is `https://openrouter.ai/settings/keys`.

Implementation:

- New `App\AI\Providers\Xai` provider: text, image, and voice. It does not claim video. Registered only when the API key is present. `XAI_ENABLED` defaults false. Not the default text, image, voice, or video provider. Not added to `AI_FALLBACK_CHAIN_*` or `STORY_VIDEO_PREFERRED_PROVIDERS`.
- Video adapter `video.xai` polls the generations API. Its `validate()` allows image-to-video to be selected before the M17 composer attaches the character lock. The picture is still required when the body is built. The parent Runway, Luma, and Seedance validator was not changed. `UNIT_SECONDS` stays 10. `preview_hash` and composer lock rules were not changed.
- Voice selector reads `STORY_VOICE_PROVIDER_EN`, `_HI`, and `_GU` (default `elevenlabs`). `xai` plus `gu` with `XAI_TTS_GUJARATI_EXPERIMENTAL` off falls back to ElevenLabs. `StoryAudioService` fail-closes if the live adapter is not the selected provider, so enabling xAI cannot steal an ElevenLabs job. English and Hindi send `voice_language` because ElevenLabs does not send a language code for those languages.
- Assembly stores `provider_key`. An xAI clip is muted with ffmpeg `-an` (anullsrc in the filter graph) for that segment only. A render mutes a segment only when every snapshot unit is `video.xai`.
- Costs use the official amounts above. Env overrides are `STORY_COST_XAI_*`. The guard runs before HTTP and before the job. No auto-approve. Config keys `video.xai` and `audio.xai` are read with array access because they contain dots.
- In-app keys: xAI writes both `ai.providers.xai.api_key` and `story_video.live_providers.xai.api_key`, and enables both when the provider is active. Claude and OpenRouter are in the same credential map. A non-empty `.env` value is not overwritten. Seeder adds one `xai` row, display `xAI (Grok)`, category multimodal. Claude and OpenRouter rows were already seeded.
- AI services page help links: xAI `https://console.x.ai/`, Claude `https://platform.claude.com/settings/keys`, OpenRouter `https://openrouter.ai/settings/keys`.

Files created:

- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiConfig.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiClient.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiModelRegistry.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiTokenCounter.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiUsageCalculator.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiImageParameterMap.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiTimestampMapper.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiResponseNormalizer.php`
- `HN9-AI-Studio/Backend/app/AI/Providers/Xai/XaiProvider.php`
- `HN9-AI-Studio/Backend/app/Story/Support/StoryRoleProvider.php`
- `HN9-AI-Studio/Backend/app/Story/Support/StoryVoiceProviderSelector.php`
- `HN9-AI-Studio/Backend/app/Story/Video/Adapters/XaiStoryVideoAdapter.php`
- `HN9-AI-Studio/Backend/app/Story/Video/Adapters/XaiStoryAudioAdapter.php`
- `HN9-AI-Studio/Backend/tests/Support/ConfiguresXai.php`
- `HN9-AI-Studio/Backend/tests/Unit/XaiImageParameterMapTest.php`
- `HN9-AI-Studio/Backend/tests/Unit/XaiAudioStripTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiProviderRegistrationTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiConnectionTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiTextGenerationTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiImageGenerationTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiVideoAdapterTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiTtsTimingTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/XaiBudgetAndRoutingTest.php`
- `HN9-AI-Studio/Backend/tests/Feature/ClaudeOpenRouterCredentialSettingsTest.php`
- `HN9-M21-AUTO-OUTPUT.md`

Files modified:

- `HN9-AI-Studio/Backend/config/ai.php`
- `HN9-AI-Studio/Backend/config/story_video.php`
- `HN9-AI-Studio/Backend/config/story_cost.php`
- `HN9-AI-Studio/Backend/config/story_voice.php`
- `HN9-AI-Studio/Backend/.env.example`
- `HN9-AI-Studio/Backend/app/Providers/AIServiceProvider.php`
- `HN9-AI-Studio/Backend/app/Providers/StoryServiceProvider.php`
- `HN9-AI-Studio/Backend/app/Story/Video/Adapters/LiveStoryVideoAdapterFactory.php`
- `HN9-AI-Studio/Backend/app/Support/RuntimeProviderCredentials.php`
- `HN9-AI-Studio/Backend/app/Services/ProviderConnectionTester.php`
- `HN9-AI-Studio/Backend/database/seeders/AiProviderSeeder.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryCostEstimator.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryAudioService.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryPlannerService.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryCharacterReferenceService.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryStyleReferenceService.php`
- `HN9-AI-Studio/Backend/app/Services/ExecutionOrchestrator.php`
- `HN9-AI-Studio/Backend/app/Story/Media/StoryMediaSegment.php`
- `HN9-AI-Studio/Backend/app/Story/Media/StoryMediaToolkit.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryProductionUnitVersionService.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StorySceneAssemblyService.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StoryRenderService.php`
- `HN9-AI-Studio/Backend/app/Story/Services/StorySpendService.php`
- `HN9-AI-Studio/Backend/tests/Feature/ProviderCredentialSettingsTest.php`
- `HN9-AI-Studio/Frontend/src/components/providers/ApiKeysPanel.jsx`
- `HN9-AI-Studio/README.md`
- `HN9-AI-Studio/ROADMAP.md`

API changes: no new routes. Existing provider credential and Test connection routes now accept `xai`, `claude`, and `openrouter`. Story plan, reference, voice, and video routes gain xAI only when that role is selected.

Database changes: none. No migration. The seeder inserts the `xai` provider row. The user runs `php artisan db:seed --class=AiProviderSeeder` after merge.

Tests (sqlite `:memory:`, `Http::preventStrayRequests()`, `Http::fake` only):

- Full suite before: 1112 passed, 1 skipped, 17833 assertions.
- Full suite after: 1133 passed, 1 skipped, 18008 assertions. Duration about 292s. The same SQLite CHECK skip remains in `StoryProductionPlanSchemaTest`.
- `npm run build`: passed in 7.36s. Existing chunk-size warning only.
- `node --test src/services/productionClips.test.js`: 13 passed, 0 failed.
- Story filter: 1 skipped, 521 passed (14993 assertions). Same pass count as the 521 / 1 baseline.
- Provider filter: 350 passed (1710 assertions). Baseline was 346 passed (1681 assertions).
- `StoryCostBudgetTest`: 12 passed (78 assertions).
- `StoryPlanner`: 10 passed (62 assertions).
- `StoryQuickStart|StoryTemplateCatalogTest`: 15 passed (155 assertions).
- `StoryVoiceTiming|StorySubtitle|ElevenLabsTimestamps`: 22 passed (268 assertions).
- `StoryVersionRestore|StoryVoiceLanguage|StoryRenderQueue|NotificationsApi`: 27 passed (176 assertions).
- `StoryCharacterLock|StoryPromptPreview|StoryLockedGeneration|StoryVideoPromptComposer`: 20 passed (172 assertions).
- `StoryProductionUnit|StoryBulkApprove|StoryUnitRetry|StoryProgress`: 47 passed (528 assertions).
- `StoryExport|StorySpend|StoryRouteSmoke`: 6 passed (279 assertions).
- Xai filter: 18 passed (100 assertions).

Scenarios: 1 seeder twice, one xAI row, no secret. 2 member Test connection 403, zero HTTP. 3 xAI, Claude, and OpenRouter probes use the documented methods and headers. 4 empty text role still uses the existing default. 5 image body uses aspect ratio and downloads the temporary URL. 6 text-to-video and image-to-video at 10 seconds, lock provenance present, `generate_audio` false. 7 stale `preview_hash` returns 409 with zero jobs and zero HTTP. 8 retry over the budget returns 422 with no new job and zero HTTP. 9 xAI clip audio is stripped. 10 English and Hindi timings land in the M16 alignment file. 11 Gujarati on xAI with the flag off stays on ElevenLabs and does not call `api.x.ai`. 12 env overrides replace the official rates. 13 a tiny INR budget blocks before HTTP. 14 enabling xAI does not take a runway clip and does not enter the fallback chain. 15 401 and 429 map to invalid key and rate limit. 16 Claude and OpenRouter masks, `.env` precedence, and the seeder’s one row each. The unknown-slug 422 now uses `not-a-provider` so Claude can be saved. All 16 passed.

Build result: frontend production build passed. Backend had no frontend API shape change beyond the existing credentials payload, which already returns one row per provider.

Migration required: NO.

Security: Test connection responses are `slug`, `result`, `message`, `supported` only. OpenRouter usage and label are not echoed. Secrets are not logged. A non-empty `.env` value is left in place. xAI image style is not sent. Video sound is not requested. Gujarati stays off xAI unless the experimental flag is true. The budget guard runs before the provider call.

Regression results: the filters above match the M20 pass counts. Provider is higher by the new provider tests. No previously passing test failed.

Live validation: NOT REQUIRED. Provider calls: 0.

Open issues:

- xAI `GET /v1/models` was not confirmed on a models index page. Test connection still uses it.
- `naksh` was not on the fetched voice page. Empty voice settings use `eve`.
- Image edits are not implemented. There is no edit hook on `ImageRequest`.
- Gujarati TTS on xAI is unconfirmed and stays behind `XAI_TTS_GUJARATI_EXPERIMENTAL=false`.
- Lip-sync is unused. xAI clips are generated silent and then muted again at assembly.
- The M19 worktree at `C:\laragon\www\Hn9-Studio-m19` is still on disk. Removing it is a user step.

#### GIT INTEGRATION

Sprint Branch: m21/xai-grok-providers
Commit: 37c11e36ee4861cc99e3634a0835b09f5ee5b800
Commit Message: feat(m21): add xAI Grok providers and in-app Claude and OpenRouter keys
Files Committed: the files listed above. logs/ and Frontend/dist were not staged.
Secret Scan: placeholders only in tests (`xai-test-key-1234`, `xai-live-key-9876`, `sk-test-openai-key`, and a truncated `sk-or-v1-au7...890` fragment asserted absent from the response). No real keys, no `.env`, no `C:\Users\` paths.
Branch Push: origin/m21/xai-grok-providers at 37c11e36ee4861cc99e3634a0835b09f5ee5b800
Origin/Main Re-sync: origin/main was still baba6f3379a2bf9993aa25daf99c8a056e763341. No re-sync merge.
Post-Sync Tests: not required. origin/main had not moved. Xai filter re-run before this merge: 18 passed, 100 assertions.
Merge: Merge branch 'm21/xai-grok-providers'
Main Push: origin/main after this merge
Local Main: equal to origin/main after the main push
Origin/Main: equal to local main after the main push
MySQL Migrate: not required
Working Tree: logs/ untracked only
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: NONE (M13–M21 plan complete; await user review)
