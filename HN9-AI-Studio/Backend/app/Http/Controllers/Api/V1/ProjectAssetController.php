<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectAssetServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\ProjectAsset\CreateProjectAssetData;
use App\DTOs\ProjectAsset\UpdateProjectAssetData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectAssetRequest;
use App\Http\Requests\UpdateProjectAssetRequest;
use App\Http\Resources\ProjectAssetResource;
use App\Models\ProjectAsset;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectAssetController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ProjectAssetServiceInterface $assets,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [ProjectAsset::class, $project]);

        $perPage = (int) $request->query('perPage', 50);
        $filters = $request->only(['status', 'type', 'source', 'sort', 'order']);
        $page = $this->assets->paginateForProject($project, $perPage, $filters);

        return ApiResponse::success(ProjectAssetResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function store(StoreProjectAssetRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [ProjectAsset::class, $project]);

        $data = CreateProjectAssetData::fromArray(array_merge($request->validated(), [
            'project_id' => $project->getKey(),
        ]));

        $asset = $this->assets->create($data, $request->user());

        return ApiResponse::created(new ProjectAssetResource($asset));
    }

    public function show(string $uuid, string $assetUuid): JsonResponse
    {
        $asset = $this->assetForProject($uuid, $assetUuid);

        $this->authorize('view', $asset);

        return ApiResponse::success(new ProjectAssetResource($asset));
    }

    public function update(UpdateProjectAssetRequest $request, string $uuid, string $assetUuid): JsonResponse
    {
        $asset = $this->assetForProject($uuid, $assetUuid);

        $this->authorize('update', $asset);

        $updated = $this->assets->update($asset, UpdateProjectAssetData::fromArray($request->validated()), $request->user());

        return ApiResponse::success(new ProjectAssetResource($updated));
    }

    public function destroy(string $uuid, string $assetUuid): JsonResponse
    {
        $asset = $this->assetForProject($uuid, $assetUuid);

        $this->authorize('delete', $asset);

        $this->assets->delete($asset, request()->user());

        return ApiResponse::noContent();
    }

    private function assetForProject(string $projectUuid, string $assetUuid): ProjectAsset
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $asset = $this->assets->getByUuid($assetUuid);

        abort_unless($asset->project_id === $project->getKey(), 404);

        return $asset;
    }
}
