<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentMemberController extends Controller
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
     * Find a department inside the current organization.
     */
    private function findScopedDepartment(
        Request $request,
        int $departmentId
    ): Department {
        $organization = $this->getCurrentOrganization($request);

        return Department::query()
            ->whereKey($departmentId)
            ->where('organization_id', $organization->id)
            ->firstOrFail();
    }

    /**
     * Find a department membership inside the current organization.
     */
    private function findScopedMembership(
        Request $request,
        int $membershipId
    ): DepartmentMember {
        $organization = $this->getCurrentOrganization($request);

        return DepartmentMember::query()
            ->whereKey($membershipId)
            ->whereHas(
                'department',
                fn ($query) => $query->where(
                    'organization_id',
                    $organization->id
                )
            )
            ->firstOrFail();
    }

    /**
     * Ensure that the selected user belongs to
     * the current organization.
     */
    private function findOrganizationUser(
        Request $request,
        int $userId
    ): User {
        $organization = $this->getCurrentOrganization($request);

        return User::query()
            ->whereKey($userId)
            ->whereHas(
                'organizations',
                function ($query) use ($organization) {
                    $query
                        ->where(
                            'organizations.id',
                            $organization->id
                        )
                        ->wherePivot('is_active', true);
                }
            )
            ->firstOrFail();
    }

    /**
     * Remove the primary flag from the user's other
     * department memberships inside the same organization.
     */
    private function clearOtherPrimaryMemberships(
        Organization $organization,
        int $userId,
        ?int $exceptMembershipId = null
    ): void {
        DepartmentMember::query()
            ->where('user_id', $userId)
            ->where('is_primary', true)
            ->whereHas(
                'department',
                fn ($query) => $query->where(
                    'organization_id',
                    $organization->id
                )
            )
            ->when(
                $exceptMembershipId !== null,
                fn ($query) => $query->whereKeyNot(
                    $exceptMembershipId
                )
            )
            ->update([
                'is_primary' => false,
            ]);
    }

    /**
     * Display department memberships
     * of the current organization.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $validated = $request->validate([
            'department_id' => [
                'nullable',
                'integer',
                'exists:departments,id',
            ],

            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'is_manager' => [
                'nullable',
                'boolean',
            ],

            'is_primary' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
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

        if (! empty($validated['department_id'])) {
            $this->findScopedDepartment(
                $request,
                (int) $validated['department_id']
            );
        }

        $members = DepartmentMember::query()
            ->whereHas(
                'department',
                fn ($query) => $query->where(
                    'organization_id',
                    $organization->id
                )
            )

            ->when(
                ! empty($validated['department_id']),
                fn ($query) => $query->where(
                    'department_id',
                    $validated['department_id']
                )
            )

            ->when(
                ! empty($validated['user_id']),
                fn ($query) => $query->where(
                    'user_id',
                    $validated['user_id']
                )
            )

            ->when(
                array_key_exists('is_manager', $validated),
                fn ($query) => $query->where(
                    'is_manager',
                    $validated['is_manager']
                )
            )

            ->when(
                array_key_exists('is_primary', $validated),
                fn ($query) => $query->where(
                    'is_primary',
                    $validated['is_primary']
                )
            )

            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where(
                    'is_active',
                    $validated['is_active']
                )
            )

            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim($validated['search']);

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
                'department:id,organization_id,name,code',
                'user:id,name,email',
            ])

            ->orderByDesc('is_primary')
            ->orderByDesc('is_manager')
            ->orderByDesc('is_active')
            ->latest('id')

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

            'members' => $members,
        ]);
    }

    /**
     * Add a user to a department.
     */
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $data = $request->validate([
            'department_id' => [
                'required',
                'integer',
                'exists:departments,id',
            ],

            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'is_manager' => [
                'nullable',
                'boolean',
            ],

            'is_primary' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'joined_at' => [
                'nullable',
                'date',
            ],
        ], [
            'department_id.required' => 'Le service est obligatoire.',

            'department_id.exists' => 'Le service sélectionné est introuvable.',

            'user_id.required' => 'L’utilisateur est obligatoire.',

            'user_id.exists' => 'L’utilisateur sélectionné est introuvable.',

            'joined_at.date' => 'La date d’affectation est invalide.',
        ]);

        $department = $this->findScopedDepartment(
            $request,
            (int) $data['department_id']
        );

        $user = $this->findOrganizationUser(
            $request,
            (int) $data['user_id']
        );

        /*
         * Prevent duplicate membership.
         */
        $alreadyExists = DepartmentMember::query()
            ->where('department_id', $department->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur est déjà affecté à ce service.',
            ]);
        }

        DB::transaction(function () use (
            $organization,
            $department,
            $user,
            $data
        ) {
            $isPrimary = (bool) ($data['is_primary'] ?? false);

            /*
             * One primary service per organization/user.
             */
            if ($isPrimary) {
                $this->clearOtherPrimaryMemberships(
                    $organization,
                    $user->id
                );
            }

            DepartmentMember::create([
                'department_id' => $department->id,
                'user_id' => $user->id,

                'is_manager' => (bool) ($data['is_manager'] ?? false),

                'is_primary' => $isPrimary,

                'is_active' => (bool) ($data['is_active'] ?? true),

                'joined_at' => $data['joined_at'] ?? now(),
            ]);
        });

        return back()->with(
            'success',
            'Utilisateur affecté au service avec succès.'
        );
    }

    /**
     * Display one department membership.
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
            'department:id,organization_id,name,code',
            'user:id,name,email',
        ]);

        return response()->json([
            'membership' => $membership,
        ]);
    }

    /**
     * Update a department membership.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $organization = $this->getCurrentOrganization($request);

        $membership = $this->findScopedMembership(
            $request,
            (int) $id
        );

        $data = $request->validate([
            'department_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:departments,id',
            ],

            'is_manager' => [
                'sometimes',
                'boolean',
            ],

            'is_primary' => [
                'sometimes',
                'boolean',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'joined_at' => [
                'sometimes',
                'nullable',
                'date',
            ],
        ]);

        /*
         * Moving a membership is allowed only to another
         * department inside the current organization.
         */
        if (array_key_exists('department_id', $data)) {
            $department = $this->findScopedDepartment(
                $request,
                (int) $data['department_id']
            );

            $duplicate = DepartmentMember::query()
                ->where('department_id', $department->id)
                ->where('user_id', $membership->user_id)
                ->whereKeyNot($membership->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'department_id' => 'Cet utilisateur est déjà affecté au service sélectionné.',
                ]);
            }

            $data['department_id'] = $department->id;
        }

        DB::transaction(function () use (
            $organization,
            $membership,
            $data
        ) {
            if (
                array_key_exists('is_primary', $data)
                && (bool) $data['is_primary']
            ) {
                $this->clearOtherPrimaryMemberships(
                    $organization,
                    $membership->user_id,
                    $membership->id
                );
            }

            /*
             * An inactive membership should not remain primary.
             */
            if (
                array_key_exists('is_active', $data)
                && ! (bool) $data['is_active']
            ) {
                $data['is_primary'] = false;
            }

            $membership->update($data);
        });

        return back()->with(
            'success',
            'Affectation mise à jour avec succès.'
        );
    }

    /**
     * Remove a user from a department.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership = $this->findScopedMembership(
            $request,
            (int) $id
        );

        DB::transaction(function () use ($membership) {
            $membership->delete();
        });

        return back()->with(
            'success',
            'Utilisateur retiré du service avec succès.'
        );
    }
}
