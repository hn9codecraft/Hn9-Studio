<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\DTOs\Script\CreateScriptData;
use App\DTOs\Script\UpdateScriptData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScriptRequest;
use App\Http\Requests\UpdateScriptRequest;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScriptController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ScriptServiceInterface $scripts,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Script::class, $project]);

        $perPage = (int) $request->query('perPage', 50);
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
        ]));

        $script = $this->scripts->create($data, $request->user());

        return ApiResponse::created(new ScriptResource($script));
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

    private function scriptForProject(string $projectUuid, string $scriptUuid): Script
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $script = $this->scripts->getByUuid($scriptUuid);

        abort_unless($script->project_id === $project->getKey(), 404);

        return $script;
    }
}
