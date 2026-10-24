<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeSlot;
use App\Models\WorkSession;
use App\Services\TaskPlanningSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    private const PRIORITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    public function __construct(
        private readonly TaskPlanningSynchronizer $taskPlanningSynchronizer
    ) {}

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
     * Find a task inside current context.
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
     * Find a time slot through its WorkSession context.
     */
    private function findScopedTimeSlot(Request $request, int $timeSlotId): TimeSlot
    {
        $context = $this->getContext($request);

        return TimeSlot::query()
            ->whereKey($timeSlotId)
            ->whereHas('workSession', fn ($query) => $query
                ->where('organization_id', $context['organization']->id)
                ->where('department_id', $context['department']->id))
            ->with('workSession:id,project_id')
            ->firstOrFail();
    }

    private function findScopedWorkSession(Request $request, int $workSessionId): WorkSession
    {
        $context = $this->getContext($request);

        return WorkSession::query()
            ->whereKey($workSessionId)
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->firstOrFail();
    }

    /**
     * Find an active status belonging to current organization.
     */
    private function findScopedStatus(
        Request $request,
        int $statusId
    ): TaskStatus {
        $context = $this->getContext($request);

        return TaskStatus::query()
            ->whereKey($statusId)
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Find project inside current organization/service.
     */
    private function findScopedProject(
        Request $request,
        int $projectId
    ): Project {
        $context = $this->getContext($request);

        return Project::query()
            ->whereKey($projectId)
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
     * Find action inside current organization/service.
     */
    private function findScopedAction(
        Request $request,
        int $actionId
    ): Action {
        $context = $this->getContext($request);

        return Action::query()
            ->whereKey($actionId)
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(function ($query) use ($context) {
                $query
                    ->whereHas(
                        'project',
                        fn ($projectQuery) => $projectQuery->where(
                            'department_id',
                            $context['department']->id
                        )
                    )
                    ->orWhereHas(
                        'actionPlan',
                        fn ($planQuery) => $planQuery->where(
                            'department_id',
                            $context['department']->id
                        )
                    );
            })
            ->firstOrFail();
    }

    /**
     * Resolve default task status for current organization.
     */
    private function getDefaultStatus(
        Organization $organization
    ): TaskStatus {
        $status = TaskStatus::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('position')
            ->first();

        if (! $status) {
            $status = TaskStatus::query()
                ->where(
                    'organization_id',
                    $organization->id
                )
                ->where('is_active', true)
                ->orderBy('position')
                ->first();
        }

        if (! $status) {
            throw ValidationException::withMessages([
                'status_id' => 'Aucun statut de tâche actif n’est configuré pour cette société.',
            ]);
        }

        return $status;
    }

    /**
     * Display tasks of current service.
     */
    public function index(
        Request $request
    ): JsonResponse|Response {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],

            'action_id' => [
                'nullable',
                'integer',
                'exists:actions,id',
            ],

            'status_id' => [
                'nullable',
                'integer',
                'exists:task_statuses,id',
            ],

            'assignee_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
            ],

            'priority' => [
                'nullable',
                Rule::in(self::PRIORITIES),
            ],

            'is_private' => [
                'nullable',
                'boolean',
            ],

            'overdue' => [
                'nullable',
                'boolean',
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

        if (! empty($validated['project_id'])) {
            $this->findScopedProject(
                $request,
                (int) $validated['project_id']
            );
        }

        if (! empty($validated['action_id'])) {
            $this->findScopedAction(
                $request,
                (int) $validated['action_id']
            );
        }

        if (! empty($validated['status_id'])) {
            $this->findScopedStatus(
                $request,
                (int) $validated['status_id']
            );
        }

        if (! empty($validated['parent_id'])) {
            $this->findScopedTask(
                $request,
                (int) $validated['parent_id']
            );
        }

        $tasks = Task::query()
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(
                'department_id',
                $context['department']->id
            )
            ->where(function ($query) use ($request) {
                $query->whereNull('work_session_id')
                    ->orWhereHas('workSession', function ($sessionQuery) use ($request) {
                        $sessionQuery->where('visibility', 'department')
                            ->orWhere('created_by', $request->user()->id)
                            ->orWhereHas('members', fn ($memberQuery) => $memberQuery->whereKey($request->user()->id));
                    });
            })

            ->when(
                ! empty($validated['project_id']),
                fn ($query) => $query->where(
                    'project_id',
                    $validated['project_id']
                )
            )

            ->when(
                ! empty($validated['action_id']),
                fn ($query) => $query->where(
                    'action_id',
                    $validated['action_id']
                )
            )

            ->when(
                ! empty($validated['status_id']),
                fn ($query) => $query->where(
                    'status_id',
                    $validated['status_id']
                )
            )

            ->when(
                ! empty($validated['assignee_id']),
                function ($query) use ($validated) {
                    $query->whereHas(
                        'assignees',
                        fn ($assigneeQuery) => $assigneeQuery->where(
                            'users.id',
                            $validated['assignee_id']
                        )
                    );
                }
            )

            ->when(
                ! empty($validated['parent_id']),
                fn ($query) => $query->where(
                    'parent_id',
                    $validated['parent_id']
                )
            )

            ->when(
                ! empty($validated['priority']),
                fn ($query) => $query->where(
                    'priority',
                    $validated['priority']
                )
            )

            ->when(
                array_key_exists(
                    'is_private',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_private',
                    $validated['is_private']
                )
            )

            ->when(
                ($validated['overdue'] ?? false) === true,
                function ($query) {
                    $query
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', now())
                        ->whereHas(
                            'status',
                            fn ($statusQuery) => $statusQuery->where(
                                'is_closed',
                                false
                            )
                        );
                }
            )

            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim(
                        $validated['search']
                    );

                    $query->where(
                        function ($subQuery) use ($search) {
                            $subQuery
                                ->where(
                                    'title',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'description',
                                    'like',
                                    '%'.$search.'%'
                                );
                        }
                    );
                }
            )

            ->with([
                'status:id,organization_id,name,slug,color,position,is_closed',
                'project:id,name,code',
                'action:id,title',
                'creator:id,name,email',
                'parent:id,title',
                'assignees:id,name,email',
            ])

            ->withCount([
                'subtasks',
                'checklistItems',
                'comments',
                'attachments',
                'timeSlots',
            ])

            ->orderBy('position')
            ->orderBy('due_at')
            ->latest('id')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        $payload = [
            'organization' => [
                'id' => $context['organization']->id,

                'name' => $context['organization']->name,
            ],

            'department' => [
                'id' => $context['department']->id,

                'name' => $context['department']->name,
            ],

            'tasks' => $tasks,
            'filters' => $validated,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Tasks/Index', [
            ...$payload,
            'statuses' => TaskStatus::query()
                ->where('organization_id', $context['organization']->id)
                ->where('is_active', true)
                ->orderBy('position')
                ->get(['id', 'name', 'slug', 'color', 'is_default', 'is_closed']),
            'projects' => Project::query()
                ->where('organization_id', $context['organization']->id)
                ->where('department_id', $context['department']->id)
                ->where('is_active', true)
                ->with([
                    'actionPlan:id,name,code',
                    'axis:id,name,code',
                ])
                ->orderBy('name')
                ->get(['id', 'action_plan_id', 'axis_id', 'name', 'code']),
            'actions' => Action::query()
                ->where('organization_id', $context['organization']->id)
                ->where(function ($query) use ($context) {
                    $query
                        ->whereHas(
                            'project',
                            fn ($projectQuery) => $projectQuery->where(
                                'department_id',
                                $context['department']->id
                            )
                        )
                        ->orWhereHas(
                            'actionPlan',
                            fn ($planQuery) => $planQuery->where(
                                'department_id',
                                $context['department']->id
                            )
                        );
                })
                ->orderBy('position')
                ->get(['id', 'project_id', 'title']),
            'members' => $context['department']->users()
                ->wherePivot('is_active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email']),
            'priorities' => self::PRIORITIES,
        ]);
    }

    /**
     * Store a new task.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $context = $this->getContext($request);

        $data = $request->validate([
            'work_session_id' => [
                'nullable',
                'integer',
                'exists:work_sessions,id',
            ],

            'time_slot_id' => [
                'nullable',
                'integer',
                'exists:time_slots,id',
            ],

            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],

            'action_id' => [
                'nullable',
                'integer',
                'exists:actions,id',
            ],

            'status_id' => [
                'nullable',
                'integer',
                'exists:task_statuses,id',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'priority' => [
                'required',
                Rule::in(self::PRIORITIES),
            ],

            'assignee_ids' => [
                'nullable',
                'array',
            ],

            'assignee_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
            ],

            'start_at' => [
                'nullable',
                'date',
            ],

            'due_at' => [
                'nullable',
                'date',
                'after_or_equal:start_at',
            ],

            'estimated_minutes' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'actual_minutes' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'progress' => [
                'nullable',
                'integer',
                'between:0,100',
            ],

            'position' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_private' => [
                'nullable',
                'boolean',
            ],
        ], [
            'title.required' => 'Le titre de la tâche est obligatoire.',

            'priority.required' => 'La priorité de la tâche est obligatoire.',

            'due_at.after_or_equal' => 'La date d’échéance doit être postérieure ou égale à la date de début.',

            'progress.between' => 'La progression doit être comprise entre 0 et 100.',

            'estimated_minutes.min' => 'La durée estimée ne peut pas être négative.',

            'actual_minutes.min' => 'La durée réelle ne peut pas être négative.',
        ]);

        $project = null;
        $action = null;
        $parent = null;
        $timeSlot = null;
        $workSession = null;

        if (! empty($data['work_session_id'])) {
            $workSession = $this->findScopedWorkSession($request, (int) $data['work_session_id']);

            if (! $request->user()->can('view', $workSession)) {
                abort(404);
            }

            $data['project_id'] = $workSession->project_id;
        }

        if (! empty($data['time_slot_id'])) {
            $timeSlot = $this->findScopedTimeSlot($request, (int) $data['time_slot_id']);

            if ($timeSlot->task_id !== null) {
                throw ValidationException::withMessages([
                    'time_slot_id' => 'Ce créneau possède déjà une tâche.',
                ]);
            }

            $data['project_id'] = $timeSlot->workSession->project_id;
            $workSession = $timeSlot->workSession;
            $data['work_session_id'] = $workSession->id;
        }

        if (! empty($data['project_id'])) {
            $project = $this->findScopedProject(
                $request,
                (int) $data['project_id']
            );
        }

        if (! empty($data['action_id'])) {
            $action = $this->findScopedAction(
                $request,
                (int) $data['action_id']
            );
        }

        if (! empty($data['parent_id'])) {
            $parent = $this->findScopedTask(
                $request,
                (int) $data['parent_id']
            );
        }

        /*
         * If both project and action are provided,
         * they must be consistent when the Action
         * itself belongs to a project.
         */
        if (
            $project !== null
            && $action !== null
            && $action->project_id !== null
            && (int) $action->project_id
            !== (int) $project->id
        ) {
            throw ValidationException::withMessages([
                'action_id' => 'L’action sélectionnée n’appartient pas au projet sélectionné.',
            ]);
        }

        /*
         * If parent task exists, project/action hierarchy
         * must remain coherent.
         */
        if (
            $parent !== null
            && $project !== null
            && $parent->project_id !== null
            && (int) $parent->project_id
            !== (int) $project->id
        ) {
            throw ValidationException::withMessages([
                'parent_id' => 'La tâche parente n’appartient pas au projet sélectionné.',
            ]);
        }

        $status = ! empty($data['status_id'])
            ? $this->findScopedStatus(
                $request,
                (int) $data['status_id']
            )
            : $this->getDefaultStatus(
                $context['organization']
            );

        $progress = (int) (
            $data['progress'] ?? 0
        );

        /*
         * A closed status means completed task.
         */
        if ($status->is_closed) {
            $progress = 100;
        }

        $assigneeIds = collect($data['assignee_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $accessibleAssigneeIds = $workSession !== null
            ? $workSession->members()->whereKey($assigneeIds)->pluck('users.id')
            : $context['department']->users()
                ->wherePivot('is_active', true)
                ->whereKey($assigneeIds)
                ->pluck('users.id');

        if ($accessibleAssigneeIds->count() !== $assigneeIds->count()) {
            throw ValidationException::withMessages([
                'assignee_ids' => $workSession !== null
                    ? 'Un ou plusieurs responsables ne sont pas membres de cette session.'
                    : 'Un ou plusieurs responsables ne sont pas membres actifs du service actuel.',
            ]);
        }

        DB::transaction(
            function () use (
                $request,
                $context,
                $data,
                $project,
                $action,
                $parent,
                $status,
                $progress,
                $assigneeIds,
                $timeSlot,
                $workSession
            ) {
                $task = Task::create([
                    'organization_id' => $context['organization']->id,

                    'department_id' => $context['department']->id,

                    'project_id' => $project?->id,

                    'action_id' => $action?->id,

                    'work_session_id' => $workSession?->id,

                    'status_id' => $status->id,

                    'created_by' => $request->user()->id,

                    'parent_id' => $parent?->id,

                    'title' => trim($data['title']),

                    'description' => $data['description'] ?? null,

                    'priority' => $data['priority'],

                    'start_at' => $data['start_at'] ?? null,

                    'due_at' => $data['due_at'] ?? null,

                    'estimated_minutes' => $data['estimated_minutes'] ?? null,

                    'actual_minutes' => (int) ($data['actual_minutes'] ?? 0),

                    'progress' => $progress,

                    'position' => (int) ($data['position'] ?? 0),

                    'completed_at' => $status->is_closed
                        ? now()
                        : null,

                    'is_private' => (bool) (
                        $data['is_private']
                        ?? false
                    ),
                ]);

                $task->assignees()->attach(
                    $assigneeIds->mapWithKeys(fn (int $userId) => [
                        $userId => [
                            'assigned_by' => $request->user()->id,
                            'assigned_at' => now(),
                        ],
                    ])->all()
                );

                $this->taskPlanningSynchronizer->sync($task);

                $timeSlot?->update(['task_id' => $task->id]);
            }
        );

        return back()->with(
            'success',
            'Tâche créée avec succès.'
        );
    }

    /**
     * Display one task.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $task = $this->findScopedTask(
            $request,
            (int) $id
        );

        Gate::authorize('view', $task);

        $task->load([
            'organization:id,name',
            'department:id,name,code',

            'status:id,organization_id,name,slug,color,position,is_default,is_closed,is_active',

            'project:id,name,code',
            'action:id,title',

            'creator:id,name,email',
            'parent:id,title',

            'assignees:id,name,email',

            'subtasks' => function ($query) {
                $query
                    ->with([
                        'status:id,name,slug,color,is_closed',
                        'assignees:id,name,email',
                    ])
                    ->orderBy('position')
                    ->orderBy('due_at');
            },

            'checklistItems',

            'comments' => function ($query) {
                $query
                    ->with('user:id,name,email')
                    ->latest();
            },

            'attachments',

            'timeSlots',
        ]);

        $task->loadCount([
            'subtasks',
            'checklistItems',
            'comments',
            'attachments',
            'timeSlots',
        ]);

        return response()->json([
            'task' => $task,
        ]);
    }

    /**
     * Update a task.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $task = $this->findScopedTask(
            $request,
            (int) $id
        );

        Gate::authorize('update', $task);

        $data = $request->validate([
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:projects,id',
            ],

            'action_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:actions,id',
            ],

            'status_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:task_statuses,id',
            ],

            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:tasks,id',
            ],

            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:10000',
            ],

            'priority' => [
                'sometimes',
                'required',
                Rule::in(self::PRIORITIES),
            ],

            'assignee_ids' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'assignee_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
            ],

            'start_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'due_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'estimated_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'actual_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'progress' => [
                'sometimes',
                'integer',
                'between:0,100',
            ],

            'position' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'is_private' => [
                'sometimes',
                'boolean',
            ],
        ]);

        /*
         * Resolve final project.
         */
        $finalProject = null;

        $finalProjectId =
            array_key_exists('project_id', $data)
            ? $data['project_id']
            : $task->project_id;

        if ($finalProjectId !== null) {
            $finalProject =
                $this->findScopedProject(
                    $request,
                    (int) $finalProjectId
                );
        }

        /*
         * Resolve final action.
         */
        $finalAction = null;

        $finalActionId =
            array_key_exists('action_id', $data)
            ? $data['action_id']
            : $task->action_id;

        if ($finalActionId !== null) {
            $finalAction =
                $this->findScopedAction(
                    $request,
                    (int) $finalActionId
                );
        }

        /*
         * Resolve final parent.
         */
        $finalParent = null;

        $finalParentId =
            array_key_exists('parent_id', $data)
            ? $data['parent_id']
            : $task->parent_id;

        if ($finalParentId !== null) {
            if (
                (int) $finalParentId
                === (int) $task->id
            ) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Une tâche ne peut pas être sa propre tâche parente.',
                ]);
            }

            $finalParent =
                $this->findScopedTask(
                    $request,
                    (int) $finalParentId
                );

            /*
             * Prevent direct parent cycle:
             * A -> B and B -> A.
             */
            if (
                $finalParent->parent_id !== null
                && (int) $finalParent->parent_id
                === (int) $task->id
            ) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Cette relation créerait une boucle entre les tâches.',
                ]);
            }
        }

        /*
         * Project / Action consistency.
         */
        if (
            $finalProject !== null
            && $finalAction !== null
            && $finalAction->project_id !== null
            && (int) $finalAction->project_id
            !== (int) $finalProject->id
        ) {
            throw ValidationException::withMessages([
                'action_id' => 'L’action sélectionnée n’appartient pas au projet sélectionné.',
            ]);
        }

        /*
         * Parent / Project consistency.
         */
        if (
            $finalParent !== null
            && $finalProject !== null
            && $finalParent->project_id !== null
            && (int) $finalParent->project_id
            !== (int) $finalProject->id
        ) {
            throw ValidationException::withMessages([
                'parent_id' => 'La tâche parente n’appartient pas au projet sélectionné.',
            ]);
        }

        /*
         * Validate final dates.
         */
        $finalStartAt =
            array_key_exists('start_at', $data)
            ? $data['start_at']
            : $task->start_at;

        $finalDueAt =
            array_key_exists('due_at', $data)
            ? $data['due_at']
            : $task->due_at;

        if (
            $finalStartAt !== null
            && $finalDueAt !== null
            && strtotime((string) $finalDueAt)
            < strtotime((string) $finalStartAt)
        ) {
            throw ValidationException::withMessages([
                'due_at' => 'La date d’échéance doit être postérieure ou égale à la date de début.',
            ]);
        }

        /*
         * Resolve final status.
         */
        $status = array_key_exists(
            'status_id',
            $data
        )
            ? $this->findScopedStatus(
                $request,
                (int) $data['status_id']
            )
            : $task->status;

        if (! $status) {
            throw ValidationException::withMessages([
                'status_id' => 'Le statut actuel de cette tâche est invalide.',
            ]);
        }

        $assigneeIds = null;

        if (array_key_exists('assignee_ids', $data)) {
            $assigneeIds = collect($data['assignee_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $context = $this->getContext($request);
            $accessibleAssigneeIds = $context['department']->users()
                ->wherePivot('is_active', true)
                ->whereKey($assigneeIds)
                ->pluck('users.id');

            if ($accessibleAssigneeIds->count() !== $assigneeIds->count()) {
                throw ValidationException::withMessages([
                    'assignee_ids' => 'Un ou plusieurs responsables ne sont pas membres actifs du service actuel.',
                ]);
            }

            unset($data['assignee_ids']);
        }

        /*
         * Normalize mutable relation IDs.
         */
        if (array_key_exists('project_id', $data)) {
            $data['project_id'] =
                $finalProject?->id;
        }

        if (array_key_exists('action_id', $data)) {
            $data['action_id'] =
                $finalAction?->id;
        }

        if (array_key_exists('parent_id', $data)) {
            $data['parent_id'] =
                $finalParent?->id;
        }

        if (array_key_exists('title', $data)) {
            $data['title'] = trim(
                $data['title']
            );
        }

        /*
         * Synchronize completion state with status.
         */
        if ($status->is_closed) {
            $data['progress'] = 100;

            if ($task->completed_at === null) {
                $data['completed_at'] = now();
            }
        } else {
            /*
             * Reopening a task clears completion date.
             */
            if (
                $task->status?->is_closed
                || $task->completed_at !== null
            ) {
                $data['completed_at'] = null;
            }
        }

        DB::transaction(
            function () use (
                $task,
                $data,
                $assigneeIds,
                $request
            ) {
                $task->update($data);

                $this->taskPlanningSynchronizer->sync($task);

                if ($assigneeIds !== null) {
                    $task->assignees()->syncWithPivotValues(
                        $assigneeIds->all(),
                        [
                            'assigned_by' => $request->user()->id,
                            'assigned_at' => now(),
                        ]
                    );
                }
            }
        );

        return back()->with(
            'success',
            'Tâche mise à jour avec succès.'
        );
    }

    /**
     * Update only the task status from the task board or list.
     */
    public function updateStatus(
        Request $request,
        string $id
    ): RedirectResponse {
        $task = $this->findScopedTask(
            $request,
            (int) $id
        );

        Gate::authorize('updateStatus', $task);

        $validated = $request->validate([
            'status_id' => [
                'required',
                'integer',
                'exists:task_statuses,id',
            ],
        ]);

        $status = $this->findScopedStatus(
            $request,
            (int) $validated['status_id']
        );

        $attributes = [
            'status_id' => $status->id,
        ];

        if ($status->is_closed) {
            $attributes['progress'] = 100;
            $attributes['completed_at'] = $task->completed_at ?? now();
        } elseif ($task->status?->is_closed || $task->completed_at !== null) {
            $attributes['completed_at'] = null;
        }

        $task->update($attributes);

        return back()->with(
            'success',
            'Statut de la tâche mis à jour avec succès.'
        );
    }

    /**
     * Delete a task.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $task = $this->findScopedTask(
            $request,
            (int) $id
        );

        Gate::authorize('delete', $task);

        $dependencies = [];

        if ($task->subtasks()->exists()) {
            $dependencies[] = 'des sous-tâches';
        }

        if ($task->taskAssignees()->exists()) {
            $dependencies[] = 'des affectations';
        }

        if ($task->checklistItems()->exists()) {
            $dependencies[] = 'une checklist';
        }

        if ($task->comments()->exists()) {
            $dependencies[] = 'des commentaires';
        }

        if ($task->attachments()->exists()) {
            $dependencies[] = 'des pièces jointes';
        }

        if ($task->timeSlots()->exists()) {
            $dependencies[] = 'des créneaux de travail';
        }

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'task' => 'Cette tâche contient encore '
                    .implode(', ', $dependencies)
                    .'. Supprimez ou réaffectez ces éléments avant de supprimer la tâche.',
            ]);
        }

        DB::transaction(function () use ($task) {
            $task->delete();
        });

        return back()->with(
            'success',
            'Tâche supprimée avec succès.'
        );
    }
}
