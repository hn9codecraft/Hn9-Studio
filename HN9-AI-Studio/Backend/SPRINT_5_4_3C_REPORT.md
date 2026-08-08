# Sprint 5.4.3C — AI Execution Pipeline Integration

## Status
Complete. The orchestration layer is verified and the `POST /api/v1/projects/{uuid}/generate` endpoint now executes the full pipeline.

## Correction to the previous audit
The earlier revision of this report concluded that the execution orchestration layer was missing and stopped the sprint. That conclusion is **out of date**. The orchestration layer is present on disk:

- [app/Services/ExecutionOrchestrator.php](app/Services/ExecutionOrchestrator.php)
- [app/Contracts/Services/ExecutionOrchestratorInterface.php](app/Contracts/Services/ExecutionOrchestratorInterface.php)
- Bound in [app/Providers/DomainServiceProvider.php](app/Providers/DomainServiceProvider.php) (`ExecutionOrchestratorInterface::class => ExecutionOrchestrator::class`)
- Covered by [tests/Unit/ExecutionOrchestratorTest.php](tests/Unit/ExecutionOrchestratorTest.php)

Both files are untracked in git, which is why the audit did not see them in history. No new orchestration service was created in this sprint — recreating one would have duplicated the existing service.

## Work performed
The orchestrator existed but violated two of the sprint's own constraints. Both were fixed in place; no module was redesigned.

### 1. Removed duplicated provider routing
The orchestrator called `ProviderRouterInterface::route()` itself, hand-building a `RoutingContext`, and then **discarded the returned `RoutingPlan`** before calling `ProviderDispatcher::dispatch()`.

`ProviderDispatcher::dispatch()` already performs routing internally:

```php
$context = RoutingContext::for($request, $options, $this->config);
$plan = $this->router->route($context)->limitTo(...);
```

So every generation planned providers twice, and the orchestrator's hand-built context could diverge from the one the dispatcher actually used (different capability filtering, budget, cost strategy and provider budget). The hand-built context also read `$dispatchOptions->strategy ?? 'priority'` off a freshly constructed default `DispatchOptions`, so those values were constant placeholders rather than real routing input.

Resolution: the redundant `route()` call and the `ProviderRouterInterface` constructor dependency were removed. ProviderRouter remains in the pipeline, invoked by ProviderDispatcher at its correct layer. Routing visibility is not lost — `DispatchResult::toArray()` already reports `'plan' => $this->plan?->keys()`, which the orchestrator persists into content and asset metadata.

### 2. Fixed the static-analysis error
`normalizeVariables()` guarded with `is_string($key)` on an `array<string, mixed>`, which PHPStan reported as always true (`function.alreadyNarrowedType`). The redundant check was removed.

### 3. Wired the API to the orchestrator
`GenerationController::generate()` previously called `GenerationRequestServiceInterface::submit()` directly, so the endpoint recorded a project input and executed nothing. It now takes `ExecutionOrchestratorInterface` and delegates the whole pipeline to `execute()`. The controller stays thin: authorise, build the DTO, delegate, present.

The response is now the pipeline's outcome rather than just the recorded input:

```json
{ "data": { "input": {...}, "content": {...}, "asset": {...}, "dispatch": {...} } }
```

This is a breaking change to the `generate` endpoint's response shape — `data.deliverable_type` moved to `data.input.deliverable_type`. `preview` and `generation-history` are unchanged.

### 4. Fixed three integration defects that only surfaced once wired
Wiring the controller exercised paths no unit test covered. All three produced HTTP 500s with stack traces.

**Domain exceptions were never rendered.** `DomainException` carries `errorCode()`, `statusCode()` and `context()`, and `AIException` extends it specifically so — per its own docblock — "the existing API error envelope renders these uniformly". But no renderer was ever registered, so every domain failure became an unhandled 500 with a stack trace: a non-editable project returned 500 instead of 409, and any provider failure (`AllProvidersFailedException`, 502) leaked a trace to the client. A renderer was added to `bootstrap/app.php` that maps `DomainException` onto `ApiResponse::error()` for API/JSON requests. This reuses both existing primitives and adds no new exception type.

**Array variables were cast to the literal string "Array".** `BrandContextService` supplies `audience` as a list of segments (and `colors`, `industry`, `brand_colors` likewise), while `PromptVariableResolver::resolve()` does `(string) $variables[$key]`. Every generation therefore raised "Array to string conversion" and, with warnings escalated, a 500. Since the orchestrator is what assembles the variable map, it now flattens values to prompt-ready strings itself (`stringifyVariable()`, lists joined with `", "`) rather than changing the Sprint 5.4.3B resolver.

**Unknown templates and unfillable placeholders were 500s.** `deliverable_type` is validated only as `string|max:100`, but `PromptTemplateResolver` knows 15 keys and throws a bare `InvalidArgumentException` for anything else; `PromptVariableResolver` throws the same for a placeholder it cannot fill. Both are client-supplied gaps. The orchestrator now maps them to 422s: the existing `GenerationException::unsupportedDeliverable()` for an unknown template, and `generation_missing_prompt_variable` (naming the variable and template) for an unfillable placeholder.

Note that the request's `deliverable_type` doubles as the template key, so only the 15 catalog keys (`blog`, `caption`, `email`, …) are accepted. The catalog also omits six templates that exist on disk — `carousel`, `facebook`, `hashtags`, `landing-page`, `proposal`, `sales` — which are therefore unreachable. Both belong to the prompt-catalog layer and were left alone.

### 5. Made the variable map explicitly overridable
`normalizeVariables()` now honours an `options['variables']` array that takes precedence over brand context and request payload. This is the seam a caller (or a future Brand Brain layer) uses to fill template-specific variables without the orchestrator hardcoding any.

## Files Modified
- app/Services/ExecutionOrchestrator.php — removed duplicated routing and the router dependency; mapped resolver failures onto `GenerationException`; added variable stringification and the `options['variables']` override; removed the redundant type check
- app/Http/Controllers/Api/V1/GenerationController.php — `generate()` now delegates to `ExecutionOrchestratorInterface` and returns the input, content, asset and dispatch payload
- bootstrap/app.php — registered the missing `DomainException` → `ApiResponse::error()` renderer
- tests/Unit/ExecutionOrchestratorTest.php — dropped the router mock and its `route()` expectation; the routing plan is now attached to the stub `DispatchResult` and asserted to reach the orchestrator's returned dispatch payload
- tests/Feature/GenerationApiTest.php — rewrote the generate test against the wired pipeline with a faked `ProviderDispatcherInterface`; added coverage for the unknown-deliverable 422, the missing-variable 422, the all-providers-failed 502 and the non-editable-project 409

## Files Created
- None

## Architecture Reused
No provider, prompt, or AI logic is implemented in the orchestrator. It only coordinates:

Project → ProjectInput → GenerationRequestService → PromptService → ProviderRouter (inside ProviderDispatcher) → ProviderDispatcher → ContentService → AssetService

Reused unchanged: GenerationRequestService, PromptService, PromptTemplateResolver, PromptContextBuilder, PromptRenderer, ProviderRouter, ProviderDispatcher, ContentService, AssetService, WorkflowService, AgentExecutionService.

## APIs Implemented
- `POST /api/v1/projects/{uuid}/generate` — now executes the pipeline. 201 with input/content/asset/dispatch; 409 non-editable project; 422 unknown deliverable, unsupported language or unfillable prompt variable; 502 when every provider fails.
- `POST /api/v1/projects/{uuid}/generate/preview` — unchanged.
- `GET /api/v1/projects/{uuid}/generation-history` — unchanged.

No routes were added or renamed.

## Verification Results
Run with the Laragon PHP 8.3.30 runtime.

- `phpunit --filter=ExecutionOrchestratorTest` → **OK (1 test, 34 assertions)**
- `phpunit --filter=GenerationApiTest` → **OK (7 tests, 31 assertions)**, and identical across three consecutive runs (the previously flaky test is now deterministic)
- Full suite → **OK (268 tests, 897 assertions)**, up from 264 tests with 1 failure
- `pint --test` (whole project) → **passed**
- `phpstan analyse` → 2 errors, both pre-existing in `BrandContextService` (see below). `ExecutionOrchestrator`, `GenerationController` and `bootstrap/app.php` are clean.
- Container resolution of `ExecutionOrchestratorInterface` → resolves with all ten dependencies injected

The previously reported "unrelated existing failure caused by a project status guard" is resolved. It was not a broken guard but a **flaky test**: [database/factories/ProjectFactory.php:32](database/factories/ProjectFactory.php#L32) randomises `status` over `['draft', 'active', 'archived']`, and `archived` is not editable, so the test failed roughly one run in three (confirmed: six runs, five passes, one failure). The generate tests now pin an explicit status, and the archived case is asserted deliberately as a 409.

## Known Issues Found (left in place, outside this sprint's scope)
1. ~~**`BrandContextService` never loads project settings or metadata.**~~ **Corrected in Sprint 5.4.4 — this was not a runtime bug.** The two `function.impossibleType` errors on `is_array($project->settings)` / `is_array($project->metadata)` were a false positive: the `Project` model casts both attributes to `array`, but carried no matching `@property` annotations, so PHPStan inferred the raw `json` column type of `string|null`. Adding the correct annotations to the model cleared both errors, and runtime behaviour was verified to be correct all along (`is_array($project->settings)` is `true`, and the per-project tone override does apply). The original diagnosis in this report was wrong.

2. **`ProjectFactory` randomises `status`.** Any future test that exercises an editable-project path will be flaky unless it pins the status. Worth making the factory default editable, with `archived` set explicitly by the tests that want it.

3. **Six prompt templates are unreachable.** `carousel`, `facebook`, `hashtags`, `landing-page`, `proposal` and `sales` exist under `Prompts/templates` but are absent from `PromptTemplateResolver`'s hardcoded catalog.

4. **Prompt-execution rows stay `queued`.** See Risks.

## Risks
- Provider selection is now planned in exactly one place. Any future need to inspect the plan before dispatch should read `DispatchResult::plan`, or pass constraints via `DispatchOptions`, rather than re-invoking the router.
- `ExecutionOrchestrator` records the prompt execution with status `queued` and never updates it, because `PromptServiceInterface` exposes only `record()` and no update method. Prompt-execution rows therefore remain `queued` after a successful generation, and the `WorkflowRun`/`AgentExecution` rows likewise stay `pending`. Fixing this requires extending PromptService, which this sprint was instructed not to redesign.
- `DispatchOptions::make()` is used with no overrides, so per-request model, provider preference, budget and timeout are not yet forwarded from the caller to the dispatcher. `options['model']` does reach the `TextRequest`.
- Generation runs **synchronously inside the HTTP request**. A slow provider chain (retries plus fallbacks under the configured deadline) holds the request open. Moving execution to a queued job is the natural next step and requires no change to the orchestrator, since it is already a plain service.
- The pipeline is not transactional. If `AssetService::create()` fails after `ContentService::create()` succeeds, the content row persists without its asset. Wrapping `execute()` in a transaction would need care around the provider call, which must not sit inside an open transaction.
- Text is the only modality wired. The orchestrator always builds a `TextRequest`, so image, voice and video deliverables route through the text capability. The dispatcher supports all four.
