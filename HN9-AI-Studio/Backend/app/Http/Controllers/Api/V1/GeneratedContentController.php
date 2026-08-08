<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ContentRegenerationServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegenerateContentRequest;
use App\Http\Resources\AssetResource;
use App\Http\Resources\GeneratedContentResource;
use App\Models\GeneratedAsset;
use App\Models\GeneratedContent;
use App\Policies\GeneratedContentPolicy;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read and lifecycle endpoints for generated content. Authorization is entirely
 * delegated to {@see GeneratedContentPolicy}; regeneration is
 * delegated to the existing execution pipeline.
 */
class GeneratedContentController extends Controller
{
    public function __construct(
        private ContentServiceInterface $contents,
        private ContentRegenerationServiceInterface $regeneration,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', GeneratedContent::class);

        $perPage = (int) ($request->query('perPage', 15));

        $filters = $request->only([
            'project', 'status', 'provider', 'template', 'date',
            'type', 'channel', 'language', 'favorite',
            'search', 'sort', 'order',
        ]);

        $page = $this->contents->paginateForUser($request->user(), $perPage, $filters);

        return ApiResponse::success(GeneratedContentResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $content = $this->contents->getByUuid($uuid);

        $this->authorize('view', $content);

        return ApiResponse::success(new GeneratedContentResource($content));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $content = $this->contents->getByUuid($uuid);

        $this->authorize('delete', $content);

        $this->contents->delete($content, $request->user());

        return ApiResponse::noContent();
    }

    public function favorite(Request $request, string $uuid): JsonResponse
    {
        $content = $this->contents->getByUuid($uuid);

        $this->authorize('update', $content);

        $updated = $this->contents->setFavorite($content, true, $request->user());

        return ApiResponse::success(new GeneratedContentResource($updated));
    }

    public function unfavorite(Request $request, string $uuid): JsonResponse
    {
        $content = $this->contents->getByUuid($uuid);

        $this->authorize('update', $content);

        $updated = $this->contents->setFavorite($content, false, $request->user());

        return ApiResponse::success(new GeneratedContentResource($updated));
    }

    public function regenerate(RegenerateContentRequest $request, string $uuid): JsonResponse
    {
        $content = $this->contents->getByUuid($uuid);

        $this->authorize('update', $content);

        $result = $this->regeneration->regenerate($content, $request->validated(), $request->user());

        $generated = $result['content'] ?? null;
        $asset = $result['asset'] ?? null;

        return ApiResponse::created([
            'content' => $generated instanceof GeneratedContent ? new GeneratedContentResource($generated) : null,
            'asset' => $asset instanceof GeneratedAsset ? new AssetResource($asset) : null,
            'dispatch' => $result['dispatch'] ?? null,
            'regenerated_from' => $content->uuid,
        ]);
    }
}
