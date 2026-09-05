<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ImageServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\Image\CreateImageData;
use App\DTOs\Image\UpdateImageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Http\Requests\UpdateImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ImageServiceInterface $images,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Image::class, $project]);

        $perPage = (int) $request->query('perPage', 50);
        $filters = $request->only(['status', 'sort', 'order']);
        $page = $this->images->paginateForProject($project, $perPage, $filters);

        return ApiResponse::success(ImageResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function store(StoreImageRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [Image::class, $project]);

        $data = CreateImageData::fromArray(array_merge($request->validated(), [
            'project_id' => $project->getKey(),
        ]));

        $image = $this->images->create($data, $request->user());

        return ApiResponse::created(new ImageResource($image));
    }

    public function show(string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('view', $image);

        return ApiResponse::success(new ImageResource($image));
    }

    public function update(UpdateImageRequest $request, string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('update', $image);

        $updated = $this->images->update($image, UpdateImageData::fromArray($request->validated()), $request->user());

        return ApiResponse::success(new ImageResource($updated));
    }

    public function destroy(string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('delete', $image);

        $this->images->delete($image, request()->user());

        return ApiResponse::noContent();
    }

    private function imageForProject(string $projectUuid, string $imageUuid): Image
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $image = $this->images->getByUuid($imageUuid);

        abort_unless($image->project_id === $project->getKey(), 404);

        return $image;
    }
}
