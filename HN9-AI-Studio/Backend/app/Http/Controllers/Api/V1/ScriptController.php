<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\ScriptGenerationServiceInterface;
use App\Contracts\Services\ScriptReviewServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\DTOs\Script\CreateScriptData;
use App\DTOs\Script\UpdateScriptData;
use App\Enums\ScriptSource;
use App\Exceptions\ScriptWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveScriptRequest;
use App\Http\Requests\GenerateScriptRequest;
use App\Http\Requests\NeedsReworkScriptRequest;
use App\Http\Requests\StoreScriptRequest;
use App\Http\Requests\SubmitScriptReviewRequest;
use App\Http\Requests\UpdateScriptRequest;
use App\Http\Resources\ScriptResource;
use App\Http\Resources\ScriptReviewEventResource;
use App\Models\Script;
use App\Support\ApiResponse;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScriptController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ScriptServiceInterface $scripts,
        private ScriptGenerationServiceInterface $generation,
        private ScriptReviewServiceInterface $reviews,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Script::class, $project]);

        $perPage = PageSize::fromRequest($request, 50);
        $filters = $request->only(['status', 'sort', 'order']);
        $page = $this->scripts->paginateForProject($project, $perPage, $filters);

        return ApiResponse::success(ScriptResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function store(StoreScriptRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [Script::class, $project]);

        $data = CreateScriptData::fromArray(array_merge($request->validated(), [
            'project_id' => $project->getKey(),
            'source' => ScriptSource::Manual->value,
        ]));

        $script = $this->scripts->create($data, $request->user());

        return ApiResponse::created(new ScriptResource($script));
    }

    public function generate(GenerateScriptRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [Script::class, $project]);
        $this->authorize('update', $project);

        $result = $this->generation->generate($project, $request->validated(), $request->user());

        return ApiResponse::created([
            'script' => (new ScriptResource($result['script']))->resolve(),
            'dispatch' => $result['dispatch'],
        ]);
    }

    public function regenerate(GenerateScriptRequest $request, string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('update', $script);
        $this->authorize('update', $script->project);
        $this->authorize('create', [Script::class, $script->project]);

        if (! $script->allowsRegeneration()) {
            throw ScriptWorkflowException::invalidTransition(
                $script->uuid,
                'regenerated',
                $script->statusEnum()->value,
            );
        }

        $result = $this->generation->generate(
            $script->project,
            $request->validated(),
            $request->user(),
            $script,
        );

        return ApiResponse::created([
            'script' => (new ScriptResource($result['script']))->resolve(),
            'dispatch' => $result['dispatch'],
        ]);
    }

    public function show(string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('view', $script);

        return ApiResponse::success(new ScriptResource($script));
    }

    public function update(UpdateScriptRequest $request, string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('update', $script);

        $updated = $this->scripts->update($script, UpdateScriptData::fromArray($request->validated()), $request->user());

        return ApiResponse::success(new ScriptResource($updated));
    }

    public function destroy(string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('delete', $script);

        $this->scripts->delete($script, request()->user());

        return ApiResponse::noContent();
    }

    public function submitReview(SubmitScriptReviewRequest $request, string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('submit', $script);

        $updated = $this->reviews->submit($script, $request->user(), $request->validated('comment'));

        return ApiResponse::success(new ScriptResource($updated));
    }

    public function approve(ApproveScriptRequest $request, string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('review', $script);

        $updated = $this->reviews->approve($script, $request->user(), $request->validated('comment'));

        return ApiResponse::success(new ScriptResource($updated));
    }

    public function needsRework(NeedsReworkScriptRequest $request, string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('review', $script);

        $updated = $this->reviews->requestRework($script, $request->user(), (string) $request->validated('comment'));

        return ApiResponse::success(new ScriptResource($updated));
    }

    public function reviewHistory(string $uuid, string $scriptUuid): JsonResponse
    {
        $script = $this->scriptForProject($uuid, $scriptUuid);

        $this->authorize('viewReviewHistory', $script);

        $events = $this->reviews->history($script);

        return ApiResponse::success(ScriptReviewEventResource::collection($events));
    }

    private function scriptForProject(string $projectUuid, string $scriptUuid): Script
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $script = $this->scripts->getByUuid($scriptUuid);

        abort_unless($script->project_id === $project->getKey(), 404);

        return $script;
    }
}
