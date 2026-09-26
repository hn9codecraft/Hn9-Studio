<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateStoryStyleReferenceRequest;
use App\Http\Requests\RejectStoryStyleReferenceRequest;
use App\Http\Requests\ReviewStoryStyleReferenceRequest;
use App\Http\Requests\UploadStoryStyleReferenceRequest;
use App\Http\Resources\StoryStyleReferenceResource;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryStyleReferenceServiceInterface;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoryStyleReferenceController extends Controller
{
    public function __construct(
        private StoryStyleReferenceServiceInterface $references,
        private StoryStyleBibleServiceInterface $styles,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('view', $style);

        $items = $this->references->listForProject($project);

        return ApiResponse::success(StoryStyleReferenceResource::collection($items));
    }

    public function upload(UploadStoryStyleReferenceRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('update', $style);

        $reference = $this->references->upload(
            $project,
            $request->file('file'),
            $request->validated('role'),
        );

        return ApiResponse::created(new StoryStyleReferenceResource($reference));
    }

    public function generate(GenerateStoryStyleReferenceRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('update', $style);

        $reference = $this->references->generate($project, $request->validated());

        return ApiResponse::created(new StoryStyleReferenceResource($reference));
    }

    public function show(string $uuid, string $referenceUuid): JsonResponse
    {
        $reference = $this->authorizedReference($uuid, $referenceUuid, 'view');

        return ApiResponse::success(new StoryStyleReferenceResource($reference));
    }

    public function file(string $uuid, string $referenceUuid): StreamedResponse
    {
        $reference = $this->authorizedReference($uuid, $referenceUuid, 'download');
        $bytes = $this->references->fileBytes($reference);

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            'style-reference-'.$reference->version.'.'.$reference->extension,
            [
                'Content-Type' => $reference->mime_type,
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control' => 'private, no-store',
            ],
            'inline',
        );
    }

    public function submitReview(
        ReviewStoryStyleReferenceRequest $request,
        string $uuid,
        string $referenceUuid,
    ): JsonResponse {
        $reference = $this->authorizedReference($uuid, $referenceUuid, 'review');
        $project = $reference->styleBible->workspace->project;

        $updated = $this->references->submitReview(
            $project,
            $referenceUuid,
            $request->user(),
            $request->validated('comment'),
        );

        return ApiResponse::success(new StoryStyleReferenceResource($updated));
    }

    public function approve(
        ReviewStoryStyleReferenceRequest $request,
        string $uuid,
        string $referenceUuid,
    ): JsonResponse {
        $reference = $this->authorizedReference($uuid, $referenceUuid, 'review');
        $project = $reference->styleBible->workspace->project;

        $updated = $this->references->approve(
            $project,
            $referenceUuid,
            $request->user(),
            $request->validated('comment'),
        );

        return ApiResponse::success(new StoryStyleReferenceResource($updated));
    }

    public function reject(
        RejectStoryStyleReferenceRequest $request,
        string $uuid,
        string $referenceUuid,
    ): JsonResponse {
        $reference = $this->authorizedReference($uuid, $referenceUuid, 'review');
        $project = $reference->styleBible->workspace->project;

        $updated = $this->references->reject(
            $project,
            $referenceUuid,
            $request->user(),
            (string) $request->validated('comment'),
        );

        return ApiResponse::success(new StoryStyleReferenceResource($updated));
    }

    public function archive(Request $request, string $uuid, string $referenceUuid): JsonResponse
    {
        $reference = $this->authorizedReference($uuid, $referenceUuid, 'update');
        $project = $reference->styleBible->workspace->project;

        $updated = $this->references->archive(
            $project,
            $referenceUuid,
            $request->user(),
        );

        return ApiResponse::success(new StoryStyleReferenceResource($updated));
    }

    private function authorizedReference(string $uuid, string $referenceUuid, string $ability): StoryStyleReference
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('view', $style);

        $reference = $this->references->getForProject($project, $referenceUuid);
        $this->authorize($ability, $reference);

        return $reference->loadMissing(['styleBible.workspace.project']);
    }
}
