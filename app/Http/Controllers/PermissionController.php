<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Support\CurrentDepartmentAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PermissionController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly CurrentDepartmentAuthorizer $departmentAuthorizer
    ) {}

    private function authorizeManagement(Request $request): void
    {
        $this->departmentAuthorizer->authorizeAccessManagement(
            $request->user(),
            (int) session('current_organization_id'),
            (int) session('current_department_id'),
        );
    }

    /**
     * Display a listing of permissions.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'module' => [
                'nullable',
                'string',
                'max:100',
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

        $permissions = Permission::query()
            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim($validated['search']);

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
                                    'module',
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
                ! empty($validated['module']),
                fn ($query) => $query->where(
                    'module',
                    $validated['module']
                )
            )

            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where(
                    'is_active',
                    $validated['is_active']
                )
            )

            ->withCount('roles')

            ->orderBy('module')
            ->orderBy('name')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        $modules = Permission::query()
            ->whereNotNull('module')
            ->where('module', '!=', '')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->values();

        return response()->json([
            'permissions' => $permissions,
            'modules' => $modules,
        ]);
    }

    /**
     * Store a newly created permission.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManagement($request);
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
                'unique:permissions,slug',
            ],

            'module' => [
                'required',
                'string',
                'max:100',
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
        ], [
            'name.required' => 'Le nom de la permission est obligatoire.',

            'name.max' => 'Le nom de la permission ne peut pas dépasser 255 caractères.',

            'slug.unique' => 'Ce slug de permission existe déjà.',

            'module.required' => 'Le module de la permission est obligatoire.',

            'module.max' => 'Le module ne peut pas dépasser 100 caractères.',

            'is_active.boolean' => 'La valeur du champ actif est invalide.',
        ]);

        $name = trim($data['name']);
        $module = trim($data['module']);

        $slug = isset($data['slug'])
            && trim($data['slug']) !== ''
            ? Str::slug($data['slug'])
            : Str::slug($module.'-'.$name);

        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => 'Impossible de générer un slug valide pour cette permission.',
            ]);
        }

        if (
            Permission::query()
                ->where('slug', $slug)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'slug' => 'Une permission avec ce slug existe déjà.',
            ]);
        }

        DB::transaction(function () use (
            $data,
            $name,
            $module,
            $slug
        ) {
            Permission::create([
                'name' => $name,
                'slug' => $slug,
                'module' => $module,

                'description' => $data['description'] ?? null,

                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        });

        return back()->with(
            'success',
            'Permission créée avec succès.'
        );
    }

    /**
     * Display the specified permission.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $permission = Permission::query()
            ->with([
                'roles' => function ($query) {
                    $query->orderBy('name');
                },
            ])
            ->withCount('roles')
            ->findOrFail((int) $id);

        return response()->json([
            'permission' => $permission,
        ]);
    }

    /**
     * Update the specified permission.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $this->authorizeManagement($request);
        $permission = Permission::query()
            ->findOrFail((int) $id);

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

                Rule::unique(
                    'permissions',
                    'slug'
                )->ignore($permission->id),
            ],

            'module' => [
                'sometimes',
                'required',
                'string',
                'max:100',
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
        ], [
            'name.required' => 'Le nom de la permission est obligatoire.',

            'slug.required' => 'Le slug de la permission est obligatoire.',

            'slug.unique' => 'Ce slug de permission existe déjà.',

            'module.required' => 'Le module de la permission est obligatoire.',

            'is_active.boolean' => 'La valeur du champ actif est invalide.',
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }

        if (array_key_exists('module', $data)) {
            $data['module'] = trim($data['module']);
        }

        if (array_key_exists('slug', $data)) {
            $slug = Str::slug($data['slug']);

            if ($slug === '') {
                throw ValidationException::withMessages([
                    'slug' => 'Le slug de la permission est invalide.',
                ]);
            }

            $slugExists = Permission::query()
                ->where('slug', $slug)
                ->whereKeyNot($permission->id)
                ->exists();

            if ($slugExists) {
                throw ValidationException::withMessages([
                    'slug' => 'Ce slug de permission existe déjà.',
                ]);
            }

            $data['slug'] = $slug;
        }

        DB::transaction(function () use (
            $permission,
            $data
        ) {
            $permission->update($data);
        });

        return back()->with(
            'success',
            'Permission mise à jour avec succès.'
        );
    }

    /**
     * Remove the specified permission.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $this->authorizeManagement($request);
        $permission = Permission::query()
            ->findOrFail((int) $id);

        /*
         * Never silently delete a permission
         * currently assigned to one or more roles.
         */
        if ($permission->roles()->exists()) {
            throw ValidationException::withMessages([
                'permission' => 'Cette permission est encore attribuée à un ou plusieurs rôles. Retirez-la des rôles avant de la supprimer.',
            ]);
        }

        /*
         * Extra protection for the direct relation
         * represented by RolePermission.
         */
        if ($permission->rolePermissions()->exists()) {
            throw ValidationException::withMessages([
                'permission' => 'Cette permission possède encore des affectations de rôle.',
            ]);
        }

        DB::transaction(function () use ($permission) {
            $permission->delete();
        });

        return back()->with(
            'success',
            'Permission supprimée avec succès.'
        );
    }
}
