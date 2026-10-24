<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\TaskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskStatusController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve the current organization from WorkPilot session.
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
     * Find a TaskStatus belonging to the current organization.
     */
    private function findScopedStatus(
        Request $request,
        int $statusId
    ): TaskStatus {
        $organization =
            $this->getCurrentOrganization($request);

        return TaskStatus::query()
            ->whereKey($statusId)
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
        ?int $ignoreStatusId = null
    ): string {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'status';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            TaskStatus::query()
                ->where(
                    'organization_id',
                    $organization->id
                )
                ->where(
                    'slug',
                    $slug
                )
                ->when(
                    $ignoreStatusId !== null,
                    fn ($query) => $query->where(
                        'id',
                        '!=',
                        $ignoreStatusId
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
     * Return the next available position.
     */
    private function getNextPosition(
        Organization $organization
    ): int {
        return (
            (int) TaskStatus::query()
                ->where(
                    'organization_id',
                    $organization->id
                )
                ->max('position')
        ) + 1;
    }

    /**
     * Display task statuses of the current organization.
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

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'is_closed' => [
                'nullable',
                'boolean',
            ],

            'is_default' => [
                'nullable',
                'boolean',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        $statuses = TaskStatus::query()
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
                                );
                        }
                    );
                }
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

            ->when(
                array_key_exists(
                    'is_closed',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_closed',
                    $validated['is_closed']
                )
            )

            ->when(
                array_key_exists(
                    'is_default',
                    $validated
                ),
                fn ($query) => $query->where(
                    'is_default',
                    $validated['is_default']
                )
            )

            ->withCount('tasks')

            ->orderBy('position')
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

            'statuses' => $statuses,
        ]);
    }

    /**
     * Store a new task status.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $organization =
            $this->getCurrentOrganization($request);

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

            'color' => [
                'nullable',
                'string',
                'max:50',
            ],

            'position' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_default' => [
                'nullable',
                'boolean',
            ],

            'is_closed' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ], [
            'name.required' => 'Le nom du statut est obligatoire.',

            'position.integer' => 'La position doit être un nombre entier.',

            'position.min' => 'La position ne peut pas être négative.',
        ]);

        $name = trim($data['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Le nom du statut ne peut pas être vide.',
            ]);
        }

        $slug = $this->generateUniqueSlug(
            $organization,
            ! empty($data['slug'])
                ? $data['slug']
                : $name
        );

        $isDefault = (bool) (
            $data['is_default'] ?? false
        );

        $isActive = (bool) (
            $data['is_active'] ?? true
        );

        /*
         * A default status must always be active.
         */
        if ($isDefault && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => 'Le statut par défaut doit être actif.',
            ]);
        }

        DB::transaction(
            function () use (
                $organization,
                $data,
                $name,
                $slug,
                $isDefault,
                $isActive
            ) {
                /*
                 * Only one default status
                 * per organization.
                 */
                if ($isDefault) {
                    TaskStatus::query()
                        ->where(
                            'organization_id',
                            $organization->id
                        )
                        ->where(
                            'is_default',
                            true
                        )
                        ->update([
                            'is_default' => false,
                        ]);
                }

                TaskStatus::create([
                    'organization_id' => $organization->id,

                    'name' => $name,

                    'slug' => $slug,

                    'color' => $data['color'] ?? null,

                    'position' => array_key_exists(
                        'position',
                        $data
                    )
                        ? (int) $data['position']
                        : $this->getNextPosition(
                            $organization
                        ),

                    'is_default' => $isDefault,

                    'is_closed' => (bool) (
                        $data['is_closed']
                        ?? false
                    ),

                    'is_active' => $isActive,
                ]);
            }
        );

        return back()->with(
            'success',
            'Statut de tâche créé avec succès.'
        );
    }

    /**
     * Display one task status.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $status = $this->findScopedStatus(
            $request,
            (int) $id
        );

        $status->loadCount('tasks');

        return response()->json([
            'status' => $status,
        ]);
    }

    /**
     * Update a task status.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $organization =
            $this->getCurrentOrganization($request);

        $status = $this->findScopedStatus(
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

            'color' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'position' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'is_default' => [
                'sometimes',
                'boolean',
            ],

            'is_closed' => [
                'sometimes',
                'boolean',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim(
                $data['name']
            );

            if ($data['name'] === '') {
                throw ValidationException::withMessages([
                    'name' => 'Le nom du statut ne peut pas être vide.',
                ]);
            }
        }

        if (array_key_exists('slug', $data)) {
            $slug = Str::slug(
                $data['slug']
            );

            if ($slug === '') {
                throw ValidationException::withMessages([
                    'slug' => 'Le slug du statut est invalide.',
                ]);
            }

            $slugAlreadyExists =
                TaskStatus::query()
                    ->where(
                        'organization_id',
                        $organization->id
                    )
                    ->where(
                        'slug',
                        $slug
                    )
                    ->where(
                        'id',
                        '!=',
                        $status->id
                    )
                    ->exists();

            if ($slugAlreadyExists) {
                throw ValidationException::withMessages([
                    'slug' => 'Un statut avec ce slug existe déjà dans cette société.',
                ]);
            }

            $data['slug'] = $slug;
        }

        /*
         * Determine final state before saving.
         */
        $finalIsDefault =
            array_key_exists(
                'is_default',
                $data
            )
            ? (bool) $data['is_default']
            : (bool) $status->is_default;

        $finalIsActive =
            array_key_exists(
                'is_active',
                $data
            )
            ? (bool) $data['is_active']
            : (bool) $status->is_active;

        if (
            $finalIsDefault
            && ! $finalIsActive
        ) {
            throw ValidationException::withMessages([
                'is_active' => 'Le statut par défaut doit rester actif.',
            ]);
        }

        /*
         * Do not allow removing the only default
         * without explicitly choosing another one.
         */
        if (
            $status->is_default
            && array_key_exists(
                'is_default',
                $data
            )
            && $data['is_default'] === false
        ) {
            $anotherDefaultExists =
                TaskStatus::query()
                    ->where(
                        'organization_id',
                        $organization->id
                    )
                    ->where(
                        'id',
                        '!=',
                        $status->id
                    )
                    ->where(
                        'is_default',
                        true
                    )
                    ->exists();

            if (! $anotherDefaultExists) {
                throw ValidationException::withMessages([
                    'is_default' => 'Vous devez définir un autre statut par défaut avant de retirer celui-ci.',
                ]);
            }
        }

        DB::transaction(
            function () use (
                $organization,
                $status,
                $data,
                $finalIsDefault
            ) {
                /*
                 * Setting this status as default
                 * automatically removes default flag
                 * from every other status.
                 */
                if ($finalIsDefault) {
                    TaskStatus::query()
                        ->where(
                            'organization_id',
                            $organization->id
                        )
                        ->where(
                            'id',
                            '!=',
                            $status->id
                        )
                        ->where(
                            'is_default',
                            true
                        )
                        ->update([
                            'is_default' => false,
                        ]);
                }

                $status->update($data);
            }
        );

        return back()->with(
            'success',
            'Statut de tâche mis à jour avec succès.'
        );
    }

    /**
     * Delete a task status.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $status = $this->findScopedStatus(
            $request,
            (int) $id
        );

        /*
         * Default status cannot be deleted.
         */
        if ($status->is_default) {
            throw ValidationException::withMessages([
                'status' => 'Le statut par défaut ne peut pas être supprimé. Définissez d’abord un autre statut par défaut.',
            ]);
        }

        /*
         * Status referenced by tasks cannot be deleted.
         */
        if ($status->tasks()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Ce statut est utilisé par une ou plusieurs tâches. Modifiez d’abord le statut de ces tâches.',
            ]);
        }

        DB::transaction(
            function () use ($status) {
                $status->delete();
            }
        );

        return back()->with(
            'success',
            'Statut de tâche supprimé avec succès.'
        );
    }
}
