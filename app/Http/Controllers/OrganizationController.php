<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProjectCategory;
use App\Services\DefaultRoleProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly DefaultRoleProvisioner $defaultRoleProvisioner) {}

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
                        ->where('user_id', $request->user()->id)
                        ->where('is_active', true);
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
                        ->where('user_id', $request->user()->id)
                        ->where('is_owner', true)
                        ->where('is_active', true);
                }
            )
            ->firstOrFail();
    }

    /**
     * Find an organization manageable by an owner or an administrative role.
     */
    private function findManageableOrganization(
        Request $request,
        int $organizationId
    ): Organization {
        $organization = $this->findAccessibleOrganization($request, $organizationId);
        $isOwner = $organization->memberships()
            ->where('user_id', $request->user()->id)
            ->where('is_owner', true)
            ->where('is_active', true)
            ->exists();
        $hasAdministrativeRole = $request->user()
            ->roles()
            ->where('user_roles.organization_id', $organization->id)
            ->whereIn('roles.slug', ['administrator', 'director'])
            ->exists();

        abort_unless($isOwner || $hasAdministrativeRole, 403);

        return $organization;
    }

    /**
     * Generate a unique organization slug.
     */
    private function generateUniqueSlug(
        string $name,
        ?int $ignoreOrganizationId = null
    ): string {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'organization';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            Organization::query()
                ->where('slug', $slug)
                ->when(
                    $ignoreOrganizationId !== null,
                    fn ($query) => $query->whereKeyNot(
                        $ignoreOrganizationId
                    )
                )
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Display organizations available to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
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

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        $organizations = Organization::query()
            ->whereHas(
                'memberships',
                function ($query) use ($request) {
                    $query
                        ->where('user_id', $request->user()->id)
                        ->where('is_active', true);
                }
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
                                'legal_name',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'email',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'city',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'country',
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

            ->withCount([
                'users',
                'departments',
                'actionPlans',
                'projects',
                'tasks',
            ])

            ->orderByDesc('is_active')
            ->orderBy('name')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        return response()->json([
            'organizations' => $organizations,
        ]);
    }

    /**
     * Create a new organization.
     *
     * The authenticated user automatically becomes its owner.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'legal_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'logo' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'website' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'timezone' => [
                'nullable',
                'string',
                'max:100',
            ],

            'locale' => [
                'nullable',
                'string',
                'max:20',
            ],

            'settings' => [
                'nullable',
                'array',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ], [
            'name.required' => 'Le nom de la société est obligatoire.',

            'name.max' => 'Le nom de la société ne peut pas dépasser 255 caractères.',

            'email.email' => 'L’adresse e-mail de la société est invalide.',

            'settings.array' => 'Les paramètres de la société sont invalides.',
        ]);

        $organization = DB::transaction(
            function () use ($request, $data) {
                $organization = Organization::create([
                    'name' => trim($data['name']),

                    'slug' => $this->generateUniqueSlug(
                        $data['name']
                    ),

                    'legal_name' => $data['legal_name'] ?? null,

                    'logo' => $data['logo'] ?? null,

                    'email' => $data['email'] ?? null,

                    'phone' => $data['phone'] ?? null,

                    'website' => $data['website'] ?? null,

                    'country' => $data['country'] ?? null,

                    'city' => $data['city'] ?? null,

                    'address' => $data['address'] ?? null,

                    'timezone' => $data['timezone'] ?? 'Africa/Casablanca',

                    'locale' => $data['locale'] ?? 'fr',

                    'settings' => $data['settings'] ?? [],

                    'is_active' => (bool) ($data['is_active'] ?? true),
                ]);

                OrganizationMember::create([
                    'organization_id' => $organization->id,
                    'user_id' => $request->user()->id,

                    'job_title' => null,

                    'status' => 'active',

                    'joined_at' => now(),

                    'is_owner' => true,
                    'is_active' => true,
                ]);

                foreach (['Support', 'Pédagogique', 'Autre'] as $categoryName) {
                    ProjectCategory::query()->create([
                        'organization_id' => $organization->id,
                        'manager_id' => $request->user()->id,
                        'name' => $categoryName,
                        'slug' => Str::slug($categoryName),
                        'is_active' => true,
                    ]);
                }

                $administratorRole = $this->defaultRoleProvisioner
                    ->provision($organization)
                    ->firstWhere('slug', 'administrator');

                $request->user()->roles()->syncWithoutDetaching([
                    $administratorRole->id => ['organization_id' => $organization->id],
                ]);

                return $organization;
            }
        );

        return back()->with(
            'success',
            'Société créée avec succès.'
        );
    }

    /**
     * Display one organization.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $organization = $this->findAccessibleOrganization(
            $request,
            (int) $id
        );

        $organization->load([
            'departments' => function ($query) {
                $query
                    ->where('is_active', true)
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

        $organization->loadCount([
            'users',
            'departments',
            'roles',
            'actionPlans',
            'projects',
            'actions',
            'tasks',
            'workSessions',
            'attachments',
            'activities',
        ]);

        $membership = OrganizationMember::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->where(
                'user_id',
                $request->user()->id
            )
            ->first();

        return response()->json([
            'organization' => $organization,

            'membership' => [
                'job_title' => $membership?->job_title,
                'status' => $membership?->status,
                'is_owner' => (bool) $membership?->is_owner,
                'is_active' => (bool) $membership?->is_active,
                'joined_at' => $membership?->joined_at,
            ],
        ]);
    }

    /**
     * Update an organization.
     *
     * Only an active owner may perform this operation.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $organization = $this->findManageableOrganization(
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

                Rule::unique(
                    'organizations',
                    'slug'
                )->ignore($organization->id),
            ],

            'legal_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'logo' => [
                'sometimes',
                'nullable',
                'string',
                'max:2048',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'website' => [
                'sometimes',
                'nullable',
                'string',
                'max:2048',
            ],

            'country' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            'timezone' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'locale' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
            ],

            'settings' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }

        if (array_key_exists('slug', $data)) {
            $slug = Str::slug($data['slug']);

            if ($slug === '') {
                throw ValidationException::withMessages([
                    'slug' => 'Le slug de la société est invalide.',
                ]);
            }

            $data['slug'] = $slug;
        }

        DB::transaction(function () use (
            $organization,
            $data
        ) {
            $organization->update($data);
        });

        return back()->with(
            'success',
            'Société mise à jour avec succès.'
        );
    }

    /**
     * Delete an organization.
     *
     * Only the owner can delete an empty organization.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $organization = $this->findOwnedOrganization(
            $request,
            (int) $id
        );

        $dependencies = [];

        if ($organization->departments()->exists()) {
            $dependencies[] = 'des services';
        }

        if ($organization->actionPlans()->exists()) {
            $dependencies[] = 'des plans d’action';
        }

        if ($organization->projects()->exists()) {
            $dependencies[] = 'des projets';
        }

        if ($organization->actions()->exists()) {
            $dependencies[] = 'des actions';
        }

        if ($organization->tasks()->exists()) {
            $dependencies[] = 'des tâches';
        }

        if ($organization->workSessions()->exists()) {
            $dependencies[] = 'des sessions de travail';
        }

        if ($organization->attachments()->exists()) {
            $dependencies[] = 'des pièces jointes';
        }

        if ($organization->activities()->exists()) {
            $dependencies[] = 'un historique d’activité';
        }

        /*
         * Allow only the owner membership to remain.
         */
        $otherMembersExist = $organization
            ->memberships()
            ->where(
                'user_id',
                '!=',
                $request->user()->id
            )
            ->exists();

        if ($otherMembersExist) {
            $dependencies[] = 'd’autres membres';
        }

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'organization' => 'Cette société contient encore '
                    .implode(', ', $dependencies)
                    .'. Supprimez ou réaffectez ces éléments avant de supprimer la société.',
            ]);
        }

        DB::transaction(function () use (
            $organization,
            $request
        ) {
            OrganizationMember::query()
                ->where(
                    'organization_id',
                    $organization->id
                )
                ->where(
                    'user_id',
                    $request->user()->id
                )
                ->delete();

            $organization->delete();
        });

        return back()->with(
            'success',
            'Société supprimée avec succès.'
        );
    }
}
