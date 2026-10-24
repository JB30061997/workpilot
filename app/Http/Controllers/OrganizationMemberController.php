<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationMemberController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Find an organization accessible by the authenticated user.
     */
    private function findAccessibleOrganization(
        Request $request,
        int $organizationId
    ): Organization {
        return Organization::query()
            ->whereKey($organizationId)
            ->whereHas(
                'memberships',
                function ($query) use ($request) {
                    $query
                        ->where(
                            'user_id',
                            $request->user()->id
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Find an organization owned by the authenticated user.
     */
    private function findOwnedOrganization(
        Request $request,
        int $organizationId
    ): Organization {
        return Organization::query()
            ->whereKey($organizationId)
            ->whereHas(
                'memberships',
                function ($query) use ($request) {
                    $query
                        ->where(
                            'user_id',
                            $request->user()->id
                        )
                        ->where(
                            'is_owner',
                            true
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Find a membership inside an organization
     * accessible by the authenticated user.
     */
    private function findAccessibleMembership(
        Request $request,
        int $membershipId
    ): OrganizationMember {
        return OrganizationMember::query()
            ->whereKey($membershipId)
            ->whereHas(
                'organization.memberships',
                function ($query) use ($request) {
                    $query
                        ->where(
                            'user_id',
                            $request->user()->id
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Find a membership whose organization
     * is owned by the authenticated user.
     */
    private function findOwnedMembership(
        Request $request,
        int $membershipId
    ): OrganizationMember {
        return OrganizationMember::query()
            ->whereKey($membershipId)
            ->whereHas(
                'organization.memberships',
                function ($query) use ($request) {
                    $query
                        ->where(
                            'user_id',
                            $request->user()->id
                        )
                        ->where(
                            'is_owner',
                            true
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Count active owners of an organization.
     */
    private function activeOwnersCount(
        int $organizationId
    ): int {
        return OrganizationMember::query()
            ->where(
                'organization_id',
                $organizationId
            )
            ->where(
                'is_owner',
                true
            )
            ->where(
                'is_active',
                true
            )
            ->count();
    }

    /**
     * Prevent removing the last active owner.
     */
    private function ensureNotLastActiveOwner(
        OrganizationMember $membership
    ): void {
        if (
            ! $membership->is_owner
            || ! $membership->is_active
        ) {
            return;
        }

        if (
            $this->activeOwnersCount(
                $membership->organization_id
            ) <= 1
        ) {
            throw ValidationException::withMessages([
                'member' => 'Impossible de retirer ou désactiver le dernier propriétaire actif de la société.',
            ]);
        }
    }

    /**
     * Display members of an organization.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                'exists:organizations,id',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'is_owner' => [
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

        $organization = $this->findAccessibleOrganization(
            $request,
            (int) $validated['organization_id']
        );

        $members = OrganizationMember::query()
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

            ->when(
                ! empty($validated['status']),
                fn ($query) => $query->where(
                    'status',
                    $validated['status']
                )
            )

            ->when(
                array_key_exists(
                    'is_owner',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_owner',
                    $validated['is_owner']
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

            ->with([
                'user:id,name,email',
            ])

            ->orderByDesc('is_owner')
            ->orderByDesc('is_active')
            ->latest('joined_at')
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
     * Add a user to an organization.
     *
     * Only an active owner can add members.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $data = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                'exists:organizations,id',
            ],

            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'job_title' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'joined_at' => [
                'nullable',
                'date',
            ],

            'is_owner' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ], [
            'organization_id.required' => 'La société est obligatoire.',

            'organization_id.exists' => 'La société sélectionnée est introuvable.',

            'user_id.required' => 'L’utilisateur est obligatoire.',

            'user_id.exists' => 'L’utilisateur sélectionné est introuvable.',

            'joined_at.date' => 'La date d’intégration est invalide.',
        ]);

        $organization = $this->findOwnedOrganization(
            $request,
            (int) $data['organization_id']
        );

        $user = User::query()
            ->findOrFail(
                (int) $data['user_id']
            );

        $alreadyExists = OrganizationMember::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur appartient déjà à cette société.',
            ]);
        }

        DB::transaction(function () use (
            $organization,
            $user,
            $data
        ) {
            OrganizationMember::create([
                'organization_id' => $organization->id,

                'user_id' => $user->id,

                'job_title' => isset($data['job_title'])
                    ? trim($data['job_title'])
                    : null,

                'status' => $data['status'] ?? 'active',

                'joined_at' => $data['joined_at'] ?? now(),

                'is_owner' => (bool) (
                    $data['is_owner']
                    ?? false
                ),

                'is_active' => (bool) (
                    $data['is_active']
                    ?? true
                ),
            ]);
        });

        return back()->with(
            'success',
            'Utilisateur ajouté à la société avec succès.'
        );
    }

    /**
     * Display one organization membership.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $membership =
            $this->findAccessibleMembership(
                $request,
                (int) $id
            );

        $membership->load([
            'organization:id,name,slug',
            'user:id,name,email',
        ]);

        return response()->json([
            'membership' => $membership,
        ]);
    }

    /**
     * Update an organization membership.
     *
     * organization_id and user_id are intentionally immutable.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership =
            $this->findOwnedMembership(
                $request,
                (int) $id
            );

        $data = $request->validate([
            'job_title' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'max:50',
            ],

            'joined_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'is_owner' => [
                'sometimes',
                'boolean',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        /*
         * Determine the final state before applying it.
         */
        $finalIsOwner = array_key_exists(
            'is_owner',
            $data
        )
            ? (bool) $data['is_owner']
            : (bool) $membership->is_owner;

        $finalIsActive = array_key_exists(
            'is_active',
            $data
        )
            ? (bool) $data['is_active']
            : (bool) $membership->is_active;

        /*
         * If the current membership is the last active owner,
         * it cannot lose ownership or be deactivated.
         */
        if (
            $membership->is_owner
            && $membership->is_active
            && (
                ! $finalIsOwner
                || ! $finalIsActive
            )
            && $this->activeOwnersCount(
                $membership->organization_id
            ) <= 1
        ) {
            throw ValidationException::withMessages([
                'member' => 'Cette opération laisserait la société sans propriétaire actif.',
            ]);
        }

        if (
            array_key_exists(
                'job_title',
                $data
            )
            && $data['job_title'] !== null
        ) {
            $data['job_title'] = trim(
                $data['job_title']
            );
        }

        /*
         * An inactive membership should not keep
         * operational ownership privileges.
         */
        if (
            array_key_exists(
                'is_active',
                $data
            )
            && ! (bool) $data['is_active']
        ) {
            $data['is_owner'] = false;
        }

        DB::transaction(function () use (
            $membership,
            $data
        ) {
            $membership->update($data);
        });

        return back()->with(
            'success',
            'Membre mis à jour avec succès.'
        );
    }

    /**
     * Remove a member from an organization.
     *
     * The last active owner can never be removed.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership =
            $this->findOwnedMembership(
                $request,
                (int) $id
            );

        $this->ensureNotLastActiveOwner(
            $membership
        );

        /*
         * Prevent an owner from accidentally deleting
         * their own active membership while managing
         * the organization.
         */
        if (
            (int) $membership->user_id
            === (int) $request->user()->id
            && $membership->is_owner
        ) {
            throw ValidationException::withMessages([
                'member' => 'Vous ne pouvez pas supprimer directement votre propre affectation propriétaire.',
            ]);
        }

        DB::transaction(function () use (
            $membership
        ) {
            $membership->delete();
        });

        return back()->with(
            'success',
            'Utilisateur retiré de la société avec succès.'
        );
    }
}
