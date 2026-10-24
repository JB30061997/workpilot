<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\SessionMember;
use App\Models\Task;
use App\Models\TimeSlot;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimeSlotController extends Controller
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
     * Find Task inside current context.
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
     * Find TimeSlot through its WorkSession context.
     */
    private function findScopedTimeSlot(
        Request $request,
        int $timeSlotId
    ): TimeSlot {
        $context = $this->getContext($request);

        return TimeSlot::query()
            ->whereKey($timeSlotId)
            ->whereHas(
                'workSession',
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
     * Ensure Task and WorkSession are coherent.
     */
    private function validateTaskSessionConsistency(
        ?Task $task,
        WorkSession $workSession
    ): void {
        if ($task === null) {
            return;
        }

        /*
         * If the WorkSession belongs to a project,
         * the task must belong to the same project.
         */
        if (
            $workSession->project_id !== null
            && (int) $workSession->project_id
            !== (int) $task->project_id
        ) {
            throw ValidationException::withMessages([
                'task_id' => 'La tâche sélectionnée n’appartient pas au projet de cette session de travail.',
            ]);
        }
    }

    /**
     * Ensure the real work period stays inside its WorkSession dates.
     */
    private function validatePeriod(string $startAt, string $endAt, WorkSession $workSession): void
    {
        if (
            strtotime($startAt) < $workSession->start_date->copy()->startOfDay()->timestamp
            || strtotime($endAt) > $workSession->end_date->copy()->endOfDay()->timestamp
        ) {
            throw ValidationException::withMessages([
                'start_at' => 'Le créneau doit rester dans la période de la session de travail.',
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $memberIds
     * @return array<int, int>
     */
    private function validateSessionMembers(array $memberIds, WorkSession $workSession): array
    {
        $uniqueMemberIds = collect($memberIds)->map(fn ($id) => (int) $id)->unique()->values();
        $validMemberIds = SessionMember::query()
            ->where('work_session_id', $workSession->id)
            ->whereIn('user_id', $uniqueMemberIds)
            ->pluck('user_id');

        if ($validMemberIds->count() !== $uniqueMemberIds->count()) {
            throw ValidationException::withMessages([
                'member_ids' => 'Chaque collaborateur affecté doit participer à cette session de travail.',
            ]);
        }

        return $uniqueMemberIds->all();
    }

    /**
     * Display time slots.
     */
    public function index(
        Request $request
    ): JsonResponse {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'work_session_id' => [
                'required',
                'integer',
                'exists:work_sessions,id',
            ],

            'task_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
            ],

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'is_locked' => [
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

        if (! empty($validated['work_session_id'])) {
            $this->findScopedWorkSession(
                $request,
                (int) $validated['work_session_id']
            );
        }

        if (! empty($validated['task_id'])) {
            $this->findScopedTask(
                $request,
                (int) $validated['task_id']
            );
        }

        $slots = TimeSlot::query()

            /*
             * TimeSlot has no organization_id/department_id.
             * Scope is inherited from WorkSession.
             */
            ->whereHas(
                'workSession',
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

            ->when(
                ! empty($validated['work_session_id']),
                fn ($query) => $query->where(
                    'work_session_id',
                    $validated['work_session_id']
                )
            )

            ->when(
                ! empty($validated['task_id']),
                fn ($query) => $query->where(
                    'task_id',
                    $validated['task_id']
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
                array_key_exists(
                    'is_locked',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_locked',
                    $validated['is_locked']
                )
            )

            ->when(
                ! empty($validated['from']),
                fn ($query) => $query->where(
                    'start_at',
                    '>=',
                    $validated['from']
                )
            )

            ->when(
                ! empty($validated['to']),
                fn ($query) => $query->where(
                    'end_at',
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
                'workSession:id,organization_id,department_id,project_id,name,status',
                'task:id,project_id,title,priority,status_id',
                'creator:id,name,email',
                'members:id,name,email',
            ])

            ->withCount('members')

            ->orderBy('start_at')
            ->orderBy('end_at')
            ->orderBy('id')

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

            'time_slots' => $slots,
        ]);
    }

    /**
     * Store a new TimeSlot.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $data = $request->validate([
            'work_session_id' => [
                'required',
                'integer',
                'exists:work_sessions,id',
            ],

            'task_id' => [
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

            'start_at' => [
                'required',
                'date',
            ],

            'end_at' => [
                'required',
                'date',
                'after:start_at',
            ],

            'status' => [
                'required',
                Rule::in(['planned', 'in_progress', 'completed', 'cancelled']),
            ],

            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],

            'is_locked' => [
                'nullable',
                'boolean',
            ],
        ], [
            'work_session_id.required' => 'La session de travail est obligatoire.',

            'task_id.required' => 'La tâche à planifier est obligatoire.',

            'member_ids.required' => 'Affectez au moins un collaborateur au créneau.',

            'title.required' => 'Le titre du créneau est obligatoire.',

            'start_at.required' => 'La date de début est obligatoire.',

            'end_at.required' => 'La date de fin est obligatoire.',

            'end_at.after' => 'La date de fin doit être postérieure à la date de début.',

            'status.required' => 'Le statut du créneau est obligatoire.',
        ]);

        $workSession =
            $this->findScopedWorkSession(
                $request,
                (int) $data['work_session_id']
            );

        $task = ! empty($data['task_id'])
            ? $this->findScopedTask($request, (int) $data['task_id'])
            : null;

        $this->validateTaskSessionConsistency(
            $task,
            $workSession
        );
        $this->validatePeriod($data['start_at'], $data['end_at'], $workSession);
        $memberIds = $this->validateSessionMembers($data['member_ids'], $workSession);

        $title = trim($data['title']);
        $status = trim($data['status']);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Le titre du créneau ne peut pas être vide.',
            ]);
        }

        if ($status === '') {
            throw ValidationException::withMessages([
                'status' => 'Le statut du créneau ne peut pas être vide.',
            ]);
        }

        DB::transaction(
            function () use (
                $request,
                $workSession,
                $task,
                $data,
                $title,
                $status,
                $memberIds
            ) {
                $timeSlot = TimeSlot::create([
                    'work_session_id' => $workSession->id,

                    'task_id' => $task?->id,

                    /*
                     * Backend controlled.
                     */
                    'created_by' => $request->user()->id,

                    'title' => $title,

                    'description' => $data['description'] ?? null,

                    'start_at' => $data['start_at'],

                    'end_at' => $data['end_at'],

                    'status' => $status,

                    'is_locked' => (bool) (
                        $data['is_locked']
                        ?? false
                    ),
                ]);

                $timeSlot->members()->sync($memberIds);
            }
        );

        return back()->with(
            'success',
            'Créneau de travail créé avec succès.'
        );
    }

    /**
     * Display one TimeSlot.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $slot = $this->findScopedTimeSlot(
            $request,
            (int) $id
        );

        $slot->load([
            'workSession:id,organization_id,department_id,project_id,created_by,name,status,start_date,end_date',

            'task:id,organization_id,department_id,project_id,action_id,status_id,title,priority,start_at,due_at,progress',

            'creator:id,name,email',

            'members:id,name,email',
        ]);

        $slot->loadCount('members');

        return response()->json([
            'time_slot' => $slot,
        ]);
    }

    /**
     * Update a TimeSlot.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $slot = $this->findScopedTimeSlot(
            $request,
            (int) $id
        );

        /*
         * Locked slots are immutable.
         */
        if ($slot->is_locked) {
            throw ValidationException::withMessages([
                'time_slot' => 'Ce créneau est verrouillé et ne peut pas être modifié.',
            ]);
        }

        $data = $request->validate([
            'work_session_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:work_sessions,id',
            ],

            'task_id' => [
                'sometimes',
                'required',
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

            'start_at' => [
                'sometimes',
                'required',
                'date',
            ],

            'end_at' => [
                'sometimes',
                'required',
                'date',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['planned', 'in_progress', 'completed', 'cancelled']),
            ],

            'member_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],

            'is_locked' => [
                'sometimes',
                'boolean',
            ],
        ]);

        /*
         * Resolve final WorkSession.
         */
        $finalWorkSessionId =
            array_key_exists(
                'work_session_id',
                $data
            )
            ? $data['work_session_id']
            : $slot->work_session_id;

        $workSession =
            $this->findScopedWorkSession(
                $request,
                (int) $finalWorkSessionId
            );

        /*
         * Resolve final Task.
         */
        $finalTaskId =
            array_key_exists('task_id', $data)
            ? $data['task_id']
            : $slot->task_id;

        if ($finalTaskId === null) {
            throw ValidationException::withMessages([
                'task_id' => 'La tâche à planifier est obligatoire.',
            ]);
        }

        $task = $this->findScopedTask($request, (int) $finalTaskId);

        $this->validateTaskSessionConsistency(
            $task,
            $workSession
        );

        /*
         * Validate final dates.
         */
        $finalStartAt =
            array_key_exists('start_at', $data)
            ? $data['start_at']
            : $slot->start_at;

        $finalEndAt =
            array_key_exists('end_at', $data)
            ? $data['end_at']
            : $slot->end_at;

        if (
            strtotime((string) $finalEndAt)
            <= strtotime((string) $finalStartAt)
        ) {
            throw ValidationException::withMessages([
                'end_at' => 'La date de fin doit être postérieure à la date de début.',
            ]);
        }

        $this->validatePeriod((string) $finalStartAt, (string) $finalEndAt, $workSession);
        $memberIds = array_key_exists('member_ids', $data)
            ? $this->validateSessionMembers($data['member_ids'], $workSession)
            : null;

        /*
         * Normalize relation IDs.
         */
        if (
            array_key_exists(
                'work_session_id',
                $data
            )
        ) {
            $data['work_session_id'] =
                $workSession->id;
        }

        if (
            array_key_exists(
                'task_id',
                $data
            )
        ) {
            $data['task_id'] =
                $task?->id;
        }

        if (array_key_exists('title', $data)) {
            $data['title'] = trim(
                $data['title']
            );

            if ($data['title'] === '') {
                throw ValidationException::withMessages([
                    'title' => 'Le titre du créneau ne peut pas être vide.',
                ]);
            }
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = trim(
                $data['status']
            );

            if ($data['status'] === '') {
                throw ValidationException::withMessages([
                    'status' => 'Le statut du créneau ne peut pas être vide.',
                ]);
            }
        }

        /*
         * created_by is intentionally immutable.
         */
        DB::transaction(
            function () use (
                $slot,
                $data,
                $memberIds
            ) {
                $slot->update(collect($data)->except('member_ids')->all());

                if ($memberIds !== null) {
                    $slot->members()->sync($memberIds);
                }
            }
        );

        return back()->with(
            'success',
            'Créneau de travail mis à jour avec succès.'
        );
    }

    /**
     * Delete a TimeSlot.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $slot = $this->findScopedTimeSlot(
            $request,
            (int) $id
        );

        /*
         * Locked slots cannot be deleted.
         */
        if ($slot->is_locked) {
            throw ValidationException::withMessages([
                'time_slot' => 'Ce créneau est verrouillé et ne peut pas être supprimé.',
            ]);
        }

        DB::transaction(
            function () use ($slot) {
                $slot->members()->detach();
                $slot->delete();
            }
        );

        return back()->with(
            'success',
            'Créneau de travail supprimé avec succès.'
        );
    }
}
