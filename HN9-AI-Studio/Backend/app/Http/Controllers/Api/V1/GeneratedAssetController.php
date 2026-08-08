<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\AssetServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGeneratedAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\GeneratedAsset;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read and lifecycle endpoints for generated assets. Authorization is delegated
 * to the generated asset policy.
 */
class GeneratedAssetController extends Controller
{
    public function __construct(private AssetServiceInterface $assets) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', GeneratedAsset::class);

        $perPage = (int) ($request->query('perPage', 15));
        $filters = $request->only([
            'project', 'projectUuid', 'type', 'provider', 'status', 'search', 'favorite', 'sort', 'order',
        ]);

        $page = $this->assets->paginateForUser($request->user(), $perPage, $filters);

        return ApiResponse::success(AssetResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $asset = $this->assets->getByUuid($uuid);

        $this->authorize('view', $asset);

        return ApiResponse::success(new AssetResource($asset));
    }

    public function update(UpdateGeneratedAssetRequest $request, string $uuid): JsonResponse
    {
        $asset = $this->assets->getByUuid($uuid);

        $this->authorize('update', $asset);

        $updated = $this->assets->update($asset, $request->validated());

        return ApiResponse::success(new AssetResource($updated));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $asset = $this->assets->getByUuid($uuid);

        $this->authorize('update', $asset);

        $updated = $this->assets->update($asset, ['status' => 'cancelled']);

        return ApiResponse::success(new AssetResource($updated));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $asset = $this->assets->getByUuid($uuid);

        $this->authorize('delete', $asset);

        $this->assets->delete($asset, $request->user());

        return ApiResponse::noContent();
    }

    public function favorite(Request $request, string $uuid): JsonResponse
    {
        $asset = $this->assets->getByUuid($uuid);

        $this->authorize('update', $asset);

        $updated = $this->assets->setFavorite($asset, true, $request->user());

        return ApiResponse::success(new AssetResource($updated));
    }

    public function unfavorite(Request $request, string $uuid): JsonResponse
    {
        $asset = $this->assets->getByUuid($uuid);

        $this->authorize('update', $asset);

        $updated = $this->assets->setFavorite($asset, false, $request->user());

        return ApiResponse::success(new AssetResource($updated));
    }
}
