<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyStoryVoiceTimingRequest;
use App\Http\Requests\PreviewStoryVoiceTimingRequest;
use App\Models\User;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryVoiceTimingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryVoiceTimingController extends Controller
{
    public function __construct(
        private StoryVoiceTimingService $timing,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(PreviewStoryVoiceTimingRequest $request, string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->timing->preview($project, $planUuid, $request->validated()));
    }

    public function apply(ApplyStoryVoiceTimingRequest $request, string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return ApiResponse::success($this->timing->apply($project, $planUuid, $request->validated(), $user));
    }
}
