<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\SessionMember;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SessionMemberController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve the current organization from session.
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
     * Resolve the current department from session.
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
     *
     * @return array{
     *     organization: Organization,
     *     department: Department
     * }
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
     * Find a WorkSession inside the current context.
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
     * Find a SessionMember inside the current context.
     */
    private function findScopedMembership(
        Request $request,
        int $membershipId
    ): SessionMember {
        $context = $this->getContext($request);

        return SessionMember::query()
            ->whereKey($membershipId)
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
                        ->where('organization_members.is_active', true);
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
                        ->where('department_members.is_active', true);
                }
            )

            ->firstOrFail();
    }

    /**
     * Display members of a WorkSession.
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

            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'role' => [
                'nullable',
                'string',
                'max:100',
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

        $workSession =
            $this->findScopedWorkSession(
                $request,
                (int) $validated['work_session_id']
            );

        Gate::authorize('view', $workSession);

        $members = SessionMember::query()
            ->where(
                'work_session_id',
                $workSession->id
            )

            ->when(
                ! empty($validated['user_id']),
                fn ($query) => $query->where(
                    'user_id',
                    $validated['user_id']
                )
            )

            ->when(
                ! empty($validated['role']),
                fn ($query) => $query->where(
                    'role',
                    $validated['role']
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
                'workSession:id,organization_id,department_id,project_id,name,status',
            ])

            ->orderBy('role')
            ->latest('joined_at')
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

            'work_session' => [
                'id' => $workSession->id,

                'name' => $workSession->name,

                'status' => $workSession->status,
            ],

            'members' => $members,
        ]);
    }

    /**
     * Add a member to a WorkSession.
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

            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'role' => [
                'required',
                Rule::in(['member', 'viewer']),
            ],

            'joined_at' => [
                'nullable',
                'date',
            ],
        ], [
            'work_session_id.required' => 'La session de travail est obligatoire.',

            'work_session_id.exists' => 'La session de travail sélectionnée est introuvable.',

            'user_id.required' => 'L’utilisateur est obligatoire.',

            'user_id.exists' => 'L’utilisateur sélectionné est introuvable.',

            'role.required' => 'Le rôle du membre dans la session est obligatoire.',

            'role.max' => 'Le rôle ne peut pas dépasser 100 caractères.',

            'joined_at.date' => 'La date d’intégration est invalide.',
        ]);

        $workSession =
            $this->findScopedWorkSession(
                $request,
                (int) $data['work_session_id']
            );

        Gate::authorize('update', $workSession);

        $user = $this->findScopedUser(
            $request,
            (int) $data['user_id']
        );

        $role = trim($data['role']);

        if ($role === '') {
            throw ValidationException::withMessages([
                'role' => 'Le rôle du membre ne peut pas être vide.',
            ]);
        }

        /*
         * A user can belong only once
         * to the same WorkSession.
         */
        $alreadyExists =
            SessionMember::query()
                ->where(
                    'work_session_id',
                    $workSession->id
                )
                ->where(
                    'user_id',
                    $user->id
                )
                ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur participe déjà à cette session de travail.',
            ]);
        }

        DB::transaction(
            function () use (
                $workSession,
                $user,
                $role,
                $data
            ) {
                SessionMember::create([
                    'work_session_id' => $workSession->id,

                    'user_id' => $user->id,

                    'role' => $role,

                    'joined_at' => $data['joined_at']
                        ?? now(),
                ]);
            }
        );

        return back()->with(
            'success',
            'Membre ajouté à la session de travail avec succès.'
        );
    }

    /**
     * Display one SessionMember.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $membership =
            $this->findScopedMembership(
                $request,
                (int) $id
            );

        Gate::authorize('view', $membership->workSession);

        $membership->load([
            'user:id,name,email',

            'workSession:id,organization_id,department_id,project_id,created_by,name,status,start_date,end_date',
        ]);

        return response()->json([
            'membership' => $membership,
        ]);
    }

    /**
     * Update a session membership.
     *
     * work_session_id and user_id are immutable.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership =
            $this->findScopedMembership(
                $request,
                (int) $id
            );

        Gate::authorize('update', $membership->workSession);

        $data = $request->validate([
            'role' => [
                'sometimes',
                'required',
                Rule::in(['member', 'viewer']),
            ],

            'joined_at' => [
                'sometimes',
                'nullable',
                'date',
            ],
        ], [
            'role.required' => 'Le rôle du membre dans la session est obligatoire.',

            'role.max' => 'Le rôle ne peut pas dépasser 100 caractères.',

            'joined_at.date' => 'La date d’intégration est invalide.',
        ]);

        if (array_key_exists('role', $data)) {
            $data['role'] = trim(
                $data['role']
            );

            if ($data['role'] === '') {
                throw ValidationException::withMessages([
                    'role' => 'Le rôle du membre ne peut pas être vide.',
                ]);
            }
        }

        DB::transaction(
            function () use (
                $membership,
                $data
            ) {
                $membership->update($data);
            }
        );

        return back()->with(
            'success',
            'Participation mise à jour avec succès.'
        );
    }

    /**
     * Remove a member from a WorkSession.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership =
            $this->findScopedMembership(
                $request,
                (int) $id
            );

        $workSession =
            $membership->workSession()
                ->firstOrFail();

        Gate::authorize('update', $workSession);

        /*
         * The creator is stored directly on WorkSession.
         *
         * We prevent removing the creator from the
         * participant list while they still own the session.
         */
        if (
            (int) $workSession->created_by
            === (int) $membership->user_id
        ) {
            throw ValidationException::withMessages([
                'member' => 'Le créateur de la session ne peut pas être retiré de la liste des participants.',
            ]);
        }

        if (
            $workSession->timeSlots()
                ->whereHas('members', fn ($query) => $query->whereKey($membership->user_id))
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'member' => 'Ce participant est encore affecté à un créneau. Modifiez le créneau avant de le retirer de la session.',
            ]);
        }

        DB::transaction(
            function () use ($membership) {
                $membership->delete();
            }
        );

        return back()->with(
            'success',
            'Membre retiré de la session de travail avec succès.'
        );
    }
}
