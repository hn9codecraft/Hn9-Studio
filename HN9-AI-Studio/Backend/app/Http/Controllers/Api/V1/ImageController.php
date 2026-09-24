<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ImageGenerationServiceInterface;
use App\Contracts\Services\ImageReviewServiceInterface;
use App\Contracts\Services\ImageServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\Image\CreateImageData;
use App\DTOs\Image\UpdateImageData;
use App\Exceptions\ImageWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveImageRequest;
use App\Http\Requests\GenerateImageRequest;
use App\Http\Requests\NeedsReworkImageRequest;
use App\Http\Requests\StoreImageRequest;
use App\Http\Requests\SubmitImageReviewRequest;
use App\Http\Requests\UpdateImageRequest;
use App\Http\Resources\ImageResource;
use App\Http\Resources\ImageReviewEventResource;
use App\Models\Image;
use App\Support\ApiResponse;
use App\Support\PageSize;
use App\Support\StorageHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ImageServiceInterface $images,
        private ImageGenerationServiceInterface $generation,
        private ImageReviewServiceInterface $reviews,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Image::class, $project]);

        $perPage = PageSize::fromRequest($request, 50);
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

    public function generate(GenerateImageRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [Image::class, $project]);
        $this->authorize('update', $project);

        $result = $this->generation->generate($project, $request->validated(), $request->user());

        return ApiResponse::created([
            'image' => (new ImageResource($result['image']))->resolve(),
            'dispatch' => $result['dispatch'],
        ]);
    }

    public function regenerate(GenerateImageRequest $request, string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('update', $image);
        $this->authorize('update', $image->project);
        $this->authorize('create', [Image::class, $image->project]);

        if (! $image->allowsRegeneration()) {
            throw ImageWorkflowException::invalidTransition(
                $image->uuid,
                'regenerated',
                $image->statusEnum()->value,
            );
        }

        $result = $this->generation->generate(
            $image->project,
            $request->validated(),
            $request->user(),
            $image,
        );

        return ApiResponse::created([
            'image' => (new ImageResource($result['image']))->resolve(),
            'dispatch' => $result['dispatch'],
        ]);
    }

    public function show(string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('view', $image);

        return ApiResponse::success(new ImageResource($image));
    }

    public function file(string $uuid, string $imageUuid): StreamedResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('view', $image);

        $file = $image->file;

        abort_if($file === null, 404);

        $disk = StorageHelper::disk($file->disk);
        abort_unless(Storage::disk($disk)->exists($file->path), 404);

        return Storage::disk($disk)->response($file->path, 'image.'.($file->extension ?: 'png'), [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
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

    public function submitReview(SubmitImageReviewRequest $request, string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('submit', $image);

        $updated = $this->reviews->submit($image, $request->user(), $request->validated('comment'));

        return ApiResponse::success(new ImageResource($updated));
    }

    public function approve(ApproveImageRequest $request, string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('review', $image);

        $updated = $this->reviews->approve($image, $request->user(), $request->validated('comment'));

        return ApiResponse::success(new ImageResource($updated));
    }

    public function needsRework(NeedsReworkImageRequest $request, string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('review', $image);

        $updated = $this->reviews->requestRework($image, $request->user(), (string) $request->validated('comment'));

        return ApiResponse::success(new ImageResource($updated));
    }

    public function reviewHistory(string $uuid, string $imageUuid): JsonResponse
    {
        $image = $this->imageForProject($uuid, $imageUuid);

        $this->authorize('viewReviewHistory', $image);

        return ApiResponse::success(ImageReviewEventResource::collection($this->reviews->history($image)));
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
