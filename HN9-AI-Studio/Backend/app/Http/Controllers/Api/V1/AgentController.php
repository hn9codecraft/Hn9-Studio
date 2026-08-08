<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\AgentExecutionServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\WorkflowRun;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentController extends Controller
{
    public function __construct(
        private AgentExecutionServiceInterface $executions,
        private WorkflowServiceInterface $workflows,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkflowRun::class);

        $dir = base_path('../Agents/agents');
        $list = [];

        if (is_dir($dir)) {
            foreach (scandir($dir) as $file) {
                if (str_starts_with($file, '.')) {
                    continue;
                }

                $name = pathinfo($file, PATHINFO_FILENAME);

                $list[] = ['id' => $name, 'file' => $file];
            }
        }

        return ApiResponse::success($list);
    }

    public function show(string $agentUuid): JsonResponse
    {
        $this->authorize('viewAny', WorkflowRun::class);

        $path = base_path('../Agents/agents/'.$agentUuid.'.md');

        if (! is_file($path)) {
            return ApiResponse::error('Not found', 'not_found', 404);
        }

        $content = file_get_contents($path);

        return ApiResponse::success(['id' => $agentUuid, 'content' => $content]);
    }

    public function forWorkflow(string $workflowUuid): JsonResponse
    {
        $workflow = $this->workflows->getByUuid($workflowUuid);

        $this->authorize('view', $workflow);

        $executions = $this->executions->forWorkflowRun($workflow);

        $agents = $executions->map(fn ($e) => [
            'agent_key' => $e->agent_key,
            'status' => $e->status,
        ])->values();

        return ApiResponse::success(['workflow' => $workflowUuid, 'agents' => $agents]);
    }

    public function forProject(string $projectUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $runs = $this->workflows->forProject($project);

        $all = [];

        foreach ($runs as $run) {
            $executions = $this->executions->forWorkflowRun($run);
            foreach ($executions as $e) {
                $all[] = [
                    'workflow' => $run->uuid,
                    'agent_key' => $e->agent_key,
                    'status' => $e->status,
                ];
            }
        }

        return ApiResponse::success($all);
    }
}
