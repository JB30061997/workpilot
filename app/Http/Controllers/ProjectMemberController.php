<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectMemberController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve the active organization.
     */
    private function getCurrentOrganization(Request $request): Organization
    {
        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->when(
                session('current_organization_id'),
                fn ($query, $organizationId) => $query->whereKey($organizationId)
            )
            ->first();

        if (! $organization && session()->has('current_organization_id')) {
            session()->forget([
                'current_organization_id',
                'current_department_id',
            ]);

            return $this->getCurrentOrganization($request);
        }

        abort_if(
            ! $organization,
            403,
            'Aucune société active n’est associée à votre compte.'
        );

        return $organization;
    }

    /**
     * Resolve the active department/service.
     */
    private function getCurrentDepartment(
        Request $request,
        Organization $organization
    ): Department {
        $department = $request->user()
            ->departments()
            ->where(
                'departments.organization_id',
                $organization->id
            )
            ->wherePivot('is_active', true)
            ->when(
                session('current_department_id'),
                fn ($query, $departmentId) => $query->whereKey($departmentId)
            )
            ->orderByDesc('department_members.is_primary')
            ->first();

        if (! $department && session()->has('current_department_id')) {
            session()->forget('current_department_id');

            return $this->getCurrentDepartment($request, $organization);
        }

        abort_if(
            ! $department,
            403,
            'Aucun service actif n’est associé à votre compte dans cette société.'
        );

        return $department;
    }

    /**
     * Resolve current WorkPilot context.
     *
     * @return array{
     *     organization: Organization,
     *     department: Department
     * }
     */
    private function getContext(Request $request): array
    {
        $organization = $this->getCurrentOrganization($request);

        $department = $this->getCurrentDepartment(
            $request,
            $organization
        );

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    /**
     * Find a project inside the current organization/service.
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
     * Find a project membership inside the current context.
     */
    private function findScopedMembership(
        Request $request,
        int $membershipId
    ): ProjectMember {
        $context = $this->getContext($request);

        return ProjectMember::query()
            ->whereKey($membershipId)
            ->whereHas(
                'project',
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
                        ->wherePivot('is_active', true);
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
                        ->wherePivot('is_active', true);
                }
            )

            ->firstOrFail();
    }

    /**
     * Display project members.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'project_id' => [
                'required',
                'integer',
                'exists:projects,id',
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

        $project = $this->findScopedProject(
            $request,
            (int) $validated['project_id']
        );

        $members = ProjectMember::query()
            ->where(
                'project_id',
                $project->id
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

                'project:id,organization_id,department_id,name,code',
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

            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
            ],

            'members' => $members,
        ]);
    }

    /**
     * Add a member to a project.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => [
                'required',
                'integer',
                'exists:projects,id',
            ],

            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'role' => [
                'required',
                'string',
                'max:100',
            ],

            'joined_at' => [
                'nullable',
                'date',
            ],
        ], [
            'project_id.required' => 'Le projet est obligatoire.',

            'project_id.exists' => 'Le projet sélectionné est introuvable.',

            'user_id.required' => 'L’utilisateur est obligatoire.',

            'user_id.exists' => 'L’utilisateur sélectionné est introuvable.',

            'role.required' => 'Le rôle du membre dans le projet est obligatoire.',

            'role.max' => 'Le rôle ne peut pas dépasser 100 caractères.',

            'joined_at.date' => 'La date d’intégration au projet est invalide.',
        ]);

        $project = $this->findScopedProject(
            $request,
            (int) $data['project_id']
        );

        $user = $this->findScopedUser(
            $request,
            (int) $data['user_id']
        );

        /*
         * A user may belong only once to the same project.
         */
        $alreadyExists = ProjectMember::query()
            ->where(
                'project_id',
                $project->id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur est déjà membre de ce projet.',
            ]);
        }

        DB::transaction(function () use (
            $project,
            $user,
            $data
        ) {
            ProjectMember::create([
                'project_id' => $project->id,

                'user_id' => $user->id,

                'role' => trim($data['role']),

                'joined_at' => $data['joined_at'] ?? now(),
            ]);
        });

        return back()->with(
            'success',
            'Membre ajouté au projet avec succès.'
        );
    }

    /**
     * Display one project membership.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $membership = $this->findScopedMembership(
            $request,
            (int) $id
        );

        $membership->load([
            'user:id,name,email',

            'project:id,organization_id,department_id,name,code,manager_id',
        ]);

        return response()->json([
            'membership' => $membership,
        ]);
    }

    /**
     * Update a project membership.
     *
     * project_id and user_id are intentionally immutable.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership = $this->findScopedMembership(
            $request,
            (int) $id
        );

        $data = $request->validate([
            'role' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'joined_at' => [
                'sometimes',
                'nullable',
                'date',
            ],
        ], [
            'role.required' => 'Le rôle du membre dans le projet est obligatoire.',

            'role.max' => 'Le rôle ne peut pas dépasser 100 caractères.',

            'joined_at.date' => 'La date d’intégration au projet est invalide.',
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

        DB::transaction(function () use (
            $membership,
            $data
        ) {
            $membership->update($data);
        });

        return back()->with(
            'success',
            'Membre du projet mis à jour avec succès.'
        );
    }

    /**
     * Remove a member from a project.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership = $this->findScopedMembership(
            $request,
            (int) $id
        );

        /*
         * Project manager is managed through Project.manager_id.
         *
         * If the member being removed is also the current
         * project manager, require manager reassignment first.
         */
        $project = $membership->project()
            ->firstOrFail();

        if (
            $project->manager_id !== null
            && (int) $project->manager_id
            === (int) $membership->user_id
        ) {
            throw ValidationException::withMessages([
                'member' => 'Cet utilisateur est actuellement responsable du projet. Affectez d’abord un autre responsable au projet avant de le retirer.',
            ]);
        }

        DB::transaction(function () use ($membership) {
            $membership->delete();
        });

        return back()->with(
            'success',
            'Membre retiré du projet avec succès.'
        );
    }
}
