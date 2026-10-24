<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve the active organization of the authenticated user.
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
     * Find a department strictly inside the current organization.
     */
    private function findScopedDepartment(
        Request $request,
        int $departmentId
    ): Department {
        $organization = $this->getCurrentOrganization($request);

        return Department::query()
            ->whereKey($departmentId)
            ->where(
                'organization_id',
                $organization->id
            )
            ->firstOrFail();
    }

    /**
     * Validate the selected parent department.
     */
    private function validateParent(
        Request $request,
        ?int $parentId,
        ?Department $currentDepartment = null
    ): ?Department {
        if ($parentId === null) {
            return null;
        }

        $parent = $this->findScopedDepartment(
            $request,
            $parentId
        );

        /*
         * A department cannot be its own parent.
         */
        if (
            $currentDepartment
            && (int) $parent->id === (int) $currentDepartment->id
        ) {
            throw ValidationException::withMessages([
                'parent_id' => 'Un service ne peut pas être son propre service parent.',
            ]);
        }

        /*
         * Prevent circular hierarchies.
         *
         * Example:
         *
         * IT
         * └── Infrastructure
         *
         * IT cannot later become a child of Infrastructure.
         */
        if ($currentDepartment) {
            $cursor = $parent;

            while ($cursor !== null) {
                if (
                    (int) $cursor->id
                    === (int) $currentDepartment->id
                ) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Cette sélection créerait une hiérarchie circulaire entre les services.',
                    ]);
                }

                if ($cursor->parent_id === null) {
                    break;
                }

                $cursor = Department::query()
                    ->whereKey($cursor->parent_id)
                    ->where(
                        'organization_id',
                        $currentDepartment->organization_id
                    )
                    ->first();
            }
        }

        return $parent;
    }

    /**
     * Display departments of the current organization.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:departments,id',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        /*
         * If a parent filter is supplied,
         * verify that it belongs to the current organization.
         */
        if (! empty($validated['parent_id'])) {
            $this->findScopedDepartment(
                $request,
                (int) $validated['parent_id']
            );
        }

        $departments = Department::query()
            ->where(
                'organization_id',
                $organization->id
            )

            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim($validated['search']);

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'name',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'code',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'description',
                                'like',
                                '%'.$search.'%'
                            );
                    });
                }
            )

            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where(
                    'is_active',
                    $validated['is_active']
                )
            )

            ->when(
                ! empty($validated['parent_id']),
                fn ($query) => $query->where(
                    'parent_id',
                    $validated['parent_id']
                )
            )

            ->with([
                'parent:id,name,code',
            ])

            ->withCount([
                'children',
                'users',
                'actionPlans',
                'projects',
                'tasks',
                'workSessions',
            ])

            ->orderByDesc('is_active')
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

            'departments' => $departments,
        ]);
    }

    /**
     * Store a new department/service.
     */
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:departments,id',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ], [
            'name.required' => 'Le nom du service est obligatoire.',

            'name.max' => 'Le nom du service ne peut pas dépasser 255 caractères.',

            'code.max' => 'Le code du service ne peut pas dépasser 255 caractères.',

            'parent_id.exists' => 'Le service parent sélectionné est introuvable.',

            'is_active.required' => 'L’état du service est obligatoire.',

            'is_active.boolean' => 'La valeur du champ actif est invalide.',
        ]);

        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = $this->validateParent(
                $request,
                (int) $data['parent_id']
            );
        }

        DB::transaction(function () use (
            $organization,
            $data,
            $parent
        ) {
            Department::create([
                'organization_id' => $organization->id,

                'name' => trim($data['name']),
                'code' => isset($data['code'])
                    ? trim($data['code'])
                    : null,

                'description' => $data['description'] ?? null,

                'parent_id' => $parent?->id,

                'is_active' => $data['is_active'],
            ]);
        });

        return back()->with(
            'success',
            'Service créé avec succès.'
        );
    }

    /**
     * Display one department/service.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $department = $this->findScopedDepartment(
            $request,
            (int) $id
        );

        $department->load([
            'organization:id,name',

            'parent:id,name,code,parent_id',

            'children' => function ($query) {
                $query
                    ->orderByDesc('is_active')
                    ->orderBy('name');
            },

            'users' => function ($query) {
                $query
                    ->select([
                        'users.id',
                        'users.name',
                        'users.email',
                    ])
                    ->orderBy('users.name');
            },
        ]);

        $department->loadCount([
            'children',
            'users',
            'actionPlans',
            'projects',
            'tasks',
            'workSessions',
        ]);

        return response()->json([
            'department' => $department,
        ]);
    }

    /**
     * Update a department/service.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $department = $this->findScopedDepartment(
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

            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:10000',
            ],

            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:departments,id',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ], [
            'name.required' => 'Le nom du service est obligatoire.',

            'name.max' => 'Le nom du service ne peut pas dépasser 255 caractères.',

            'code.max' => 'Le code du service ne peut pas dépasser 255 caractères.',

            'parent_id.exists' => 'Le service parent sélectionné est introuvable.',

            'is_active.boolean' => 'La valeur du champ actif est invalide.',
        ]);

        /*
         * organization_id is intentionally not accepted.
         *
         * A department cannot be moved from one organization
         * to another through this endpoint.
         */

        if (array_key_exists('parent_id', $data)) {
            $parent = $this->validateParent(
                $request,
                $data['parent_id'] !== null
                    ? (int) $data['parent_id']
                    : null,
                $department
            );

            $data['parent_id'] = $parent?->id;
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }

        if (
            array_key_exists('code', $data)
            && $data['code'] !== null
        ) {
            $data['code'] = trim($data['code']);
        }

        DB::transaction(function () use (
            $department,
            $data
        ) {
            $department->update($data);
        });

        return back()->with(
            'success',
            'Service mis à jour avec succès.'
        );
    }

    /**
     * Delete a department/service.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $department = $this->findScopedDepartment(
            $request,
            (int) $id
        );

        /*
         * A structural department must never disappear while
         * operational or organizational data still depends on it.
         */
        $dependencies = [];

        if ($department->children()->exists()) {
            $dependencies[] = 'des sous-services';
        }

        if ($department->memberships()->exists()) {
            $dependencies[] = 'des membres';
        }

        if ($department->actionPlans()->exists()) {
            $dependencies[] = 'des plans d’action';
        }

        if ($department->projects()->exists()) {
            $dependencies[] = 'des projets';
        }

        if ($department->tasks()->exists()) {
            $dependencies[] = 'des tâches';
        }

        if ($department->workSessions()->exists()) {
            $dependencies[] = 'des sessions de travail';
        }

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'department' => 'Ce service contient encore '
                    .implode(', ', $dependencies)
                    .'. Réaffectez ou supprimez ces éléments avant de supprimer le service.',
            ]);
        }

        DB::transaction(function () use ($department) {
            $department->delete();
        });

        return back()->with(
            'success',
            'Service supprimé avec succès.'
        );
    }
}
