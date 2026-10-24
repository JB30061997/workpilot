<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskAssigneeController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve current organization from WorkPilot session.
     */
    private function getCurrentOrganization(
        Request $request
    ): Organization {
        $organizations = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->orderBy('name')
            ->get();

        abort_if(
            $organizations->isEmpty(),
            403,
            'Aucune société accessible pour votre compte.'
        );

        $organizationId = session(
            'current_organization_id'
        );

        if (
            ! $organizationId
            || ! $organizations->contains(
                'id',
                (int) $organizationId
            )
        ) {
            $organizationId =
                $organizations->first()->id;

            session([
                'current_organization_id' => $organizationId,
            ]);
        }

        return Organization::query()
            ->findOrFail($organizationId);
    }

    /**
     * Resolve current department from WorkPilot session.
     */
    private function getCurrentDepartment(
        Request $request,
        Organization $organization
    ): Department {
        $departments = $request->user()
            ->departments()
            ->where(
                'departments.organization_id',
                $organization->id
            )
            ->wherePivot('is_active', true)
            ->orderBy('departments.name')
            ->get();

        abort_if(
            $departments->isEmpty(),
            403,
            'Aucun service accessible dans cette société.'
        );

        $departmentId = session(
            'current_department_id'
        );

        if (
            ! $departmentId
            || ! $departments->contains(
                'id',
                (int) $departmentId
            )
        ) {
            $departmentId =
                $departments->first()->id;

            session([
                'current_department_id' => $departmentId,
            ]);
        }

        return Department::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->findOrFail($departmentId);
    }

    /**
     * Resolve current WorkPilot context.
     */
    private function getContext(
        Request $request
    ): array {
        $organization =
            $this->getCurrentOrganization($request);

        $department =
            $this->getCurrentDepartment(
                $request,
                $organization
            );

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    /**
     * Find a task strictly inside current context.
     */
    private function findScopedTask(
        Request $request,
        int $taskId
    ): Task {
        $context = $this->getContext($request);

        return Task::query()
            ->whereKey($taskId)
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(
                'department_id',
                $context['department']->id
            )
            ->firstOrFail();
    }

    /**
     * Find an assignment inside current context.
     */
    private function findScopedAssignment(
        Request $request,
        int $assignmentId
    ): TaskAssignee {
        $context = $this->getContext($request);

        return TaskAssignee::query()
            ->whereKey($assignmentId)
            ->whereHas(
                'task',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organization_id',
                            $context['organization']->id
                        )
                        ->where(
                            'department_id',
                            $context['department']->id
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Find an active user belonging to the current
     * organization and department.
     */
    private function findScopedUser(
        Request $request,
        int $userId
    ): User {
        $context = $this->getContext($request);

        return User::query()
            ->whereKey($userId)

            ->whereHas(
                'organizations',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organizations.id',
                            $context['organization']->id
                        )
                        ->wherePivot(
                            'is_active',
                            true
                        );
                }
            )

            ->whereHas(
                'departments',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'departments.id',
                            $context['department']->id
                        )
                        ->wherePivot(
                            'is_active',
                            true
                        );
                }
            )

            ->firstOrFail();
    }

    /**
     * Display assignees of a task.
     */
    public function index(
        Request $request
    ): JsonResponse {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'task_id' => [
                'required',
                'integer',
                'exists:tasks,id',
            ],

            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        $task = $this->findScopedTask(
            $request,
            (int) $validated['task_id']
        );

        $assignees = TaskAssignee::query()
            ->where(
                'task_id',
                $task->id
            )

            ->when(
                ! empty($validated['user_id']),
                fn ($query) => $query->where(
                    'user_id',
                    $validated['user_id']
                )
            )

            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim(
                        $validated['search']
                    );

                    $query->whereHas(
                        'user',
                        function ($userQuery) use ($search) {
                            $userQuery
                                ->where(
                                    'name',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    '%'.$search.'%'
                                );
                        }
                    );
                }
            )

            ->with([
                'user:id,name,email',
                'assigner:id,name,email',
                'task:id,organization_id,department_id,title,status_id,priority,due_at,progress',
            ])

            ->latest('assigned_at')
            ->latest('id')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $context['organization']->id,

                'name' => $context['organization']->name,
            ],

            'department' => [
                'id' => $context['department']->id,

                'name' => $context['department']->name,
            ],

            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'progress' => $task->progress,
                'due_at' => $task->due_at,
            ],

            'assignees' => $assignees,
        ]);
    }

    /**
     * Assign a user to a task.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $data = $request->validate([
            'task_id' => [
                'required',
                'integer',
                'exists:tasks,id',
            ],

            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'assigned_at' => [
                'nullable',
                'date',
            ],
        ], [
            'task_id.required' => 'La tâche est obligatoire.',

            'task_id.exists' => 'La tâche sélectionnée est introuvable.',

            'user_id.required' => 'L’utilisateur à affecter est obligatoire.',

            'user_id.exists' => 'L’utilisateur sélectionné est introuvable.',

            'assigned_at.date' => 'La date d’affectation est invalide.',
        ]);

        $task = $this->findScopedTask(
            $request,
            (int) $data['task_id']
        );

        $user = $this->findScopedUser(
            $request,
            (int) $data['user_id']
        );

        /*
         * Same user cannot be assigned twice
         * to the same task.
         */
        $alreadyAssigned =
            TaskAssignee::query()
                ->where(
                    'task_id',
                    $task->id
                )
                ->where(
                    'user_id',
                    $user->id
                )
                ->exists();

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur est déjà affecté à cette tâche.',
            ]);
        }

        DB::transaction(
            function () use (
                $request,
                $task,
                $user,
                $data
            ) {
                TaskAssignee::create([
                    'task_id' => $task->id,

                    'user_id' => $user->id,

                    /*
                     * Security:
                     * assigned_by is always controlled
                     * by the backend.
                     */
                    'assigned_by' => $request->user()->id,

                    'assigned_at' => $data['assigned_at']
                        ?? now(),
                ]);
            }
        );

        return back()->with(
            'success',
            'Utilisateur affecté à la tâche avec succès.'
        );
    }

    /**
     * Display one task assignment.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $assignment =
            $this->findScopedAssignment(
                $request,
                (int) $id
            );

        $assignment->load([
            'user:id,name,email',

            'assigner:id,name,email',

            'task:id,organization_id,department_id,project_id,action_id,status_id,created_by,parent_id,title,priority,start_at,due_at,progress,completed_at',
        ]);

        return response()->json([
            'assignment' => $assignment,
        ]);
    }

    /**
     * Update assignment metadata.
     *
     * task_id, user_id and assigned_by are immutable.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $assignment =
            $this->findScopedAssignment(
                $request,
                (int) $id
            );

        $data = $request->validate([
            'assigned_at' => [
                'sometimes',
                'required',
                'date',
            ],
        ], [
            'assigned_at.required' => 'La date d’affectation est obligatoire.',

            'assigned_at.date' => 'La date d’affectation est invalide.',
        ]);

        if ($data !== []) {
            DB::transaction(
                function () use (
                    $assignment,
                    $data
                ) {
                    $assignment->update($data);
                }
            );
        }

        return back()->with(
            'success',
            'Affectation mise à jour avec succès.'
        );
    }

    /**
     * Remove a user assignment from a task.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $assignment =
            $this->findScopedAssignment(
                $request,
                (int) $id
            );

        DB::transaction(
            function () use ($assignment) {
                $assignment->delete();
            }
        );

        return back()->with(
            'success',
            'Utilisateur retiré de la tâche avec succès.'
        );
    }
}
