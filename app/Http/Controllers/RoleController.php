<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\CurrentDepartmentAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer
    ) {}

    private function authorizeManagement(Request $request, Organization $organization): void
    {
        $this->departmentAuthorizer->authorizeAccessManagement(
            $request->user(),
            $organization->id,
            (int) session('current_department_id'),
        );
    }

    /**
     * Resolve the current organization using the same
     * session context used by WorkPilot.
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
     * Find a role belonging to the current organization.
     */
    private function findScopedRole(
        Request $request,
        int $roleId
    ): Role {
        $organization =
            $this->getCurrentOrganization($request);

        return Role::query()
            ->whereKey($roleId)
            ->where(
                'organization_id',
                $organization->id
            )
            ->firstOrFail();
    }

    /**
     * Generate a unique slug inside the organization.
     */
    private function generateUniqueSlug(
        Organization $organization,
        string $value,
        ?int $ignoreRoleId = null
    ): string {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'role';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            Role::query()
                ->where(
                    'organization_id',
                    $organization->id
                )
                ->where(
                    'slug',
                    $slug
                )
                ->when(
                    $ignoreRoleId !== null,
                    fn ($query) => $query->whereKeyNot(
                        $ignoreRoleId
                    )
                )
                ->exists()
        ) {
            $slug =
                $baseSlug.'-'.$counter;

            $counter++;
        }

        return $slug;
    }

    /**
     * Validate permission IDs.
     *
     * Permissions are global in the current architecture,
     * therefore they are not filtered by organization.
     *
     * @return array<int>
     */
    private function validatePermissions(
        array $permissionIds
    ): array {
        $permissionIds = collect(
            $permissionIds
        )
            ->map(
                fn ($id) => (int) $id
            )
            ->unique()
            ->values();

        if ($permissionIds->isEmpty()) {
            return [];
        }

        $existingIds = Permission::query()
            ->whereIn(
                'id',
                $permissionIds
            )
            ->where(
                'is_active',
                true
            )
            ->pluck('id');

        $missingIds = $permissionIds
            ->diff($existingIds);

        if ($missingIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission_ids' => 'Une ou plusieurs permissions sélectionnées sont invalides ou inactives.',
            ]);
        }

        return $permissionIds->all();
    }

    /**
     * Display roles of the current organization.
     */
    public function index(
        Request $request
    ): JsonResponse {
        $organization =
            $this->getCurrentOrganization($request);

        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'is_system' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        $roles = Role::query()
            ->where(
                'organization_id',
                $organization->id
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
                                    'slug',
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

            ->when(
                array_key_exists(
                    'is_system',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_system',
                    $validated['is_system']
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

            ->withCount([
                'permissions',
                'users',
            ])

            ->orderByDesc('is_system')
            ->orderBy('name')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'roles' => $roles,
        ]);
    }

    /**
     * Store a new role.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $organization =
            $this->getCurrentOrganization($request);
        $this->authorizeManagement($request, $organization);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'permission_ids' => [
                'nullable',
                'array',
            ],

            'permission_ids.*' => [
                'integer',
                'exists:permissions,id',
            ],
        ], [
            'name.required' => 'Le nom du rôle est obligatoire.',

            'permission_ids.array' => 'La liste des permissions est invalide.',

            'permission_ids.*.exists' => 'Une permission sélectionnée est introuvable.',
        ]);

        $name = trim($data['name']);

        $slug = $this->generateUniqueSlug(
            $organization,
            ! empty($data['slug'])
                ? $data['slug']
                : $name
        );

        $permissionIds =
            $this->validatePermissions(
                $data['permission_ids'] ?? []
            );

        DB::transaction(
            function () use (
                $organization,
                $data,
                $name,
                $slug,
                $permissionIds
            ) {
                $role = Role::create([
                    'organization_id' => $organization->id,

                    'name' => $name,

                    'slug' => $slug,

                    'description' => $data['description'] ?? null,

                    /*
                     * Frontend is never allowed to create
                     * a system role.
                     */
                    'is_system' => false,

                    'is_active' => (bool) (
                        $data['is_active']
                        ?? true
                    ),
                ]);

                if ($permissionIds !== []) {
                    $role
                        ->permissions()
                        ->sync($permissionIds);
                }
            }
        );

        return back()->with(
            'success',
            'Rôle créé avec succès.'
        );
    }

    /**
     * Display one role.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $role = $this->findScopedRole(
            $request,
            (int) $id
        );

        $role->load([
            'permissions' => function ($query) {
                $query
                    ->orderBy('module')
                    ->orderBy('name');
            },
        ]);

        $role->loadCount([
            'permissions',
            'users',
        ]);

        return response()->json([
            'role' => $role,
        ]);
    }

    /**
     * Update a role.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $organization =
            $this->getCurrentOrganization($request);
        $this->authorizeManagement($request, $organization);

        $role = $this->findScopedRole(
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

            'slug' => [
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

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'permission_ids' => [
                'sometimes',
                'array',
            ],

            'permission_ids.*' => [
                'integer',
                'exists:permissions,id',
            ],
        ]);

        /*
         * System roles are protected.
         *
         * Their identity and active state should not be
         * modified through normal administration.
         */
        if ($role->is_system) {
            $forbiddenFields = collect([
                'name',
                'slug',
                'is_active',
            ])->filter(
                fn ($field) => array_key_exists(
                    $field,
                    $data
                )
            );

            if ($forbiddenFields->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'role' => 'Les informations principales d’un rôle système ne peuvent pas être modifiées.',
                ]);
            }
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim(
                $data['name']
            );
        }

        if (array_key_exists('slug', $data)) {
            $slug = Str::slug(
                $data['slug']
            );

            if ($slug === '') {
                throw ValidationException::withMessages([
                    'slug' => 'Le slug du rôle est invalide.',
                ]);
            }

            $exists = Role::query()
                ->where(
                    'organization_id',
                    $organization->id
                )
                ->where(
                    'slug',
                    $slug
                )
                ->whereKeyNot(
                    $role->id
                )
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'slug' => 'Un rôle avec ce slug existe déjà dans cette société.',
                ]);
            }

            $data['slug'] = $slug;
        }

        $permissionIds = null;

        if (
            array_key_exists(
                'permission_ids',
                $data
            )
        ) {
            $permissionIds =
                $this->validatePermissions(
                    $data['permission_ids']
                );

            unset(
                $data['permission_ids']
            );
        }

        DB::transaction(
            function () use (
                $role,
                $data,
                $permissionIds
            ) {
                if ($data !== []) {
                    $role->update($data);
                }

                /*
                 * sync([]) intentionally removes all
                 * permissions if an empty array is sent.
                 */
                if ($permissionIds !== null) {
                    $role
                        ->permissions()
                        ->sync($permissionIds);
                }
            }
        );

        return back()->with(
            'success',
            'Rôle mis à jour avec succès.'
        );
    }

    /**
     * Delete a role.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $organization = $this->getCurrentOrganization($request);
        $this->authorizeManagement($request, $organization);
        $role = $this->findScopedRole(
            $request,
            (int) $id
        );

        /*
         * System roles must never be deleted.
         */
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'Un rôle système ne peut pas être supprimé.',
            ]);
        }

        /*
         * Do not delete a role still assigned to users.
         */
        if ($role->userRoles()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Ce rôle est encore attribué à un ou plusieurs utilisateurs. Retirez d’abord ces affectations.',
            ]);
        }

        DB::transaction(function () use ($role) {
            /*
             * Permissions can safely be detached because
             * this is configuration of the role itself.
             */
            $role
                ->permissions()
                ->detach();

            $role->delete();
        });

        return back()->with(
            'success',
            'Rôle supprimé avec succès.'
        );
    }
}
