<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryCharacterLockService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryCharacterLockController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryCharacterLockService $locks,
    ) {}

    public function store(Request $request, string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $payload = $request->validate([
            'reference_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $result = $this->locks->lock($project, $characterUuid, $this->actor($request), $payload['reference_uuid'] ?? null);

        return ApiResponse::success([
            'changed' => $result['changed'],
            'lock' => $this->locks->present($result['lock']),
        ]);
    }

    public function destroy(Request $request, string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $result = $this->locks->unlock($project, $characterUuid, $this->actor($request));

        return ApiResponse::success([
            'changed' => $result['changed'],
            'lock' => null,
        ]);
    }

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $rows = $this->locks->listForProject($project)->map(function ($lock): array {
            return [
                'character_uuid' => $lock->character?->uuid,
                'name' => $lock->character?->name,
                'reference_uuid' => $lock->reference?->uuid,
                'identity_text' => $lock->identity_text,
                'locked_at' => $lock->locked_at?->toIso8601String(),
                'locked_by' => $lock->lockedBy?->name,
            ];
        })->values();

        return ApiResponse::success($rows);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
