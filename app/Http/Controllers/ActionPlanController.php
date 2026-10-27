<?php

namespace App\Http\Controllers;

use App\Models\ActionPlan;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Services\UniqueCodeGenerator;
use App\Services\WorkContextDepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ActionPlanController extends Controller
{
    private const STATUSES = [
        'draft',
        'active',
        'completed',
        'archived',
    ];

    public function __construct(
        private readonly UniqueCodeGenerator $codeGenerator,
        private readonly WorkContextDepartmentService $departmentAccess,
    ) {}

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
     * Resolve the active department/service of the authenticated user
     * inside the current organization.
     */
    private function getCurrentDepartment(
        Request $request,
        Organization $organization
    ): Department {
        $departments = $this->departmentAccess
            ->accessibleDepartments($request->user(), $organization->id);
        $department = session('current_department_id')
            ? $departments->firstWhere('id', (int) session('current_department_id'))
            : $departments->first();

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
     * Resolve the complete WorkPilot context.
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
     * Find an ActionPlan strictly inside the current
     * organization and department.
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

    private function findScopedPlanningYear(Organization $organization, int $planningYearId): PlanningYear
    {
        return PlanningYear::query()
            ->whereKey($planningYearId)
            ->where('organization_id', $organization->id)
            ->firstOrFail();
    }

    /**
     * Validation rules used when creating/updating an ActionPlan.
     */
    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:255',
            ],

            'planning_year_id' => [
                'nullable',
                'integer',
                'exists:planning_years,id',
            ],

            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'start_date' => [
                'nullable',
                'date',
            ],

            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'status' => [
                $required,
                Rule::in(self::STATUSES),
            ],

            'progress' => [
                $required,
                'integer',
                'between:0,100',
            ],

            'is_active' => [
                $required,
                'boolean',
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    private function validationMessages(): array
    {
        return [
            'name.required' => 'Le nom du plan d’action est obligatoire.',

            'name.max' => 'Le nom du plan d’action ne peut pas dépasser 255 caractères.',

            'code.max' => 'Le code ne peut pas dépasser 255 caractères.',

            'start_date.date' => 'La date de début est invalide.',

            'end_date.date' => 'La date de fin est invalide.',

            'end_date.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',

            'status.required' => 'Le statut du plan d’action est obligatoire.',

            'status.in' => 'Le statut sélectionné est invalide.',

            'progress.required' => 'La progression est obligatoire.',

            'progress.integer' => 'La progression doit être un nombre entier.',

            'progress.between' => 'La progression doit être comprise entre 0 et 100.',

            'is_active.required' => 'L’état actif du plan d’action est obligatoire.',

            'is_active.boolean' => 'La valeur du champ actif est invalide.',
        ];
    }

    /**
     * Display ActionPlans belonging only to
     * the current organization and department.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'planning_year_id' => ['nullable', 'integer', 'exists:planning_years,id'],
            'is_active' => ['nullable', 'boolean'],
            'open_plan' => ['nullable', 'integer'],
        ]);

        if (! empty($filters['planning_year_id'])) {
            $this->findScopedPlanningYear($organization, (int) $filters['planning_year_id']);
        }

        $currentPlanningYear = PlanningYear::resolveForOrganization($organization->id, now());
        $planningYears = PlanningYear::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('start_date')
            ->get(['id', 'label', 'start_date', 'end_date']);

        $actionPlans = ActionPlan::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->where(
                'department_id',
                $department->id
            )
            ->with([
                'creator:id,name,email',
                'planningYear:id,label,start_date,end_date',
                'axes' => fn ($query) => $query
                    ->withCount(['projects', 'actions'])
                    ->orderBy('position')
                    ->orderBy('id'),
                'projects' => fn ($query) => $query
                    ->select([
                        'id',
                        'action_plan_id',
                        'axis_id',
                        'project_category_id',
                        'name',
                        'code',
                        'description',
                        'start_date',
                        'due_date',
                        'status',
                        'priority',
                        'position',
                        'progress',
                    ])
                    ->with([
                        'axis:id,action_plan_id,name,code',
                        'category:id,manager_id,name,slug',
                        'category.manager:id,name,email',
                        'departments:id,name,code',
                        'actions' => fn ($actionQuery) => $actionQuery
                            ->select([
                                'id',
                                'project_id',
                                'title',
                                'description',
                                'start_date',
                                'due_date',
                                'actual_start_date',
                                'actual_end_date',
                                'status',
                                'priority',
                                'progress',
                                'position',
                            ])
                            ->with([
                                'comments' => fn ($commentQuery) => $commentQuery
                                    ->select(['id', 'action_id', 'user_id', 'content', 'created_at'])
                                    ->with('user:id,name')
                                    ->oldest('created_at')
                                    ->oldest('id'),
                            ])
                            ->orderBy('position')
                            ->orderBy('id'),
                    ])
                    ->withCount('actions')
                    ->orderBy('axis_id')
                    ->orderBy('position')
                    ->orderBy('name'),
            ])
            ->withCount([
                'axes',
                'projects',
                'actions',
            ])
            ->when(
                ! empty($filters['search']),
                function ($query) use ($filters) {
                    $search = trim($filters['search']);

                    $query->where(function ($searchQuery) use ($search) {
                        $searchQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('code', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%');
                    });
                }
            )
            ->when(
                ! empty($filters['status']),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                ! empty($filters['planning_year_id']),
                fn ($query) => $query->where('planning_year_id', $filters['planning_year_id'])
            )
            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $payload = [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'department' => [
                'id' => $department->id,
                'name' => $department->name,
            ],

            'action_plans' => $actionPlans,
            'filters' => $filters,
            'planningYears' => $planningYears,
            'currentPlanningYearId' => $currentPlanningYear->id,
            'openActionPlanId' => isset($filters['open_plan']) ? (int) $filters['open_plan'] : null,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('ActionPlans/Index', [
            ...$payload,
            'statuses' => self::STATUSES,
        ]);
    }

    /**
     * Store a newly created ActionPlan.
     */
    public function store(Request $request): RedirectResponse
    {
        $context = $this->getContext($request);

        $organization = $context['organization'];
        $department = $context['department'];

        $data = $request->validate(
            $this->rules(),
            $this->validationMessages()
        );

        $planningYear = ! empty($data['planning_year_id'])
            ? $this->findScopedPlanningYear($organization, (int) $data['planning_year_id'])
            : PlanningYear::resolveForOrganization($organization->id, now());

        $actionPlan = DB::transaction(function () use (
            $data,
            $organization,
            $department,
            $planningYear,
            $request
        ) {
            return ActionPlan::create([
                'organization_id' => $organization->id,
                'department_id' => $department->id,
                'planning_year_id' => $planningYear->id,
                'created_by' => $request->user()->id,

                'name' => $data['name'],
                'code' => $this->codeGenerator->forActionPlan($data['name'], $organization->id),
                'description' => $data['description'] ?? null,

                'start_date' => $planningYear->start_date,
                'end_date' => $planningYear->end_date,

                'status' => $data['status'],
                'progress' => $data['progress'],
                'is_active' => $data['is_active'],
            ]);
        });

        return redirect()->route('action-plans.index', [
            'open_plan' => $actionPlan->id,
        ])->with('success', 'Plan d’action créé avec succès. Vous pouvez maintenant ajouter un projet.');
    }

    /**
     * Display one ActionPlan with its existing relationships.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $actionPlan = $this->findScopedActionPlan(
            $request,
            (int) $id
        );

        $actionPlan->load([
            'organization',
            'department',
            'creator:id,name,email',

            'axes' => function ($query) {
                $query->orderBy('position');
            },

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
            'action_plan' => $actionPlan,
        ]);
    }

    /**
     * Update the specified ActionPlan.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $actionPlan = $this->findScopedActionPlan(
            $request,
            (int) $id
        );

        $data = $request->validate(
            $this->rules(true),
            $this->validationMessages()
        );

        if (array_key_exists('planning_year_id', $data) && $data['planning_year_id'] !== null) {
            $planningYear = $this->findScopedPlanningYear(
                $actionPlan->organization,
                (int) $data['planning_year_id']
            );
            $data['planning_year_id'] = $planningYear->id;
            $data['start_date'] = $planningYear->start_date->toDateString();
            $data['end_date'] = $planningYear->end_date->toDateString();
        }

        /*
         * If only end_date is updated, Laravel's after_or_equal:start_date
         * rule cannot compare it against the existing database value.
         * We therefore protect this case explicitly.
         */
        $finalStartDate = array_key_exists('start_date', $data)
            ? $data['start_date']
            : $actionPlan->start_date?->format('Y-m-d');

        $finalEndDate = array_key_exists('end_date', $data)
            ? $data['end_date']
            : $actionPlan->end_date?->format('Y-m-d');

        if (
            $finalStartDate
            && $finalEndDate
            && $finalEndDate < $finalStartDate
        ) {
            throw ValidationException::withMessages([
                'end_date' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]);
        }

        DB::transaction(function () use (
            $actionPlan,
            $data
        ) {
            $actionPlan->update($data);
        });

        return back()->with(
            'success',
            'Plan d’action mis à jour avec succès.'
        );
    }

    /**
     * Remove the specified ActionPlan.
     *
     * A plan containing operational data cannot be deleted silently.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $actionPlan = $this->findScopedActionPlan(
            $request,
            (int) $id
        );

        $hasAxes = $actionPlan->axes()->exists();
        $hasProjects = $actionPlan->projects()->exists();
        $hasActions = $actionPlan->actions()->exists();

        if ($hasAxes || $hasProjects || $hasActions) {
            $dependencies = [];

            if ($hasAxes) {
                $dependencies[] = 'des axes';
            }

            if ($hasProjects) {
                $dependencies[] = 'des projets';
            }

            if ($hasActions) {
                $dependencies[] = 'des actions';
            }

            throw ValidationException::withMessages([
                'action_plan' => 'Ce plan d’action contient encore '
                    .implode(', ', $dependencies)
                    .'. Supprimez ou réaffectez ces éléments avant de supprimer le plan.',
            ]);
        }

        DB::transaction(function () use ($actionPlan) {
            $actionPlan->delete();
        });

        return back()->with(
            'success',
            'Plan d’action supprimé avec succès.'
        );
    }
}
