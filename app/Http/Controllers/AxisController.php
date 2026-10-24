<?php

namespace App\Http\Controllers;

use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AxisController extends Controller
{
    /**
     * Default pagination size.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum pagination size.
     */
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
     * Resolve the active department/service of the authenticated user.
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
     * Resolve the complete current WorkPilot context.
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
     * Find an ActionPlan belonging strictly to the
     * current organization and department.
     */
    private function findScopedActionPlan(
        Request $request,
        int $actionPlanId
    ): ActionPlan {
        $context = $this->getContext($request);

        return ActionPlan::query()
            ->whereKey($actionPlanId)
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
     * Find an Axis belonging strictly to the current
     * organization and department through its ActionPlan.
     */
    private function findScopedAxis(
        Request $request,
        int $axisId
    ): Axis {
        $context = $this->getContext($request);

        return Axis::query()
            ->whereKey($axisId)
            ->whereHas(
                'actionPlan',
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
     * Validation rules shared between store and update.
     */
    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'action_plan_id' => $isUpdate
                ? [
                    'sometimes',
                    'required',
                    'integer',
                    'exists:action_plans,id',
                ]
                : [
                    'required',
                    'integer',
                    'exists:action_plans,id',
                ],

            'name' => [
                $required,
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

            'position' => [
                $required,
                'integer',
                'min:0',
            ],

            'is_active' => [
                $required,
                'boolean',
            ],
        ];
    }

    /**
     * Validation messages.
     */
    private function validationMessages(): array
    {
        return [
            'action_plan_id.required' => 'Le plan d’action est obligatoire.',

            'action_plan_id.integer' => 'Le plan d’action sélectionné est invalide.',

            'action_plan_id.exists' => 'Le plan d’action sélectionné est introuvable.',

            'name.required' => 'Le nom de l’axe est obligatoire.',

            'name.string' => 'Le nom de l’axe est invalide.',

            'name.max' => 'Le nom de l’axe ne peut pas dépasser 255 caractères.',

            'code.max' => 'Le code de l’axe ne peut pas dépasser 255 caractères.',

            'position.required' => 'La position de l’axe est obligatoire.',

            'position.integer' => 'La position doit être un nombre entier.',

            'position.min' => 'La position ne peut pas être négative.',

            'is_active.required' => 'L’état actif de l’axe est obligatoire.',

            'is_active.boolean' => 'La valeur du champ actif est invalide.',
        ];
    }

    /**
     * Display the axes belonging to the current service.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        $validated = $request->validate([
            'action_plan_id' => [
                'nullable',
                'integer',
                'exists:action_plans,id',
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

        /*
         * If an ActionPlan filter is supplied, verify that
         * this plan belongs to the current service.
         */
        if (! empty($validated['action_plan_id'])) {
            $this->findScopedActionPlan(
                $request,
                (int) $validated['action_plan_id']
            );
        }

        $perPage = $validated['per_page']
            ?? self::DEFAULT_PER_PAGE;

        $axes = Axis::query()
            ->whereHas(
                'actionPlan',
                function ($query) use (
                    $organization,
                    $department
                ) {
                    $query
                        ->where(
                            'organization_id',
                            $organization->id
                        )
                        ->where(
                            'department_id',
                            $department->id
                        );
                }
            )

            ->when(
                ! empty($validated['action_plan_id']),
                fn ($query) => $query->where(
                    'action_plan_id',
                    $validated['action_plan_id']
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

                    $query->where(
                        function ($subQuery) use ($search) {
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
                        }
                    );
                }
            )

            ->with([
                'actionPlan:id,name,code,organization_id,department_id',
            ])

            ->withCount([
                'projects',
                'actions',
            ])

            ->orderBy('action_plan_id')
            ->orderBy('position')
            ->orderBy('id')

            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'department' => [
                'id' => $department->id,
                'name' => $department->name,
            ],

            'axes' => $axes,
        ]);
    }

    /**
     * Store a newly created Axis.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->rules(),
            $this->validationMessages()
        );

        /*
         * Security:
         * never trust only exists:action_plans,id.
         *
         * The selected ActionPlan must belong to the
         * organization AND service of the authenticated user.
         */
        $actionPlan = $this->findScopedActionPlan(
            $request,
            (int) $data['action_plan_id']
        );

        DB::transaction(function () use (
            $data,
            $actionPlan
        ) {
            Axis::create([
                'action_plan_id' => $actionPlan->id,

                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,

                'position' => $data['position'],
                'is_active' => $data['is_active'],
            ]);
        });

        return back()->with(
            'success',
            'Axe créé avec succès.'
        );
    }

    /**
     * Display the specified Axis.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $axis = $this->findScopedAxis(
            $request,
            (int) $id
        );

        $axis->load([
            'actionPlan:id,name,code,organization_id,department_id',

            'projects',

            'actions' => function ($query) {
                $query
                    ->with([
                        'owner:id,name,email',
                    ])
                    ->orderBy('position')
                    ->orderByDesc('created_at');
            },
        ]);

        return response()->json([
            'axis' => $axis,
        ]);
    }

    /**
     * Update the specified Axis.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $axis = $this->findScopedAxis(
            $request,
            (int) $id
        );

        $data = $request->validate(
            $this->rules(true),
            $this->validationMessages()
        );

        /*
         * If the frontend moves the Axis to another ActionPlan,
         * ensure the destination ActionPlan belongs to the same
         * current organization and department.
         */
        if (array_key_exists('action_plan_id', $data)) {
            $actionPlan = $this->findScopedActionPlan(
                $request,
                (int) $data['action_plan_id']
            );

            $data['action_plan_id'] = $actionPlan->id;
        }

        DB::transaction(function () use (
            $axis,
            $data
        ) {
            $axis->update($data);
        });

        return back()->with(
            'success',
            'Axe mis à jour avec succès.'
        );
    }

    /**
     * Remove the specified Axis.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $axis = $this->findScopedAxis(
            $request,
            (int) $id
        );

        /*
         * Axis has existing relations with Projects and Actions.
         * Do not silently remove an operational structure.
         */
        $hasProjects = $axis->projects()->exists();
        $hasActions = $axis->actions()->exists();

        if ($hasProjects || $hasActions) {
            $dependencies = [];

            if ($hasProjects) {
                $dependencies[] = 'des projets';
            }

            if ($hasActions) {
                $dependencies[] = 'des actions';
            }

            throw ValidationException::withMessages([
                'axis' => 'Cet axe contient encore '
                    .implode(' et ', $dependencies)
                    .'. Supprimez ou réaffectez ces éléments avant de supprimer l’axe.',
            ]);
        }

        DB::transaction(function () use ($axis) {
            $axis->delete();
        });

        return back()->with(
            'success',
            'Axe supprimé avec succès.'
        );
    }
}
