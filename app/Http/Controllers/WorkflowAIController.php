<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateWorkflowAIRequest;
use App\Models\Project;
use App\Models\WorkflowDefinition;
use App\Services\AI\WorkflowAIService;
use App\Services\WorkContextDepartmentService;
use App\Support\ProjectAccessAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class WorkflowAIController extends Controller
{
    public function __construct(
        private readonly ProjectAccessAuthorizer $authorizer,
        private readonly WorkflowAIService $service,
        private readonly WorkContextDepartmentService $departmentAccess,
    ) {}

    public function generate(GenerateWorkflowAIRequest $request, Project $project, WorkflowDefinition $workflow): JsonResponse
    {
        $organizationId = (int) session('current_organization_id');
        $departmentId = (int) session('current_department_id');
        $user = $request->user();
        abort_unless($organizationId && $departmentId && (int) $project->organization_id === $organizationId, 403);
        abort_unless($user->organizations()->whereKey($organizationId)->wherePivot('is_active', true)->exists(), 403);
        abort_unless($this->departmentAccess->canAccessDepartment($user, $organizationId, $departmentId), 403);
        abort_unless((int) $project->department_id === $departmentId || $project->departments()->whereKey($departmentId)->exists(), 403);
        abort_unless((int) $workflow->project_id === (int) $project->id && (int) $workflow->organization_id === $organizationId, 404);
        abort_unless($workflow->status === 'draft', 422, 'Seul un workflow brouillon peut être amélioré par IA.');
        $this->authorizer->authorizeProjectManagement($user, $project, $organizationId, $departmentId);

        $dailyKey = sprintf('workflow-ai:%d:%s', $user->id, now()->toDateString());
        Cache::add($dailyKey, 0, now()->endOfDay());
        if ((int) Cache::get($dailyKey, 0) >= (int) config('services.openai.workflow_daily_limit', 20)) {
            return response()->json(['message' => 'Votre limite quotidienne de générations IA est atteinte.'], 429);
        }
        Cache::increment($dailyKey);

        $validated = $request->validated();
        try {
            $graph = $this->service->generate($validated['description'], $validated['mode'], $validated['current_graph'] ?? null);
        } catch (RuntimeException $exception) {
            $status = $exception->getCode() === 429 ? 429 : 503;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json(['graph' => $graph]);
    }
}
