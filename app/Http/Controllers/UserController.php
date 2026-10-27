<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\WorkContextDepartmentService;
use App\Support\CurrentDepartmentAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer,
        private readonly WorkContextDepartmentService $departmentAccess,
    ) {}

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
        $departments = $this->departmentAccess
            ->accessibleDepartments($request->user(), $organization->id);

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
     * Find an active user inside current organization/service.
     */
    private function findScopedUser(
        Request $request,
        int $userId
    ): User {
        $context = $this->getContext($request);

        $query = User::query()
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
            );

        if (! $this->departmentAccess->isDirectorGeneral($request->user(), $context['organization']->id)) {
            $query->whereHas(
                'departments',
                fn ($departmentQuery) => $departmentQuery
                    ->where('departments.id', $context['department']->id)
                    ->where('department_members.is_active', true)
            );
        }

        return $query->firstOrFail();
    }

    /**
     * Display users of current organization/service.
     */
    public function index(
        Request $request
    ): JsonResponse|Response {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'role_id' => [
                'nullable',
                'integer',
                'exists:roles,id',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        $isDirectorGeneral = $this->departmentAccess
            ->isDirectorGeneral($request->user(), $context['organization']->id);
        $users = User::query()

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

            ->when(! $isDirectorGeneral, fn ($query) => $query->whereHas(
                'departments',
                fn ($departmentQuery) => $departmentQuery
                    ->where('departments.id', $context['department']->id)
                    ->where('department_members.is_active', true)
            ))

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
                                    'email',
                                    'like',
                                    '%'.$search.'%'
                                );
                        }
                    );
                }
            )

            ->when(
                ! empty($validated['role_id']),
                function ($query) use (
                    $validated,
                    $context
                ) {
                    $query->whereHas(
                        'roles',
                        function ($roleQuery) use (
                            $validated,
                            $context
                        ) {
                            $roleQuery
                                ->where(
                                    'roles.id',
                                    $validated['role_id']
                                )
                                ->where(
                                    'user_roles.organization_id',
                                    $context['organization']->id
                                );
                        }
                    );
                }
            )

            ->with([
                'organizations' => function ($query) use ($context) {
                    $query->where(
                        'organizations.id',
                        $context['organization']->id
                    );
                },

                'departments' => function ($query) use ($context) {
                    $query->where('departments.organization_id', $context['organization']->id)
                        ->where('department_members.is_active', true)
                        ->orderByDesc('department_members.is_primary')
                        ->orderBy('departments.name');
                },

                'roles' => function ($query) use ($context) {
                    $query->where(
                        'user_roles.organization_id',
                        $context['organization']->id
                    );
                },
            ])

            ->withCount([
                'assignedTasks',
                'projects',
                'workSessions',
                'timeSlots',
            ])

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

            'users' => $users,
            'departments' => Department::query()
                ->where('organization_id', $context['organization']->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'roles' => $context['organization']->roles()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['roles.id', 'roles.name']),
            'filters' => [
                'search' => $validated['search'] ?? '',
                'role_id' => $validated['role_id'] ?? '',
            ],
            'canManageUsers' => $this->departmentAuthorizer->canManageAccess(
                $request->user(),
                $context['organization']->id,
                $context['department']->id,
            ),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Users/Index', $payload);
    }

    /**
     * Store a user and assign their organization, department, and role.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $context = $this->getContext($request);
        $this->departmentAuthorizer->authorizeAccessManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::defaults(),
            ],

            'department_id' => [
                'required_without:department_ids',
                'integer',
                Rule::exists('departments', 'id')->where('organization_id', $context['organization']->id),
            ],
            'department_ids' => ['required_without:department_id', 'array', 'min:1'],
            'department_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('departments', 'id')->where('organization_id', $context['organization']->id),
            ],

            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('roles', 'id')->where('organization_id', $context['organization']->id),
            ],

            'job_title' => [
                'nullable',
                'string',
                'max:255',
            ],
        ], [
            'name.required' => 'Le nom de l’utilisateur est obligatoire.',

            'email.required' => 'L’adresse e-mail est obligatoire.',

            'email.email' => 'L’adresse e-mail est invalide.',

            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',

            'password.required' => 'Le mot de passe est obligatoire.',

            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $name = trim($data['name']);
        $email = mb_strtolower(
            trim($data['email'])
        );

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Le nom de l’utilisateur ne peut pas être vide.',
            ]);
        }

        DB::transaction(
            function () use (
                $context,
                $name,
                $email,
                $data
            ) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(
                        $data['password']
                    ),
                ]);

                $user->organizations()->attach($context['organization']->id, [
                    'job_title' => $data['job_title'] ?? null,
                    'status' => 'active',
                    'joined_at' => now(),
                    'is_owner' => false,
                    'is_active' => true,
                ]);

                $departmentIds = collect($data['department_ids'] ?? [$data['department_id']])
                    ->map(fn ($departmentId): int => (int) $departmentId)
                    ->unique()
                    ->values();
                $user->departments()->attach($departmentIds->mapWithKeys(fn (int $departmentId, int $index): array => [
                    $departmentId => [
                        'is_manager' => in_array(
                            $context['organization']->roles()->find($data['role_id'] ?? null)?->slug,
                            ['director', 'deputy-director', 'department-manager'],
                            true,
                        ),
                        'is_primary' => $index === 0,
                        'is_active' => true,
                        'joined_at' => now(),
                    ],
                ])->all());

                if (! empty($data['role_id'])) {
                    $user->roles()->attach($data['role_id'], [
                        'organization_id' => $context['organization']->id,
                    ]);
                }
            }
        );

        return back()->with(
            'success',
            'Utilisateur créé avec succès.'
        );
    }

    /**
     * Display one user.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $context = $this->getContext($request);

        $user = $this->findScopedUser(
            $request,
            (int) $id
        );

        $user->load([
            'organizations' => function ($query) use ($context) {
                $query->where(
                    'organizations.id',
                    $context['organization']->id
                );
            },

            'departments' => function ($query) use ($context) {
                $query->where(
                    'departments.organization_id',
                    $context['organization']->id
                );
            },

            'roles' => function ($query) use ($context) {
                $query->where(
                    'user_roles.organization_id',
                    $context['organization']->id
                );
            },

            'projects' => function ($query) use ($context) {
                $query
                    ->where(
                        'projects.organization_id',
                        $context['organization']->id
                    )
                    ->where(
                        'projects.department_id',
                        $context['department']->id
                    );
            },

            'assignedTasks' => function ($query) use ($context) {
                $query
                    ->where(
                        'tasks.organization_id',
                        $context['organization']->id
                    )
                    ->where(
                        'tasks.department_id',
                        $context['department']->id
                    )
                    ->with('status:id,name,slug,color,is_closed');
            },

            'workSessions' => function ($query) use ($context) {
                $query
                    ->where(
                        'work_sessions.organization_id',
                        $context['organization']->id
                    )
                    ->where(
                        'work_sessions.department_id',
                        $context['department']->id
                    );
            },

            'timeSlots' => function ($query) use ($context) {
                $query->whereHas(
                    'workSession',
                    function ($sessionQuery) use ($context) {
                        $sessionQuery
                            ->where(
                                'organization_id',
                                $context['organization']->id
                            )
                            ->where(
                                'department_id',
                                $context['department']->id
                            );
                    }
                );
            },
        ]);

        $user->loadCount([
            'createdTasks',
            'assignedTasks',
            'projects',
            'workSessions',
            'timeSlots',
        ]);

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Update user identity and access assignments.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $context = $this->getContext($request);
        $this->departmentAuthorizer->authorizeAccessManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );
        $user = $this->findScopedUser(
            $request,
            (int) $id
        );

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',

                Rule::unique(
                    'users',
                    'email'
                )->ignore($user->id),
            ],

            'password' => [
                'sometimes',
                'required',
                'confirmed',
                Password::defaults(),
            ],

            'department_id' => [
                'sometimes',
                'required_without:department_ids',
                'integer',
                Rule::exists('departments', 'id')->where('organization_id', $context['organization']->id),
            ],
            'department_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'department_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('departments', 'id')->where('organization_id', $context['organization']->id),
            ],

            'role_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('roles', 'id')->where('organization_id', $context['organization']->id),
            ],

            'job_title' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim(
                $data['name']
            );

            if ($data['name'] === '') {
                throw ValidationException::withMessages([
                    'name' => 'Le nom de l’utilisateur ne peut pas être vide.',
                ]);
            }
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = mb_strtolower(
                trim($data['email'])
            );

            if (
                $data['email']
                !== $user->email
            ) {
                $data['email_verified_at'] = null;
            }
        }

        if (
            array_key_exists(
                'password',
                $data
            )
        ) {
            $data['password'] = Hash::make(
                $data['password']
            );
        }

        DB::transaction(
            function () use ($context, $user, $data) {
                $identity = collect($data)->only(['name', 'email', 'password', 'email_verified_at'])->all();
                $user->update($identity);

                if (array_key_exists('job_title', $data)) {
                    $user->organizations()->updateExistingPivot($context['organization']->id, [
                        'job_title' => $data['job_title'],
                    ]);
                }

                if (array_key_exists('department_id', $data) || array_key_exists('department_ids', $data)) {
                    $selectedDepartmentIds = collect($data['department_ids'] ?? [$data['department_id']])
                        ->map(fn ($departmentId): int => (int) $departmentId)
                        ->unique()
                        ->values();
                    $organizationDepartmentIds = Department::query()
                        ->where('organization_id', $context['organization']->id)
                        ->pluck('id');
                    $user->departments()->detach($organizationDepartmentIds);
                    $roleSlug = array_key_exists('role_id', $data)
                        ? $context['organization']->roles()->find($data['role_id'])?->slug
                        : $user->roles()->where('user_roles.organization_id', $context['organization']->id)->value('slug');
                    $user->departments()->attach($selectedDepartmentIds->mapWithKeys(fn (int $departmentId, int $index): array => [
                        $departmentId => [
                            'is_manager' => in_array($roleSlug, ['director', 'deputy-director', 'department-manager'], true),
                            'is_primary' => $index === 0,
                            'is_active' => true,
                            'joined_at' => now(),
                        ],
                    ])->all());
                }

                if (array_key_exists('role_id', $data)) {
                    $organizationRoleIds = $context['organization']->roles()->pluck('roles.id');
                    $user->roles()->detach($organizationRoleIds);

                    if (! empty($data['role_id'])) {
                        $user->roles()->attach($data['role_id'], [
                            'organization_id' => $context['organization']->id,
                        ]);
                    }
                }
            }
        );

        return back()->with(
            'success',
            'Utilisateur mis à jour avec succès.'
        );
    }

    /**
     * Delete a user only when no business data depends on it.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $context = $this->getContext($request);
        $this->departmentAuthorizer->authorizeAccessManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );
        $user = $this->findScopedUser(
            $request,
            (int) $id
        );

        /*
         * Never allow the authenticated user
         * to delete their own account here.
         */
        if (
            (int) $user->id
            === (int) $request->user()->id
        ) {
            throw ValidationException::withMessages([
                'user' => 'Vous ne pouvez pas supprimer votre propre compte depuis la gestion des utilisateurs.',
            ]);
        }

        $dependencies = [];

        if ($user->createdActionPlans()->exists()) {
            $dependencies[] = 'des plans d’action';
        }

        if ($user->createdProjects()->exists()) {
            $dependencies[] = 'des projets créés';
        }

        if ($user->managedProjects()->exists()) {
            $dependencies[] = 'des projets gérés';
        }

        if ($user->createdActions()->exists()) {
            $dependencies[] = 'des actions créées';
        }

        if ($user->ownedActions()->exists()) {
            $dependencies[] = 'des actions sous sa responsabilité';
        }

        if ($user->createdTasks()->exists()) {
            $dependencies[] = 'des tâches créées';
        }

        if ($user->assignedTasks()->exists()) {
            $dependencies[] = 'des tâches affectées';
        }

        if ($user->comments()->exists()) {
            $dependencies[] = 'des commentaires';
        }

        if ($user->createdWorkSessions()->exists()) {
            $dependencies[] = 'des sessions de travail créées';
        }

        if ($user->workSessions()->exists()) {
            $dependencies[] = 'des participations aux sessions';
        }

        if ($user->createdTimeSlots()->exists()) {
            $dependencies[] = 'des créneaux créés';
        }

        if ($user->timeSlots()->exists()) {
            $dependencies[] = 'des affectations au planning';
        }

        if ($user->activities()->exists()) {
            $dependencies[] = 'un historique d’activités';
        }

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'user' => 'Cet utilisateur possède encore '
                    .implode(', ', $dependencies)
                    .'. Désactivez ou réaffectez ces éléments avant de supprimer le compte.',
            ]);
        }

        DB::transaction(
            function () use ($user) {
                /*
                 * Remove pure access relations first.
                 */
                $user->roles()->detach();
                $user->departments()->detach();
                $user->organizations()->detach();

                $user->delete();
            }
        );

        return back()->with(
            'success',
            'Utilisateur supprimé avec succès.'
        );
    }
}
