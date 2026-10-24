<?php

namespace App\Http\Controllers;

use App\Models\ActionPlan;
use App\Models\Axis;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\Task;
use App\Models\TaskPlanning;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\ProjectAccessAuthorizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ProjectAccessAuthorizer $projectAuthorizer,
    ) {}

    /**
     * Dashboard principal WorkPilot.
     *
     * Toutes les données sont filtrées par :
     * - Société active
     * - Service actif
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | 1. Organisations accessibles
        |--------------------------------------------------------------------------
        */

        $organizations = $user->organizations()
            ->wherePivot('is_active', true)
            ->orderBy('name')
            ->get();

        abort_if(
            $organizations->isEmpty(),
            403,
            'Aucune société accessible pour votre compte.'
        );

        /*
        |--------------------------------------------------------------------------
        | 2. Société active
        |--------------------------------------------------------------------------
        */

        $organizationId = session('current_organization_id');

        if (
            ! $organizationId ||
            ! $organizations->contains('id', (int) $organizationId)
        ) {
            $organizationId = $organizations->first()->id;

            session([
                'current_organization_id' => $organizationId,
            ]);
        }

        $organization = Organization::findOrFail($organizationId);

        /*
        |--------------------------------------------------------------------------
        | 3. Services accessibles
        |--------------------------------------------------------------------------
        */

        $departments = $user->departments()
            ->where('departments.organization_id', $organizationId)
            ->wherePivot('is_active', true)
            ->orderBy('departments.name')
            ->get();

        abort_if(
            $departments->isEmpty(),
            403,
            'Aucun service accessible dans cette société.'
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Service actif
        |--------------------------------------------------------------------------
        */

        $departmentId = session('current_department_id');

        if (
            ! $departmentId ||
            ! $departments->contains('id', (int) $departmentId)
        ) {
            $departmentId = $departments->first()->id;

            session([
                'current_department_id' => $departmentId,
            ]);
        }

        $department = Department::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($departmentId);

        /*
        |--------------------------------------------------------------------------
        | 5. Projets du service
        |--------------------------------------------------------------------------
        */

        $projectsQuery = Project::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId);

        $projectsCount = (clone $projectsQuery)->count();

        $activeProjectsCount = (clone $projectsQuery)
            ->where('status', 'in_progress')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | 6. Tâches du service
        |--------------------------------------------------------------------------
        */

        $tasksQuery = Task::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId);

        $tasksCount = (clone $tasksQuery)->count();

        $completedTasksCount = (clone $tasksQuery)
            ->whereHas('status', function ($query) {
                $query->where('is_closed', true);
            })
            ->count();

        $overdueTasksCount = (clone $tasksQuery)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereDoesntHave('status', function ($query) {
                $query->where('is_closed', true);
            })
            ->count();

        /*
        |--------------------------------------------------------------------------
        | 8. Plans d'action du service
        |--------------------------------------------------------------------------
        */

        $actionPlans = ActionPlan::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->withCount([
                'axes',
                'projects',
            ])
            ->with([
                'axes' => function ($query) {
                    $query
                        ->where('is_active', true)
                        ->orderBy('position');
                },
                'projects' => function ($query) {
                    $query
                        ->select(['id', 'action_plan_id', 'name', 'code', 'progress', 'status'])
                        ->withMin('actions', 'actual_start_date')
                        ->withMax('actions', 'actual_end_date')
                        ->orderBy('name');
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'code' => $plan->code,
                    'description' => $plan->description,

                    'status' => $plan->status,
                    'progress' => (int) $plan->progress,

                    'start_date' => $plan->start_date,
                    'end_date' => $plan->end_date,

                    'axes_count' => (int) $plan->axes_count,
                    'projects_count' => (int) $plan->projects_count,

                    'projects' => $plan->projects
                        ->map(function ($project) {
                            $actualStartDate = $project->actions_min_actual_start_date;
                            $actualEndDate = $project->actions_max_actual_end_date;
                            $actualDurationDays = $actualStartDate && $actualEndDate
                                ? CarbonImmutable::parse($actualStartDate)->diffInDays(CarbonImmutable::parse($actualEndDate)) + 1
                                : 0;

                            return [
                                'id' => $project->id,
                                'name' => $project->name,
                                'code' => $project->code,
                                'progress' => (int) $project->progress,
                                'status' => $project->status,
                                'actual_start_date' => $actualStartDate ? CarbonImmutable::parse($actualStartDate)->toDateString() : null,
                                'actual_end_date' => $actualEndDate ? CarbonImmutable::parse($actualEndDate)->toDateString() : null,
                                'actual_duration_days' => $actualDurationDays,
                            ];
                        })
                        ->values(),

                    'axes' => $plan->axes
                        ->map(function ($axis) {
                            return [
                                'id' => $axis->id,
                                'name' => $axis->name,
                                'code' => $axis->code,
                                'position' => $axis->position,
                            ];
                        })
                        ->values(),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | 9. Progression globale du service
        |--------------------------------------------------------------------------
        |
        | Exemple :
        |
        | Infrastructure IT     = 45%
        | Solutions Digitales   = 35%
        |
        | Service IT            = 40%
        |
        | Plus tard, cette logique pourra être remplacée par
        | une pondération automatique basée sur les projets/tâches.
        |
        */

        $serviceProgress = $actionPlans->isNotEmpty()
            ? (int) round($actionPlans->avg('progress'))
            : 0;

        /*
        |--------------------------------------------------------------------------
        | 10. Mes tâches
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | 11. Sessions de travail
        |--------------------------------------------------------------------------
        */

        $workSessionsCount = WorkSession::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | 12. Planning du jour
        |--------------------------------------------------------------------------
        */

        $todayPlanning = TaskPlanning::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->whereHas('task.assignees', fn ($query) => $query->whereKey($user->id))
            ->where('start_at', '<=', today()->endOfDay())
            ->where('end_at', '>=', today()->startOfDay())
            ->with([
                'task:id,project_id,title,status_id,priority,progress',
                'task.status:id,name,color,is_closed',
                'task.project:id,name,code',
            ])
            ->orderBy('start_at')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | 13. Membres du service
        |--------------------------------------------------------------------------
        */

        $teamMembers = User::query()
            ->whereHas('departments', function ($query) use (
                $organizationId,
                $departmentId
            ) {
                $query
                    ->where(
                        'departments.organization_id',
                        $organizationId
                    )
                    ->where(
                        'departments.id',
                        $departmentId
                    )
                    ->where(
                        'department_members.is_active',
                        true
                    );
            })
            ->with([
                'assignedTasks' => function ($query) use ($organizationId, $departmentId) {
                    $query
                        ->where('tasks.organization_id', $organizationId)
                        ->where('tasks.department_id', $departmentId)
                        ->select([
                            'tasks.id',
                            'tasks.project_id',
                            'tasks.status_id',
                            'tasks.title',
                            'tasks.priority',
                            'tasks.progress',
                            'tasks.due_at',
                        ])
                        ->orderByRaw('tasks.due_at IS NULL')
                        ->orderBy('tasks.due_at')
                        ->orderBy('tasks.title');
                },
                'assignedTasks.status:id,name,slug,color,is_closed',
                'assignedTasks.project:id,name,code',
            ])
            ->orderBy('name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
            ]);

        /*
        |--------------------------------------------------------------------------
        | 14. Projets récents
        |--------------------------------------------------------------------------
        */

        $projectFilters = $request->validate([
            'project_search' => ['nullable', 'string', 'max:255'],
            'project_status' => ['nullable', Rule::in(['draft', 'planned', 'in_progress', 'on_hold', 'completed', 'cancelled'])],
            'project_priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'project_category_id' => ['nullable', 'integer', 'exists:project_categories,id'],
            'project_sort' => ['nullable', Rule::in(['position', 'name', 'action_plan', 'axis', 'departments', 'category', 'manager', 'status', 'priority', 'progress', 'due_date', 'actions_count'])],
            'project_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $portfolioProjectsQuery = Project::query()
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query
                ->where('department_id', $departmentId)
                ->orWhereHas('departments', fn ($departmentQuery) => $departmentQuery->whereKey($departmentId)))
            ->when(! empty($projectFilters['project_search']), function ($query) use ($projectFilters): void {
                $search = trim($projectFilters['project_search']);
                $query->where(fn ($searchQuery) => $searchQuery
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%'));
            })
            ->when(! empty($projectFilters['project_status']), fn ($query) => $query->where('status', $projectFilters['project_status']))
            ->when(! empty($projectFilters['project_priority']), fn ($query) => $query->where('priority', $projectFilters['project_priority']))
            ->when(! empty($projectFilters['project_category_id']), fn ($query) => $query->where('project_category_id', $projectFilters['project_category_id']))
            ->with([
                'actionPlan:id,name,code',
                'axis:id,name,code',
                'manager:id,name,email',
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
                'actions.comments' => fn ($query) => $query
                    ->select(['id', 'action_id', 'user_id', 'content', 'created_at'])
                    ->oldest(),
                'actions.comments.user:id,name',
            ])
            ->select([
                'id',
                'organization_id',
                'department_id',
                'action_plan_id',
                'axis_id',
                'project_category_id',
                'manager_id',
                'created_by',
                'name',
                'code',
                'status',
                'priority',
                'position',
                'progress',
                'start_date',
                'due_date',
                'created_at',
            ])
            ->withCount('actions');

        if (! empty($projectFilters['project_sort'])) {
            $sortExpression = match ($projectFilters['project_sort']) {
                'action_plan' => ActionPlan::query()->select('name')->whereColumn('action_plans.id', 'projects.action_plan_id')->limit(1),
                'axis' => Axis::query()->select('name')->whereColumn('axes.id', 'projects.axis_id')->limit(1),
                'departments' => Department::query()->select('departments.name')->join('project_department', 'project_department.department_id', '=', 'departments.id')->whereColumn('project_department.project_id', 'projects.id')->orderBy('departments.name')->limit(1),
                'category' => ProjectCategory::query()->select('name')->whereColumn('project_categories.id', 'projects.project_category_id')->limit(1),
                'manager' => User::query()->select('name')->whereColumn('users.id', 'projects.manager_id')->limit(1),
                'actions_count' => 'actions_count',
                default => 'projects.'.$projectFilters['project_sort'],
            };
            $portfolioProjectsQuery
                ->orderBy($sortExpression, $projectFilters['project_direction'] ?? 'asc')
                ->orderBy('projects.id');
        } else {
            $portfolioProjectsQuery
                ->orderByRaw('CASE WHEN progress > 0 AND progress < 100 THEN 0 WHEN progress = 0 THEN 1 ELSE 2 END')
                ->orderByRaw('CASE WHEN progress > 0 AND progress < 100 THEN progress END DESC')
                ->orderBy('name');
        }

        $solutionsProjects = (clone $portfolioProjectsQuery)
            ->whereHas('actionPlan', function ($query) {
                $query->where('code', 'like', '%SOLUTIONS%');
            })
            ->paginate(10, ['*'], 'solutions_page')
            ->through(fn (Project $project) => $this->prepareDashboardProject(
                $project,
                $request,
                (int) $organizationId,
                (int) $departmentId,
            ))
            ->appends($request->except(['solutions_page', 'portfolio']))
            ->appends(['portfolio' => 'solutions']);

        $infrastructureProjects = (clone $portfolioProjectsQuery)
            ->whereHas('actionPlan', function ($query) {
                $query->where('code', 'like', '%INFRA%');
            })
            ->paginate(10, ['*'], 'infrastructure_page')
            ->through(fn (Project $project) => $this->prepareDashboardProject(
                $project,
                $request,
                (int) $organizationId,
                (int) $departmentId,
            ))
            ->appends($request->except(['infrastructure_page', 'portfolio']))
            ->appends(['portfolio' => 'infrastructure']);

        $portfolioTab = in_array($request->string('portfolio')->toString(), ['solutions', 'infrastructure'], true)
            ? $request->string('portfolio')->toString()
            : 'solutions';

        /*
        |--------------------------------------------------------------------------
        | 16. Contexte WorkPilot
        |--------------------------------------------------------------------------
        */

        $context = [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
            ],

            /*
            | Sociétés accessibles par l'utilisateur.
            | Utilisé plus tard pour le sélecteur société.
            */

            'organizations' => $organizations
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                    ];
                })
                ->values(),

            /*
            | Services accessibles dans la société actuelle.
            */

            'departments' => $departments
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'code' => $item->code,
                    ];
                })
                ->values(),
        ];

        /*
        |--------------------------------------------------------------------------
        | 17. Statistiques Dashboard
        |--------------------------------------------------------------------------
        */

        $stats = [
            'projects' => $projectsCount,

            'active_projects' => $activeProjectsCount,

            'tasks' => $tasksCount,

            'completed_tasks' => $completedTasksCount,

            'overdue_tasks' => $overdueTasksCount,

            'work_sessions' => $workSessionsCount,

            'team_members' => $teamMembers->count(),

            /*
            | Progression globale principale du service :
            | moyenne des plans d'action actifs.
            */

            'department_progress' => $serviceProgress,

            'action_plans' => $actionPlans->count(),
        ];

        /*
        |--------------------------------------------------------------------------
        | 18. Dashboard
        |--------------------------------------------------------------------------
        */

        return Inertia::render('Dashboard', [
            'context' => $context,

            'stats' => $stats,

            'actionPlans' => $actionPlans,

            'projectPortfolios' => [
                'solutions' => $solutionsProjects,
                'infrastructure' => $infrastructureProjects,
            ],

            'portfolioTab' => $portfolioTab,

            'projectFilters' => $projectFilters,

            'projectCategories' => ProjectCategory::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->with('manager:id,name,email')
                ->orderBy('name')
                ->get(['id', 'organization_id', 'manager_id', 'name', 'slug']),

            'projectStatuses' => ['draft', 'planned', 'in_progress', 'on_hold', 'completed', 'cancelled'],

            'projectPriorities' => ['low', 'medium', 'high', 'critical'],

            'todayPlanning' => $todayPlanning,

            'teamMembers' => $teamMembers,
        ]);
    }

    private function prepareDashboardProject(
        Project $project,
        Request $request,
        int $organizationId,
        int $departmentId,
    ): Project {
        $project->setAttribute('can_manage', $this->projectAuthorizer->canManageProject(
            $request->user(),
            $project,
            $organizationId,
            $departmentId,
        ));
        $project->setAttribute('can_manage_actions', $this->projectAuthorizer->canManageProjectActions(
            $request->user(),
            $project,
            $organizationId,
            $departmentId,
        ));

        $project->actions->each(function ($action) use ($request): void {
            $action->comments->each(function ($comment) use ($request): void {
                $comment->setAttribute(
                    'can_edit',
                    (int) $comment->user_id === (int) $request->user()->id,
                );
            });
        });

        return $project;
    }
}
