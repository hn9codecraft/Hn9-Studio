<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\AgentExecutionServiceInterface;
use App\Contracts\Services\HistoryServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRunResource;
use App\Models\WorkflowRun;
use App\Support\ApiResponse;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowRunController extends Controller
{
    public function __construct(
        private WorkflowServiceInterface $workflows,
        private AgentExecutionServiceInterface $executions,
        private HistoryServiceInterface $history,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkflowRun::class);

        $perPage = (int) ($request->query('per_page', $request->query('perPage', 15)));
        $filters = $request->only(['status', 'provider', 'project', 'workflow', 'created_from', 'created_to', 'sort', 'order', 'search']);

        $page = $this->workflows->paginateForUser($request->user(), $perPage, $filters);

        return ApiResponse::success(WorkflowRunResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $workflow = $this->workflows->getByUuid($uuid);

        $this->authorize('view', $workflow);

        return ApiResponse::success(new WorkflowRunResource($workflow));
    }

    public function timeline(string $uuid): JsonResponse
    {
        $workflow = $this->workflows->getByUuid($uuid);

        $this->authorize('view', $workflow);

        $executions = $this->executions->forWorkflowRun($workflow);

        $timeline = $executions->map(fn (mixed $execution): array => [
            'id' => $execution->uuid,
            'type' => 'agent_execution',
            'status' => $execution->status,
            'agent_key' => $execution->agent_key,
            'started_at' => $this->serializeTimestamp($execution->started_at),
            'finished_at' => $this->serializeTimestamp($execution->finished_at),
        ]);

        return ApiResponse::success([
            'workflow' => new WorkflowRunResource($workflow),
            'timeline' => $timeline,
        ]);
    }

    private function serializeTimestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    public function logs(string $uuid): JsonResponse
    {
        $workflow = $this->workflows->getByUuid($uuid);

        $this->authorize('view', $workflow);

        $entries = $this->history->forSubject($workflow, 100);

        return ApiResponse::success($entries->map(fn ($entry) => [
            'id' => $entry->uuid,
            'action' => $entry->action,
            'description' => $entry->description,
            'created_at' => $entry->created_at?->toIso8601String(),
        ])->values());
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        $workflow = $this->workflows->getByUuid($uuid);

        $this->authorize('update', $workflow);

        $updated = $this->workflows->retry($workflow, $request->user());

        return ApiResponse::success(new WorkflowRunResource($updated));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $workflow = $this->workflows->getByUuid($uuid);

        $this->authorize('update', $workflow);

        $updated = $this->workflows->cancel($workflow, $request->user());

        return ApiResponse::success(new WorkflowRunResource($updated));
    }
}
