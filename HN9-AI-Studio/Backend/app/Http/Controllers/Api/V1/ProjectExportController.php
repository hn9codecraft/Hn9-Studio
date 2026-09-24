<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ExportServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Http\Resources\ProjectResource;
use App\Models\Export;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Project-scoped final assets, finalization, export, and authenticated download.
 */
final class ProjectExportController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ExportServiceInterface $exports,
    ) {}

    public function finalAssets(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projectFor($uuid);

        $this->authorize('view', $project);

        return ApiResponse::success($this->exports->readiness($project));
    }

    public function finalize(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projectFor($uuid);

        $this->authorize('update', $project);

        $updated = $this->exports->finalize($project, $request->user());

        return ApiResponse::success([
            'project' => (new ProjectResource($updated))->resolve(),
            'readiness' => $this->exports->readiness($updated),
        ]);
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projectFor($uuid);

        $this->authorize('update', $project);
        $this->authorize('create', [Export::class, $project]);

        $export = $this->exports->createForProject($project, $request->user());

        return ApiResponse::created(new ExportResource($export));
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projectFor($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Export::class, $project]);

        return ApiResponse::success(ExportResource::collection($this->exports->listForProject($project)));
    }

    public function show(Request $request, string $uuid, string $exportUuid): JsonResponse
    {
        $project = $this->projectFor($uuid);

        $this->authorize('view', $project);

        $export = $this->exports->findForProject($project, $exportUuid);

        $this->authorize('view', $export);

        return ApiResponse::success(new ExportResource($export));
    }

    public function download(Request $request, string $uuid, string $exportUuid): StreamedResponse
    {
        $project = $this->projectFor($uuid);

        $this->authorize('view', $project);

        $export = $this->exports->findForProject($project, $exportUuid);

        $this->authorize('download', $export);

        return $this->exports->downloadForProject($project, $exportUuid);
    }

    private function projectFor(string $uuid): Project
    {
        return $this->projects->getByUuid($uuid);
    }
}
