<?php

namespace App\Http\Controllers;

use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectWorkflowService;
use App\Support\ProjectAccessAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    private const STATUSES = [
        'draft',
        'planned',
        'in_progress',
        'on_hold',
        'completed',
        'cancelled',
    ];

    private const PRIORITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    public function __construct(
        private readonly ProjectAccessAuthorizer $projectAuthorizer,
        private readonly ProjectWorkflowService $workflowService,
    ) {}

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
     * Resolve the active department/service.
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
     * Resolve current WorkPilot context.
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
     * Find a project strictly inside the current context.
     */
    private function findScopedProject(
        Request $request,
        int $projectId
    ): Project {
        $context = $this->getContext($request);

        return Project::query()
            ->whereKey($projectId)
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(fn ($query) => $query
                ->where('department_id', $context['department']->id)
                ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                    ->whereKey($context['department']->id)))
            ->firstOrFail();
    }

    /**
     * Find an ActionPlan belonging to the current context.
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
     * Find an Axis belonging to the current context.
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
     * Find a user who actively belongs to
     * the current organization and department.
     */
    private function findScopedUser(
        Request $request,
        int $userId
    ): User {
        $context = $this->getContext($request);

        return User::query()
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
            )
            ->whereHas(
                'departments',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'departments.id',
                            $context['department']->id
                        )
                        ->where('department_members.is_active', true);
                }
            )
            ->firstOrFail();
    }

    /**
     * Validate ActionPlan / Axis consistency.
     */
    private function resolvePlanningStructure(
        Request $request,
        ?int $actionPlanId,
        ?int $axisId
    ): array {
        $actionPlan = null;
        $axis = null;

        if ($actionPlanId !== null) {
            $actionPlan = $this->findScopedActionPlan(
                $request,
                $actionPlanId
            );
        }

        if ($axisId !== null) {
            $axis = $this->findScopedAxis(
                $request,
                $axisId
            );

            /*
             * If an axis is selected, it belongs to
             * exactly one ActionPlan.
             */
            if (
                $actionPlan !== null
                && (int) $axis->action_plan_id
                !== (int) $actionPlan->id
            ) {
                throw ValidationException::withMessages([
                    'axis_id' => 'L’axe sélectionné n’appartient pas au plan d’action sélectionné.',
                ]);
            }

            /*
             * If frontend sends only axis_id,
             * infer its ActionPlan automatically.
             */
            if ($actionPlan === null) {
                $actionPlan = $this->findScopedActionPlan(
                    $request,
                    (int) $axis->action_plan_id
                );
            }
        }

        return [
            'action_plan' => $actionPlan,
            'axis' => $axis,
        ];
    }

    /**
     * @param  array<int, int|string>  $departmentIds
     * @return array<int, int>
     */
    private function validateProjectDepartments(array $departmentIds, Organization $organization): array
    {
        $ids = collect($departmentIds)
            ->map(fn (int|string $departmentId): int => (int) $departmentId)
            ->unique()
            ->values();

        $validIds = Department::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', $ids)
            ->pluck('id');

        if ($validIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'department_ids' => 'Tous les services du projet doivent appartenir à la société actuelle.',
            ]);
        }

        return $validIds->map(fn (int $departmentId): int => $departmentId)->all();
    }

    private function resolveProjectCategory(
        Organization $organization,
        User $fallbackManager,
        ?int $categoryId
    ): ProjectCategory {
        if ($categoryId !== null) {
            return ProjectCategory::query()
                ->whereKey($categoryId)
                ->whereBelongsTo($organization)
                ->where('is_active', true)
                ->firstOrFail();
        }

        return ProjectCategory::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'autre',
            ],
            [
                'manager_id' => $fallbackManager->id,
                'name' => 'Autre',
                'is_active' => true,
            ],
        );
    }

    /**
     * Display projects of the current service.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'action_plan_id' => [
                'nullable',
                'integer',
                'exists:action_plans,id',
            ],

            'axis_id' => [
                'nullable',
                'integer',
                'exists:axes,id',
            ],

            'manager_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'portfolio' => [
                'nullable',
                Rule::in(['solutions', 'infrastructure']),
            ],

            'status' => [
                'nullable',
                Rule::in(self::STATUSES),
            ],

            'priority' => [
                'nullable',
                Rule::in(self::PRIORITIES),
            ],

            'project_category_id' => [
                'nullable',
                'integer',
                'exists:project_categories,id',
            ],

            'sort' => [
                'nullable',
                Rule::in([
                    'name',
                    'position',
                    'action_plan',
                    'axis',
                    'departments',
                    'category',
                    'manager',
                    'status',
                    'priority',
                    'progress',
                    'due_date',
                    'actions_count',
                ]),
            ],

            'direction' => [
                'nullable',
                Rule::in(['asc', 'desc']),
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'is_private' => [
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

            'create_project' => [
                'nullable',
                'boolean',
            ],
        ]);

        if (! empty($validated['action_plan_id'])) {
            $this->findScopedActionPlan(
                $request,
                (int) $validated['action_plan_id']
            );
        }

        if (! empty($validated['axis_id'])) {
            $this->findScopedAxis(
                $request,
                (int) $validated['axis_id']
            );
        }

        if (! empty($validated['manager_id'])) {
            $this->findScopedUser(
                $request,
                (int) $validated['manager_id']
            );
        }

        if (! empty($validated['project_category_id'])) {
            $this->resolveProjectCategory(
                $context['organization'],
                $request->user(),
                (int) $validated['project_category_id'],
            );
        }

        $portfolio = $validated['portfolio'] ?? 'solutions';
        $portfolioCode = $portfolio === 'infrastructure' ? 'INFRA' : 'SOLUTIONS';

        $projects = Project::query()
            ->where(
                'organization_id',
                $context['organization']->id
            )
            ->where(fn ($query) => $query
                ->where('department_id', $context['department']->id)
                ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                    ->whereKey($context['department']->id)))
            ->whereHas('actionPlan', fn ($query) => $query->where('code', 'like', '%'.$portfolioCode.'%'))

            ->when(
                ! empty($validated['action_plan_id']),
                fn ($query) => $query->where(
                    'action_plan_id',
                    $validated['action_plan_id']
                )
            )

            ->when(
                ! empty($validated['axis_id']),
                fn ($query) => $query->where(
                    'axis_id',
                    $validated['axis_id']
                )
            )

            ->when(
                ! empty($validated['manager_id']),
                fn ($query) => $query->where(
                    'manager_id',
                    $validated['manager_id']
                )
            )

            ->when(
                ! empty($validated['status']),
                fn ($query) => $query->where(
                    'status',
                    $validated['status']
                )
            )

            ->when(
                ! empty($validated['priority']),
                fn ($query) => $query->where(
                    'priority',
                    $validated['priority']
                )
            )

            ->when(
                ! empty($validated['project_category_id']),
                fn ($query) => $query->where(
                    'project_category_id',
                    $validated['project_category_id']
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
                array_key_exists('is_private', $validated),
                fn ($query) => $query->where(
                    'is_private',
                    $validated['is_private']
                )
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

            ->with([
                'manager:id,name,email',
                'creator:id,name,email',
                'actionPlan:id,name,code',
                'axis:id,action_plan_id,name,code',
                'category:id,organization_id,manager_id,name,slug',
                'category.manager:id,name,email',
                'departments:id,name,code',
            ])

            ->withCount([
                'members',
                'actions',
                'tasks',
                'workSessions',
                'attachments',
            ]);

        $sort = $validated['sort'] ?? 'position';
        $direction = $validated['direction'] ?? 'asc';

        $sortExpression = match ($sort) {
            'action_plan' => ActionPlan::query()
                ->select('name')
                ->whereColumn('action_plans.id', 'projects.action_plan_id')
                ->limit(1),
            'axis' => Axis::query()
                ->select('name')
                ->whereColumn('axes.id', 'projects.axis_id')
                ->limit(1),
            'departments' => Department::query()
                ->select('departments.name')
                ->join('project_department', 'project_department.department_id', '=', 'departments.id')
                ->whereColumn('project_department.project_id', 'projects.id')
                ->orderBy('departments.name')
                ->limit(1),
            'category' => ProjectCategory::query()
                ->select('name')
                ->whereColumn('project_categories.id', 'projects.project_category_id')
                ->limit(1),
            'manager' => User::query()
                ->select('name')
                ->whereColumn('users.id', 'projects.manager_id')
                ->limit(1),
            'actions_count' => 'actions_count',
            default => 'projects.'.$sort,
        };

        $projects = $projects
            ->orderBy($sortExpression, $direction)
            ->orderBy('projects.id')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        $projects->through(function (Project $project) use ($request, $context): Project {
            $project->setAttribute('can_manage', $this->projectAuthorizer->canManageProject(
                $request->user(),
                $project,
                $context['organization']->id,
                $context['department']->id,
            ));

            return $project;
        });

        $payload = [
            'organization' => [
                'id' => $context['organization']->id,
                'name' => $context['organization']->name,
            ],

            'department' => [
                'id' => $context['department']->id,
                'name' => $context['department']->name,
            ],

            'projects' => $projects,
            'filters' => [
                ...$validated,
                'portfolio' => $portfolio,
            ],
            'portfolioCounts' => [
                'solutions' => Project::query()
                    ->where('organization_id', $context['organization']->id)
                    ->where(fn ($query) => $query
                        ->where('department_id', $context['department']->id)
                        ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                            ->whereKey($context['department']->id)))
                    ->whereHas('actionPlan', fn ($query) => $query->where('code', 'like', '%SOLUTIONS%'))
                    ->count(),
                'infrastructure' => Project::query()
                    ->where('organization_id', $context['organization']->id)
                    ->where(fn ($query) => $query
                        ->where('department_id', $context['department']->id)
                        ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                            ->whereKey($context['department']->id)))
                    ->whereHas('actionPlan', fn ($query) => $query->where('code', 'like', '%INFRA%'))
                    ->count(),
            ],
            'canCreateProjects' => $this->projectAuthorizer->canCreateProject(
                $request->user(),
                $context['organization']->id,
                $context['department']->id,
            ),
            'openCreateProject' => (bool) ($validated['create_project'] ?? false),
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Projects/Index', [
            ...$payload,
            'projectWorkspace' => Project::query()
                ->where('organization_id', $context['organization']->id)
                ->where(fn ($query) => $query
                    ->where('department_id', $context['department']->id)
                    ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                        ->whereKey($context['department']->id)))
                ->where('is_active', true)
                ->where(function ($query) use ($portfolioCode, $validated) {
                    $query->whereHas('actionPlan', fn ($planQuery) => $planQuery->where('code', 'like', '%'.$portfolioCode.'%'));

                    if (! empty($validated['action_plan_id'])) {
                        $query->orWhere('action_plan_id', $validated['action_plan_id']);
                    }
                })
                ->with([
                    'actionPlan:id,name,code',
                    'axis:id,action_plan_id,name,code',
                    'category:id,organization_id,manager_id,name,slug',
                    'category.manager:id,name,email',
                    'departments:id,name,code',
                    'actions' => fn ($query) => $query
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
                        ->orderBy('position')
                        ->orderBy('id'),
                ])
                ->withCount('actions')
                ->orderBy('action_plan_id')
                ->orderBy('axis_id')
                ->orderBy('position')
                ->orderBy('name')
                ->get([
                    'id',
                    'action_plan_id',
                    'axis_id',
                    'project_category_id',
                    'manager_id',
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
                ->map(function (Project $project) use ($request, $context): Project {
                    $project->setAttribute('can_manage', $this->projectAuthorizer->canManageProject(
                        $request->user(),
                        $project,
                        $context['organization']->id,
                        $context['department']->id,
                    ));

                    return $project;
                }),
            'actionPlans' => ActionPlan::query()
                ->where('organization_id', $context['organization']->id)
                ->where('department_id', $context['department']->id)
                ->where('is_active', true)
                ->where(function ($query) use ($portfolioCode, $validated) {
                    $query->where('code', 'like', '%'.$portfolioCode.'%');

                    if (! empty($validated['action_plan_id'])) {
                        $query->orWhereKey($validated['action_plan_id']);
                    }
                })
                ->with(['axes' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('position')])
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'managers' => $context['department']->users()
                ->wherePivot('is_active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email']),
            'departments' => Department::query()
                ->where('organization_id', $context['organization']->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'projectCategories' => ProjectCategory::query()
                ->where('organization_id', $context['organization']->id)
                ->where('is_active', true)
                ->with('manager:id,name,email')
                ->orderBy('name')
                ->get(['id', 'organization_id', 'manager_id', 'name', 'slug']),
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
        ]);
    }

    /**
     * Store a new project.
     */
    public function store(Request $request): RedirectResponse
    {
        $context = $this->getContext($request);
        $this->projectAuthorizer->authorizeProjectCreation(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        $data = $request->validate([
            'action_plan_id' => [
                'nullable',
                'integer',
                'exists:action_plans,id',
            ],

            'axis_id' => [
                'nullable',
                'integer',
                'exists:axes,id',
            ],

            'manager_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'project_category_id' => [
                'nullable',
                'integer',
                'exists:project_categories,id',
            ],

            'department_ids' => [
                'sometimes',
                'required',
                'array',
                'min:2',
            ],

            'department_ids.*' => [
                'integer',
                'distinct',
                'exists:departments,id',
            ],

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

            'start_date' => [
                'nullable',
                'date',
            ],

            'due_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'status' => [
                'required',
                Rule::in(self::STATUSES),
            ],

            'priority' => [
                'required',
                Rule::in(self::PRIORITIES),
            ],

            'position' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'is_private' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'creation_token' => [
                'nullable',
                'uuid',
            ],
        ], [
            'name.required' => 'Le nom du projet est obligatoire.',

            'due_date.after_or_equal' => 'La date d’échéance doit être postérieure ou égale à la date de début.',

            'status.required' => 'Le statut du projet est obligatoire.',

            'priority.required' => 'La priorité du projet est obligatoire.',
        ]);

        $structure = $this->resolvePlanningStructure(
            $request,
            isset($data['action_plan_id'])
                ? (int) $data['action_plan_id']
                : null,
            isset($data['axis_id'])
                ? (int) $data['axis_id']
                : null
        );

        $manager = null;

        $category = $this->resolveProjectCategory(
            $context['organization'],
            $request->user(),
            isset($data['project_category_id']) ? (int) $data['project_category_id'] : null,
        );

        $departmentIds = $this->validateProjectDepartments(
            $data['department_ids'] ?? [$context['department']->id],
            $context['organization'],
        );

        $position = $data['position'] ?? ((int) Project::query()
            ->where('organization_id', $context['organization']->id)
            ->where('action_plan_id', $structure['action_plan']?->id)
            ->where('axis_id', $structure['axis']?->id)
            ->max('position')) + 1;

        if (! empty($data['manager_id'])) {
            $manager = $this->findScopedUser(
                $request,
                (int) $data['manager_id']
            );
        }

        DB::transaction(function () use (
            $request,
            $context,
            $data,
            $structure,
            $manager,
            $departmentIds,
            $category,
            $position,
        ) {
            $existingProject = ! empty($data['creation_token'])
                ? Project::query()
                    ->where('organization_id', $context['organization']->id)
                    ->where('creation_token', $data['creation_token'])
                    ->first()
                : null;

            if ($existingProject) {
                return;
            }

            $project = Project::create([
                'organization_id' => $context['organization']->id,

                'department_id' => $context['department']->id,

                'action_plan_id' => $structure['action_plan']?->id,

                'axis_id' => $structure['axis']?->id,

                'project_category_id' => $category->id,

                'manager_id' => $manager?->id,

                /*
                 * created_by is always controlled by backend.
                 */
                'created_by' => $request->user()->id,
                'creation_token' => $data['creation_token'] ?? null,

                'name' => trim($data['name']),

                'code' => isset($data['code'])
                    && trim($data['code']) !== ''
                    ? trim($data['code'])
                    : null,

                'description' => $data['description'] ?? null,

                'start_date' => $data['start_date'] ?? null,

                'due_date' => $data['due_date'] ?? null,

                'status' => $data['status'],

                'priority' => $data['priority'],

                'position' => $position,

                'progress' => 0,

                'is_private' => (bool) ($data['is_private'] ?? false),

                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $project->departments()->sync($departmentIds);

            $this->workflowService->createDefaultActions($project, $request->user());
        });

        return back()->with(
            'success',
            'Projet créé avec succès.'
        );
    }

    /**
     * Display one project.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $project = $this->findScopedProject(
            $request,
            (int) $id
        );

        $project->load([
            'organization:id,name',
            'department:id,name,code',
            'departments:id,name,code',

            'actionPlan:id,name,code',
            'axis:id,action_plan_id,name,code',
            'category:id,organization_id,manager_id,name,slug',
            'category.manager:id,name,email',

            'manager:id,name,email',
            'creator:id,name,email',

            'members' => function ($query) {
                $query
                    ->select([
                        'users.id',
                        'users.name',
                        'users.email',
                    ])
                    ->orderBy('users.name');
            },
        ]);

        $project->loadCount([
            'members',
            'actions',
            'tasks',
            'workSessions',
            'attachments',
        ]);

        return response()->json([
            'project' => $project,
        ]);
    }

    public function duplicate(Request $request, string $id): RedirectResponse
    {
        $project = $this->findScopedProject($request, (int) $id);
        $context = $this->getContext($request);
        $this->projectAuthorizer->authorizeProjectManagement(
            $request->user(),
            $project,
            $context['organization']->id,
            $context['department']->id,
        );

        $copy = DB::transaction(fn (): Project => $this->workflowService->duplicateProject(
            $project,
            $request->user(),
        ));

        return back()->with([
            'success' => 'Projet dupliqué avec succès.',
            'open_project_id' => $copy->id,
        ]);
    }

    /**
     * Update a project.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $context = $this->getContext($request);
        $project = $this->findScopedProject(
            $request,
            (int) $id
        );
        $this->projectAuthorizer->authorizeProjectManagement(
            $request->user(),
            $project,
            $context['organization']->id,
            $context['department']->id,
        );

        $data = $request->validate([
            'action_plan_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:action_plans,id',
            ],

            'axis_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:axes,id',
            ],

            'manager_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'project_category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:project_categories,id',
            ],

            'department_ids' => [
                'sometimes',
                'required',
                'array',
                'min:2',
            ],

            'department_ids.*' => [
                'integer',
                'distinct',
                'exists:departments,id',
            ],

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

            'start_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'due_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(self::STATUSES),
            ],

            'priority' => [
                'sometimes',
                'required',
                Rule::in(self::PRIORITIES),
            ],

            'position' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],

            'is_private' => [
                'sometimes',
                'boolean',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        /*
         * Resolve the final ActionPlan / Axis combination,
         * not only the fields sent by the frontend.
         */
        $finalActionPlanId = array_key_exists(
            'action_plan_id',
            $data
        )
            ? (
                $data['action_plan_id'] !== null
                ? (int) $data['action_plan_id']
                : null
            )
            : (
                $project->action_plan_id !== null
                ? (int) $project->action_plan_id
                : null
            );

        $finalAxisId = array_key_exists(
            'axis_id',
            $data
        )
            ? (
                $data['axis_id'] !== null
                ? (int) $data['axis_id']
                : null
            )
            : (
                $project->axis_id !== null
                ? (int) $project->axis_id
                : null
            );

        /*
         * If ActionPlan is explicitly cleared,
         * Axis must also be cleared unless another Axis
         * was explicitly supplied.
         */
        if (
            array_key_exists('action_plan_id', $data)
            && $data['action_plan_id'] === null
            && ! array_key_exists('axis_id', $data)
        ) {
            $finalAxisId = null;
            $data['axis_id'] = null;
        }

        $structure = $this->resolvePlanningStructure(
            $request,
            $finalActionPlanId,
            $finalAxisId
        );

        if (
            array_key_exists('action_plan_id', $data)
            || array_key_exists('axis_id', $data)
        ) {
            $data['action_plan_id'] =
                $structure['action_plan']?->id;

            $data['axis_id'] =
                $structure['axis']?->id;
        }

        if (array_key_exists('manager_id', $data)) {
            if ($data['manager_id'] === null) {
                $data['manager_id'] = null;
            } else {
                $manager = $this->findScopedUser(
                    $request,
                    (int) $data['manager_id']
                );

                $data['manager_id'] = $manager->id;
            }
        }

        if (array_key_exists('project_category_id', $data)) {
            $data['project_category_id'] = $this->resolveProjectCategory(
                $context['organization'],
                $request->user(),
                (int) $data['project_category_id'],
            )->id;
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] =
                $data['code'] !== null
                && trim($data['code']) !== ''
                ? trim($data['code'])
                : null;
        }

        /*
         * Validate final dates after merging existing
         * values with incoming values.
         */
        $finalStartDate = array_key_exists(
            'start_date',
            $data
        )
            ? $data['start_date']
            : $project->start_date?->format('Y-m-d');

        $finalDueDate = array_key_exists(
            'due_date',
            $data
        )
            ? $data['due_date']
            : $project->due_date?->format('Y-m-d');

        if (
            $finalStartDate !== null
            && $finalDueDate !== null
            && strtotime($finalDueDate)
            < strtotime($finalStartDate)
        ) {
            throw ValidationException::withMessages([
                'due_date' => 'La date d’échéance doit être postérieure ou égale à la date de début.',
            ]);
        }

        $departmentIds = array_key_exists('department_ids', $data)
            ? $this->validateProjectDepartments($data['department_ids'], $context['organization'])
            : null;
        unset($data['department_ids']);

        DB::transaction(function () use (
            $project,
            $data,
            $departmentIds,
        ) {
            $project->update($data);

            if ($departmentIds !== null) {
                $project->departments()->sync($departmentIds);
            }
        });

        return back()->with(
            'success',
            'Projet mis à jour avec succès.'
        );
    }

    /**
     * Persist the priority order selected in the projects table.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $context = $this->getContext($request);
        $this->projectAuthorizer->authorizeProjectCreation(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        $validated = $request->validate([
            'project_category_id' => ['required', 'integer', 'exists:project_categories,id'],
            'positions' => ['required', 'array', 'min:1', 'max:100'],
            'positions.*.id' => ['required', 'integer', 'distinct:strict', 'exists:projects,id'],
            'positions.*.position' => ['required', 'integer', 'min:1', 'distinct:strict'],
        ]);

        $category = $this->resolveProjectCategory(
            $context['organization'],
            $request->user(),
            (int) $validated['project_category_id'],
        );

        DB::transaction(function () use ($validated, $context, $category) {
            $projectIds = collect($validated['positions'])
                ->pluck('id')
                ->map(fn ($projectId) => (int) $projectId);

            $projects = Project::query()
                ->whereIn('id', $projectIds)
                ->where('organization_id', $context['organization']->id)
                ->where('project_category_id', $category->id)
                ->where(fn ($query) => $query
                    ->where('department_id', $context['department']->id)
                    ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery
                        ->whereKey($context['department']->id)))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_if($projects->count() !== $projectIds->count(), 404);

            foreach ($validated['positions'] as $item) {
                $projects->get((int) $item['id'])->update([
                    'position' => (int) $item['position'],
                ]);
            }
        }, attempts: 3);

        return back()->with('success', 'Priorité des projets mise à jour.');
    }

    /**
     * Delete a project.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $project = $this->findScopedProject(
            $request,
            (int) $id
        );
        $context = $this->getContext($request);
        $this->projectAuthorizer->authorizeProjectManagement(
            $request->user(),
            $project,
            $context['organization']->id,
            $context['department']->id,
        );

        $dependencies = [];

        if ($project->projectMembers()->exists()) {
            $dependencies[] = 'des membres';
        }

        if ($project->actions()->exists()) {
            $dependencies[] = 'des actions';
        }

        if ($project->tasks()->exists()) {
            $dependencies[] = 'des tâches';
        }

        if ($project->workSessions()->exists()) {
            $dependencies[] = 'des sessions de travail';
        }

        if ($project->attachments()->exists()) {
            $dependencies[] = 'des pièces jointes';
        }

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'project' => 'Ce projet contient encore '
                    .implode(', ', $dependencies)
                    .'. Supprimez ou réaffectez ces éléments avant de supprimer le projet.',
            ]);
        }

        DB::transaction(function () use ($project) {
            $project->delete();
        });

        return back()->with(
            'success',
            'Projet supprimé avec succès.'
        );
    }
}
