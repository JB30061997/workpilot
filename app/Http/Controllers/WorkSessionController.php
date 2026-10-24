<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkSessionController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve current organization.
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
     * Resolve current department.
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
     * Find WorkSession inside current context.
     */
    private function findScopedWorkSession(
        Request $request,
        int $workSessionId
    ): WorkSession {
        $context = $this->getContext($request);

        return WorkSession::query()
            ->whereKey($workSessionId)
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
     * Find Project inside current context.
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
     * Display WorkSessions.
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

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'visibility' => [
                'nullable',
                'string',
                'max:50',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
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

        $sessions = WorkSession::query()
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(
                'department_id',
                $context['department']->id
            )

            ->where(function ($query) use ($request) {
                $query->where('visibility', 'department')
                    ->orWhere('created_by', $request->user()->id)
                    ->orWhereHas('members', fn ($memberQuery) => $memberQuery->whereKey($request->user()->id));
            })

            ->when(
                ! empty($validated['project_id']),
                fn ($query) => $query->where(
                    'project_id',
                    $validated['project_id']
                )
            )

            ->when(
                ! empty($validated['status']),
                fn ($query) => $query->where(
                    'status',
                    $validated['status']
                )
            )

            ->when(
                ! empty($validated['visibility']),
                fn ($query) => $query->where(
                    'visibility',
                    $validated['visibility']
                )
            )

            ->when(
                array_key_exists(
                    'is_active',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_active',
                    $validated['is_active']
                )
            )

            ->when(
                ! empty($validated['from']),
                fn ($query) => $query->where(
                    'end_date',
                    '>=',
                    $validated['from']
                )
            )

            ->when(
                ! empty($validated['to']),
                fn ($query) => $query->where(
                    'start_date',
                    '<=',
                    $validated['to']
                )
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
                                    'name',
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
                'project:id,name,code',
                'creator:id,name,email',
                'members:id,name,email',
                'timeSlots' => fn ($query) => $query
                    ->with([
                        'task:id,project_id,title,priority',
                        'creator:id,name,email',
                        'members:id,name,email',
                    ])
                    ->orderBy('start_at')
                    ->orderBy('id'),
            ])

            ->withCount([
                'members',
                'timeSlots',
                'tasks',
            ])

            ->withAvg('tasks', 'progress')

            ->orderBy('start_date')
            ->orderBy('name')
            ->orderBy('id')

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

            'work_sessions' => $sessions,
            'projects' => Project::query()
                ->where('organization_id', $context['organization']->id)
                ->where('department_id', $context['department']->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'tasks' => Task::query()
                ->where('organization_id', $context['organization']->id)
                ->where('department_id', $context['department']->id)
                ->with('status:id,name,slug,color,is_closed')
                ->orderBy('title')
                ->get(['id', 'project_id', 'title', 'priority', 'status_id']),
            'task_statuses' => TaskStatus::query()
                ->where('organization_id', $context['organization']->id)
                ->where('is_active', true)
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
            'members' => User::query()
                ->whereHas('departments', fn ($query) => $query
                    ->where('departments.id', $context['department']->id)
                    ->where('department_members.is_active', true))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'filters' => [
                'search' => $validated['search'] ?? '',
                'project_id' => $validated['project_id'] ?? '',
                'status' => $validated['status'] ?? '',
                'visibility' => $validated['visibility'] ?? '',
            ],
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('WorkSessions/Index', $payload);
    }

    /**
     * Store a WorkSession.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $context = $this->getContext($request);

        $data = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'start_date' => [
                'required',
                'date',
            ],

            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],

            'visibility' => [
                'required',
                Rule::in(['private', 'department']),
            ],

            'status' => [
                'required',
                Rule::in(['draft', 'active', 'completed', 'archived']),
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'member_ids' => [
                'nullable',
                'array',
            ],

            'member_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
            ],
        ], [
            'name.required' => 'Le nom de la session est obligatoire.',

            'start_date.required' => 'La date de début est obligatoire.',

            'end_date.required' => 'La date de fin est obligatoire.',

            'end_date.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',

            'visibility.required' => 'La visibilité est obligatoire.',

            'status.required' => 'Le statut est obligatoire.',
        ]);

        $project = null;

        if (! empty($data['project_id'])) {
            $project = $this->findScopedProject(
                $request,
                (int) $data['project_id']
            );
        }

        $name = trim($data['name']);
        $visibility = trim($data['visibility']);
        $status = trim($data['status']);

        $memberIds = collect($data['member_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->push($request->user()->id)
            ->unique()
            ->values();

        $departmentMemberIds = $context['department']->users()
            ->wherePivot('is_active', true)
            ->whereKey($memberIds)
            ->pluck('users.id');

        if ($departmentMemberIds->count() !== $memberIds->count()) {
            throw ValidationException::withMessages([
                'member_ids' => 'Un ou plusieurs membres ne font pas partie du département de la session.',
            ]);
        }

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Le nom de la session ne peut pas être vide.',
            ]);
        }

        if ($visibility === '') {
            throw ValidationException::withMessages([
                'visibility' => 'La visibilité ne peut pas être vide.',
            ]);
        }

        if ($status === '') {
            throw ValidationException::withMessages([
                'status' => 'Le statut ne peut pas être vide.',
            ]);
        }

        DB::transaction(
            function () use (
                $request,
                $context,
                $data,
                $project,
                $name,
                $visibility,
                $status,
                $memberIds
            ) {
                $workSession = WorkSession::create([
                    /*
                     * Context is controlled by backend.
                     */
                    'organization_id' => $context['organization']->id,

                    'department_id' => $context['department']->id,

                    'project_id' => $project?->id,

                    /*
                     * Never trust created_by from frontend.
                     */
                    'created_by' => $request->user()->id,

                    'name' => $name,

                    'description' => $data['description'] ?? null,

                    'start_date' => $data['start_date'],

                    'end_date' => $data['end_date'],

                    'visibility' => $visibility,

                    'status' => $status,

                    'is_active' => (bool) (
                        $data['is_active']
                        ?? true
                    ),
                ]);

                $workSession->members()->attach(
                    $memberIds->mapWithKeys(fn (int $userId): array => [
                        $userId => [
                            'role' => $userId === (int) $request->user()->id ? 'owner' : 'member',
                            'joined_at' => now(),
                        ],
                    ])->all()
                );
            }
        );

        return back()->with(
            'success',
            'Session de travail créée avec succès.'
        );
    }

    /**
     * Display one WorkSession.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse|Response {
        $session = $this->findScopedWorkSession(
            $request,
            (int) $id
        );

        Gate::authorize('view', $session);

        $session->load([
            'organization:id,name',

            'department:id,name,code',

            'project:id,name,code',

            'creator:id,name,email',

            'members:id,name,email',

            'timeSlots' => function ($query) {
                $query
                    ->with([
                        'task:id,title,priority,status_id',
                        'creator:id,name,email',
                        'members:id,name,email',
                    ])
                    ->orderBy('start_at');
            },

            'tasks' => function ($query) {
                $query
                    ->with([
                        'status:id,name,slug,color,is_closed',
                        'assignees:id,name,email',
                    ])
                    ->orderBy('position')
                    ->orderBy('id');
            },
        ]);

        $session->loadCount([
            'members',
            'timeSlots',
            'tasks',
        ]);

        $payload = [
            'work_session' => $session,
            'members' => $session->department->users()
                ->wherePivot('is_active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email']),
            'task_statuses' => TaskStatus::query()
                ->where('organization_id', $session->organization_id)
                ->where('is_active', true)
                ->orderBy('position')
                ->get(['id', 'name', 'slug', 'color', 'is_default', 'is_closed']),
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('WorkSessions/Show', $payload);
    }

    /**
     * Update WorkSession.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $session = $this->findScopedWorkSession(
            $request,
            (int) $id
        );

        Gate::authorize('update', $session);

        $data = $request->validate([
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:projects,id',
            ],

            'name' => [
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

            'start_date' => [
                'sometimes',
                'required',
                'date',
            ],

            'end_date' => [
                'sometimes',
                'required',
                'date',
            ],

            'visibility' => [
                'sometimes',
                'required',
                Rule::in(['private', 'department']),
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['draft', 'active', 'completed', 'archived']),
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        /*
         * Resolve final project.
         */
        if (array_key_exists('project_id', $data)) {
            if ($data['project_id'] === null) {
                $data['project_id'] = null;
            } else {
                $project = $this->findScopedProject(
                    $request,
                    (int) $data['project_id']
                );

                $data['project_id'] =
                    $project->id;
            }
        }

        /*
         * Validate final date interval.
         */
        $finalStartDate =
            array_key_exists(
                'start_date',
                $data
            )
            ? $data['start_date']
            : $session->start_date;

        $finalEndDate =
            array_key_exists(
                'end_date',
                $data
            )
            ? $data['end_date']
            : $session->end_date;

        if (
            strtotime((string) $finalEndDate)
            < strtotime((string) $finalStartDate)
        ) {
            throw ValidationException::withMessages([
                'end_date' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]);
        }

        /*
         * Existing TimeSlots must remain inside
         * the new WorkSession period.
         */
        if ($session->timeSlots()->exists()) {
            $firstSlot = $session->timeSlots()
                ->orderBy('start_at')
                ->first();

            $lastSlot = $session->timeSlots()
                ->orderByDesc('end_at')
                ->first();

            if (
                $firstSlot !== null
                && strtotime(
                    $firstSlot->start_at->toDateString()
                )
                < strtotime(
                    (string) $finalStartDate
                )
            ) {
                throw ValidationException::withMessages([
                    'start_date' => 'La nouvelle date de début exclurait un créneau déjà planifié.',
                ]);
            }

            if (
                $lastSlot !== null
                && strtotime(
                    $lastSlot->end_at->toDateString()
                )
                > strtotime(
                    (string) $finalEndDate
                )
            ) {
                throw ValidationException::withMessages([
                    'end_date' => 'La nouvelle date de fin exclurait un créneau déjà planifié.',
                ]);
            }
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim(
                $data['name']
            );

            if ($data['name'] === '') {
                throw ValidationException::withMessages([
                    'name' => 'Le nom de la session ne peut pas être vide.',
                ]);
            }
        }

        if (
            array_key_exists(
                'visibility',
                $data
            )
        ) {
            $data['visibility'] = trim(
                $data['visibility']
            );

            if ($data['visibility'] === '') {
                throw ValidationException::withMessages([
                    'visibility' => 'La visibilité ne peut pas être vide.',
                ]);
            }
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = trim(
                $data['status']
            );

            if ($data['status'] === '') {
                throw ValidationException::withMessages([
                    'status' => 'Le statut ne peut pas être vide.',
                ]);
            }
        }

        /*
         * organization_id, department_id and created_by
         * are intentionally immutable here.
         */
        DB::transaction(
            function () use (
                $session,
                $data
            ) {
                $session->update($data);
            }
        );

        return back()->with(
            'success',
            'Session de travail mise à jour avec succès.'
        );
    }

    /**
     * Delete WorkSession.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $session = $this->findScopedWorkSession(
            $request,
            (int) $id
        );

        Gate::authorize('delete', $session);

        $dependencies = [];

        if ($session->sessionMembers()->exists()) {
            $dependencies[] = 'des participants';
        }

        if ($session->timeSlots()->exists()) {
            $dependencies[] = 'des créneaux de travail';
        }

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'work_session' => 'Cette session contient encore '
                    .implode(', ', $dependencies)
                    .'. Retirez ces éléments avant de supprimer la session.',
            ]);
        }

        DB::transaction(
            function () use ($session) {
                $session->delete();
            }
        );

        return back()->with(
            'success',
            'Session de travail supprimée avec succès.'
        );
    }
}
