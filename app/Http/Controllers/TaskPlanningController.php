<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskPlanningRequest;
use App\Http\Requests\UpdateTaskPlanningRequest;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPlanning;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskPlanningController extends Controller
{
    private const SORTABLE_COLUMNS = ['start_at', 'end_at', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TaskPlanning::class);
        $context = $this->context($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'status_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $sort = $validated['sort'] ?? 'start_at';
        $direction = $validated['direction'] ?? 'asc';

        $plannings = TaskPlanning::query()
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->whereHas('task', fn (Builder $query) => $this->visibleTasks($query, $request))
            ->with($this->taskRelations())
            ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query->whereHas(
                'task', fn (Builder $taskQuery) => $taskQuery->where('title', 'like', '%'.trim($search).'%')
            ))
            ->when($validated['project_id'] ?? null, fn (Builder $query, int $projectId) => $query->whereHas(
                'task', fn (Builder $taskQuery) => $taskQuery->where('project_id', $projectId)
            ))
            ->when($validated['assignee_id'] ?? null, fn (Builder $query, int $assigneeId) => $query->whereHas(
                'task.assignees', fn (Builder $assigneeQuery) => $assigneeQuery->whereKey($assigneeId)
            ))
            ->when($validated['status_id'] ?? null, fn (Builder $query, int $statusId) => $query->whereHas(
                'task', fn (Builder $taskQuery) => $taskQuery->where('status_id', $statusId)
            ))
            ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query->where('end_at', '>=', $from.' 00:00:00'))
            ->when($validated['to'] ?? null, fn (Builder $query, string $to) => $query->where('start_at', '<=', $to.' 23:59:59'))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        $tasks = Task::query()
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->tap(fn (Builder $query) => $this->visibleTasks($query, $request))
            ->with([
                'status:id,name,slug,color,is_closed',
                'project:id,action_plan_id,name',
                'project.actionPlan:id,name',
                'assignees:id,name,email',
            ])
            ->orderBy('title')
            ->get();

        return Inertia::render('Planning/Index', [
            'plannings' => $plannings,
            'tasks' => $tasks,
            'projects' => Project::query()->where('organization_id', $context['organization']->id)
                ->where('department_id', $context['department']->id)->orderBy('name')->get(['id', 'name']),
            'members' => User::query()->whereHas('departments', fn (Builder $query) => $query
                ->whereKey($context['department']->id)->where('department_members.is_active', true))
                ->orderBy('name')->get(['id', 'name']),
            'statuses' => TaskStatus::query()->where('organization_id', $context['organization']->id)
                ->where('is_active', true)->orderBy('position')->get(['id', 'name', 'slug', 'color', 'is_closed']),
            'filters' => array_merge([
                'search' => '', 'project_id' => '', 'assignee_id' => '', 'status_id' => '',
                'from' => '', 'to' => '', 'sort' => $sort, 'direction' => $direction, 'per_page' => 25,
            ], $validated),
        ]);
    }

    public function store(StoreTaskPlanningRequest $request): RedirectResponse
    {
        Gate::authorize('create', TaskPlanning::class);
        $context = $this->context($request);
        $task = $this->scopedTask($request, $request->integer('task_id'));
        Gate::authorize('view', $task);
        TaskPlanning::query()->create([
            'organization_id' => $context['organization']->id,
            'department_id' => $context['department']->id,
            'task_id' => $task->id,
            'created_by' => $request->user()->id,
            'start_at' => $request->validated('start_at'),
            'end_at' => $request->validated('end_at'),
        ]);

        return back()->with('success', 'La tâche a été ajoutée au planning.');
    }

    public function update(UpdateTaskPlanningRequest $request, TaskPlanning $taskPlanning): RedirectResponse
    {
        $taskPlanning = $this->scopedPlanning($request, $taskPlanning->id);
        Gate::authorize('update', $taskPlanning);
        $taskPlanning->update($request->validated());

        return back()->with('success', 'La planification a été mise à jour.');
    }

    public function destroy(Request $request, TaskPlanning $taskPlanning): RedirectResponse
    {
        $taskPlanning = $this->scopedPlanning($request, $taskPlanning->id);
        Gate::authorize('delete', $taskPlanning);
        $taskPlanning->delete();

        return back()->with('success', 'La tâche a été retirée du planning.');
    }

    public function duplicate(UpdateTaskPlanningRequest $request, TaskPlanning $taskPlanning): RedirectResponse
    {
        $taskPlanning = $this->scopedPlanning($request, $taskPlanning->id);
        Gate::authorize('update', $taskPlanning);
        $taskPlanning->replicate(['start_at', 'end_at', 'is_automatic'])
            ->fill([
                ...$request->validated(),
                'is_automatic' => false,
            ])
            ->save();

        return back()->with('success', 'La planification a été dupliquée.');
    }

    /** @return array{organization: Organization, department: Department} */
    private function context(Request $request): array
    {
        $organizations = $request->user()->organizations()->wherePivot('is_active', true)->orderBy('name')->get();
        abort_if($organizations->isEmpty(), 403, 'Aucune société accessible pour votre compte.');
        $organizationId = (int) session('current_organization_id');
        if (! $organizations->contains('id', $organizationId)) {
            $organizationId = (int) $organizations->first()->id;
            session(['current_organization_id' => $organizationId]);
        }
        $organization = Organization::query()->findOrFail($organizationId);
        $departments = $request->user()->departments()->where('departments.organization_id', $organizationId)
            ->wherePivot('is_active', true)->orderBy('departments.name')->get();
        abort_if($departments->isEmpty(), 403, 'Aucun service accessible dans cette société.');
        $departmentId = (int) session('current_department_id');
        if (! $departments->contains('id', $departmentId)) {
            $departmentId = (int) $departments->first()->id;
            session(['current_department_id' => $departmentId]);
        }

        return [
            'organization' => $organization,
            'department' => Department::query()->where('organization_id', $organizationId)->findOrFail($departmentId),
        ];
    }

    private function visibleTasks(Builder $query, Request $request): Builder
    {
        return $query->where(function (Builder $visibilityQuery) use ($request) {
            $visibilityQuery->whereNull('work_session_id')->orWhereHas('workSession', function (Builder $sessionQuery) use ($request) {
                $sessionQuery->where('visibility', 'department')->orWhere('created_by', $request->user()->id)
                    ->orWhereHas('members', fn (Builder $memberQuery) => $memberQuery->whereKey($request->user()->id));
            });
        });
    }

    private function scopedTask(Request $request, int $taskId): Task
    {
        $context = $this->context($request);

        return Task::query()->whereKey($taskId)->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)->firstOrFail();
    }

    private function scopedPlanning(Request $request, int $planningId): TaskPlanning
    {
        $context = $this->context($request);

        return TaskPlanning::query()->whereKey($planningId)->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)->with('task.workSession')->firstOrFail();
    }

    /** @return array<int, string> */
    private function taskRelations(): array
    {
        return [
            'task.status:id,name,slug,color,is_closed',
            'task.project:id,action_plan_id,name',
            'task.project.actionPlan:id,name',
            'task.assignees:id,name,email',
        ];
    }
}
