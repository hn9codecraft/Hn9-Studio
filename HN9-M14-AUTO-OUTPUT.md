# HN9 M14 AUTO EXECUTION

CURRENT_SPRINT: M14
STATUS: PASS — MERGED
ACTION:

### M14

STATUS: PASS
STARTED: 2026-11-10
COMPLETED: 2026-11-10
VERDICT: PASS
LIVE VALIDATION: NOT REQUIRED

#### HUMAN SUMMARY
An administrator can save, replace, test, and remove API keys for OpenAI, Gemini, ElevenLabs, Runway, Luma, and Seedance inside the app. The key is stored encrypted. The screen shows only a mask of the last four characters. Test connection reports Connected, Invalid key, Unreachable, or Not verifiable. A key already set in Backend/.env is left as it is: .env keys take precedence over keys saved in the app. Luma and Seedance can be saved, and their connection is checked on the first generation because those services do not offer a safe read-only check.

From HN9-AI-Studio/Backend, with Laragon PHP on PATH:
1. php artisan db:seed --class=AiProviderSeeder
2. php artisan hn9:user-role test@example.com admin
3. Log out and log in again in the app (http://127.0.0.1:5173), open "AI services", paste each key, click Save, then Test connection.
4. If a queue worker is running: php artisan queue:restart
5. Keep APP_KEY in Backend/.env unchanged — stored keys are encrypted with it.

#### FULL TECHNICAL RESULT
Implementation: ProviderCredentialsServiceProvider is the first application provider in bootstrap/providers.php. It binds RuntimeProviderCredentials in register() and applies the overlay in boot(), after DatabaseServiceProvider has set the Eloquent event dispatcher, so new provider rows still receive a uuid. noteOrdering() records that PlatformConfig was not resolved at apply time. The ordering test asserts this provider is loaded before AIServiceProvider, RuntimeProviderCredentials::$platformResolvedBeforeApply is false, and PlatformConfig is unresolved until the test resolves it. Precedence: a non-empty config value already loaded from .env wins. A stored key is written only when that config value is empty or null. For OpenAI, Gemini, and ElevenLabs, supplying the stored key while the provider status is active also sets enabled true. Runway, Luma, and Seedance have no enable path; their existing enabled flag is left unchanged. Tests leave hn9.runtime_credentials.enabled false unless they opt in. Outside tests the overlay always runs. A missing table, a database error, or a DecryptException skips the overlay, writes one warning without the value, and the app still boots. After save or remove, refreshRuntime() applies again and forgets PlatformConfig, ProviderInstanceCache, ProviderMetadataCache, ProviderRegistryInterface, StoryCapabilityRouterInterface, and StoryVideoEngineInterface, then calls AIServiceProvider::boot() so the same request sees the new key. Queue workers need php artisan queue:restart.
Files: Backend new: SetUserRole.php, ProviderCredentialController.php, UpdateProviderCredentialRequest.php, ProviderCredentialsServiceProvider.php, ProviderConnectionTester.php, ProviderCredentialService.php, RuntimeProviderCredentials.php, AiProviderSeeder.php, ProviderCredentialSettingsTest.php, UserRoleCommandTest.php. Backend modified: .env.example (comment only), ProviderRegistryServiceInterface.php, ProviderController.php, ProviderSetting.php, ProviderRegistryService.php, bootstrap/providers.php, config/hn9.php (runtime_credentials.enabled added; existing disk, locale, and video keys kept), routes/api/v1.php, ModelRelationshipsTest.php, ProviderApiTest.php. Frontend new: ApiKeysPanel.jsx. Frontend modified: providerService.js, ProvidersPage.jsx, StudioSettingsStep.jsx, ProjectStoryPage.jsx. Control: HN9-M14-AUTO-OUTPUT.md.
API: Inside the existing auth:sanctum group. GET /api/v1/provider-credentials (provider-credentials.index) policy viewAny AiProvider. PUT /api/v1/provider-credentials/{slug} (provider-credentials.update) throttle:10,1. DELETE /api/v1/provider-credentials/{slug}/key (provider-credentials.key.destroy) throttle:10,1. POST /api/v1/provider-credentials/{slug}/test (provider-credentials.test) throttle:generation. Responses return the mask, never the key. Mask is •••• plus the last 4 characters when the value is at least 8 characters, otherwise ••••. POST /api/v1/providers/{uuid}/test now delegates to the same tester. An unmapped slug returns 422 error_code provider_test_unsupported. It no longer returns 501.
Database: No migration. MySQL hn9_ai_studio before the seeder: ai_providers 0, provider_settings 0. php artisan db:seed --class=AiProviderSeeder run twice: ai_providers 8 (openai, gemini, elevenlabs, runway, luma, seedance, claude, openrouter), every row has a uuid, provider_settings 0. No keys were written. hn9:user-role was not run against MySQL.
Connection Tests: Timeouts are 5 seconds connect and 10 seconds total, one GET, no retries. 2xx connected, 401/403 invalid_key, 429 rate_limited, 5xx/timeout/connection unreachable. The response body is not stored.

| Provider | URL | Auth header | Verifiable |
| --- | --- | --- | --- |
| openai | {base}/models, default https://api.openai.com/v1/models | Authorization: Bearer | yes |
| gemini | {base}/{version}/models, default https://generativelanguage.googleapis.com/v1beta/models | x-goog-api-key (never the query string) | yes |
| elevenlabs | {base}/user, default https://api.elevenlabs.io/v1/user | xi-api-key | yes |
| runway | {base}/v1/organization, default https://api.dev.runwayml.com/v1/organization | Authorization: Bearer and X-Runway-Version 2024-11-06 | yes |
| luma | none | none | no, result not_verifiable, no HTTP |
| seedance | none | none | no, result not_verifiable, no HTTP |

Tests: ProviderCredentialSettingsTest.php 13 passed. UserRoleCommandTest.php 1 passed. Together with the updated provider tests, scenarios 1–12 pass. Http::fake only. preventStrayRequests stays on. Fixtures use test-key-1234.
Build: npm run build passed (existing chunk-size warning only). node --test src/services/productionClips.test.js — 12 passed.
Migration: not required
Security: A member receives 403 on every credential route. Anonymous receives 401. A deactivated admin token receives 401. AiProviderPolicy stays admin-only. The leak test saves a key, gets a 200 connection result, then a 401 whose body contains the key, and asserts the plaintext is absent from the response, the activity log, and captured log lines. The raw provider_settings value is not the plaintext. Gemini's key is asserted in the header and not in the URL. M12 inactive-token rejection and M13 cost, budget, and spend tests are unchanged and green. Secret scan of the sprint files found only placeholders (test-key-1234 and existing test strings such as sk-secret-value). .env and APP_KEY were not edited.
Regression: php artisan test — 1008 passed, 1 skipped, 16387 assertions (M13 baseline 994 passed, 1 skipped, 16259 assertions). php artisan test --filter Story — 421 passed, 1 skipped, 13534 assertions (baseline 421 passed, 1 skipped). php artisan test --filter Provider — 341 passed, 1634 assertions. php artisan test --filter StoryCostBudgetTest — 11 passed, 68 assertions.
Live Validation: NOT REQUIRED
Provider/API Calls: 0
Open Issues: Plan text uses ceil(characters/4) and stays unavailable until STORY_COST_TEXT_PER_1K_TOKENS. Image generations are guarded but are not in the job-based spend summary. Queued jobs that have not reached a provider are reserved for the budget lock and are omitted from the public estimated total. Luma and Seedance remain not verifiable until a real read-only endpoint exists; they never report connected without a real 2xx. Claude and OpenRouter are seeded and are not editable in this screen.

#### GIT INTEGRATION
Sprint Branch: m14/api-key-settings
Commit: this file is inside the single sprint commit. Identify it with `git log main --grep "feat(m14)"`.
Commit Message: feat(m14): add in-app API key settings and connection tests
Files Committed: the Backend, Frontend, and control files listed above. logs/, Frontend/dist, and .env were not staged.
Secret Scan: PASS
Push: origin m14/api-key-settings
Main Sync: origin/main 747fca372d6489cb4ce4f93eecce7c72ec2f826f, fast-forward already current
Merge: --no-ff into main
Main Push: origin main
Local Main: no-ff merge of m14/api-key-settings; git rev-parse main equals origin/main
Origin/Main: equal to local main
Working Tree: clean except pre-existing untracked logs/
Force Push: NO
Status: PASS — MERGED

Blockers:
Next Sprint: M15 (awaiting user review — do not start)
