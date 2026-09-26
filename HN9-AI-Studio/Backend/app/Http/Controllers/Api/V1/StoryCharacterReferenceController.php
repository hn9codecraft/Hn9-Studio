<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateStoryCharacterReferenceRequest;
use App\Http\Requests\RejectStoryCharacterReferenceRequest;
use App\Http\Requests\ReviewStoryCharacterReferenceRequest;
use App\Http\Requests\UploadStoryCharacterReferenceRequest;
use App\Http\Resources\StoryCharacterReferenceResource;
use App\Story\Contracts\StoryCharacterReferenceServiceInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoryCharacterReferenceController extends Controller
{
    public function __construct(
        private StoryCharacterReferenceServiceInterface $references,
        private StoryCharacterServiceInterface $characters,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize('view', $character);

        $items = $this->references->listForCharacter($project, $characterUuid);

        return ApiResponse::success(StoryCharacterReferenceResource::collection($items));
    }

    public function upload(UploadStoryCharacterReferenceRequest $request, string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize('update', $character);

        $reference = $this->references->upload(
            $project,
            $characterUuid,
            $request->file('file'),
            $request->validated('role'),
        );

        return ApiResponse::created(new StoryCharacterReferenceResource($reference));
    }

    public function generate(GenerateStoryCharacterReferenceRequest $request, string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize('update', $character);

        $reference = $this->references->generate($project, $characterUuid, $request->validated());

        return ApiResponse::created(new StoryCharacterReferenceResource($reference));
    }

    public function show(string $uuid, string $characterUuid, string $referenceUuid): JsonResponse
    {
        $reference = $this->authorizedReference($uuid, $characterUuid, $referenceUuid, 'view');

        return ApiResponse::success(new StoryCharacterReferenceResource($reference));
    }

    public function file(string $uuid, string $characterUuid, string $referenceUuid): StreamedResponse
    {
        $reference = $this->authorizedReference($uuid, $characterUuid, $referenceUuid, 'download');
        $bytes = $this->references->fileBytes($reference);

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            'character-reference-'.$reference->version.'.'.$reference->extension,
            [
                'Content-Type' => $reference->mime_type,
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control' => 'private, no-store',
            ],
            'inline',
        );
    }

    public function submitReview(
        ReviewStoryCharacterReferenceRequest $request,
        string $uuid,
        string $characterUuid,
        string $referenceUuid,
    ): JsonResponse {
        $reference = $this->authorizedReference($uuid, $characterUuid, $referenceUuid, 'review');
        $project = $reference->character->workspace->project;

        $updated = $this->references->submitReview(
            $project,
            $characterUuid,
            $referenceUuid,
            $request->user(),
            $request->validated('comment'),
        );

        return ApiResponse::success(new StoryCharacterReferenceResource($updated));
    }

    public function approve(
        ReviewStoryCharacterReferenceRequest $request,
        string $uuid,
        string $characterUuid,
        string $referenceUuid,
    ): JsonResponse {
        $reference = $this->authorizedReference($uuid, $characterUuid, $referenceUuid, 'review');
        $project = $reference->character->workspace->project;

        $updated = $this->references->approve(
            $project,
            $characterUuid,
            $referenceUuid,
            $request->user(),
            $request->validated('comment'),
        );

        return ApiResponse::success(new StoryCharacterReferenceResource($updated));
    }

    public function reject(
        RejectStoryCharacterReferenceRequest $request,
        string $uuid,
        string $characterUuid,
        string $referenceUuid,
    ): JsonResponse {
        $reference = $this->authorizedReference($uuid, $characterUuid, $referenceUuid, 'review');
        $project = $reference->character->workspace->project;

        $updated = $this->references->reject(
            $project,
            $characterUuid,
            $referenceUuid,
            $request->user(),
            (string) $request->validated('comment'),
        );

        return ApiResponse::success(new StoryCharacterReferenceResource($updated));
    }

    public function archive(Request $request, string $uuid, string $characterUuid, string $referenceUuid): JsonResponse
    {
        $reference = $this->authorizedReference($uuid, $characterUuid, $referenceUuid, 'update');
        $project = $reference->character->workspace->project;

        $updated = $this->references->archive(
            $project,
            $characterUuid,
            $referenceUuid,
            $request->user(),
        );

        return ApiResponse::success(new StoryCharacterReferenceResource($updated));
    }

    private function authorizedReference(
        string $uuid,
        string $characterUuid,
        string $referenceUuid,
        string $ability,
    ): StoryCharacterReference {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize('view', $character);

        $reference = $this->references->getForCharacter($project, $characterUuid, $referenceUuid);
        $this->authorize($ability, $reference);

        return $reference->loadMissing(['character.workspace.project']);
    }
}
