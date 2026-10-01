<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoryExportResource;
use App\Models\User;
use App\Story\Models\StoryExport;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryExportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Story final package export. Authorization uses the same ExportPolicy as project exports.
 */
class StoryExportController extends Controller
{
    public function __construct(
        private StoryExportService $exports,
        private ProjectServiceInterface $projects,
    ) {}

    public function store(Request $request, string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('create', [StoryExport::class, $project]);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $result = $this->exports->create($project, $reelUuid, $renderUuid, $user);

        return ApiResponse::success(
            (new StoryExportResource($result['export']))->resolve(),
            $result['created'] ? 201 : 200,
        );
    }

    public function show(string $uuid, string $reelUuid, string $exportUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $export = $this->exports->find($project, $reelUuid, $exportUuid);
        $this->authorize('view', $export);

        return ApiResponse::success((new StoryExportResource($export))->resolve());
    }

    public function download(string $uuid, string $reelUuid, string $exportUuid): StreamedResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $export = $this->exports->find($project, $reelUuid, $exportUuid);
        $this->authorize('download', $export);

        return $this->exports->download($export);
    }
}
