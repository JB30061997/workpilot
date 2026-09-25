<?php

namespace App\Http\Controllers;

use App\Models\ActionPlan;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
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
            !$organizationId ||
            !$organizations->contains('id', (int) $organizationId)
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
            !$departmentId ||
            !$departments->contains('id', (int) $departmentId)
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
        | 7. Progression calculée depuis les tâches
        |--------------------------------------------------------------------------
        |
        | On garde cette statistique disponible.
        | Le progress global principal du service sera basé sur
        | les plans d'action actifs.
        |
        */

        $tasksProgress = $tasksCount > 0
            ? (int) round(
                ($completedTasksCount / $tasksCount) * 100
            )
            : 0;

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

        $myTasks = Task::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->whereHas('assignees', function ($query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->with([
                'status:id,name,slug,color,is_closed',
                'project:id,name,code',
            ])
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->limit(10)
            ->get();

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

        $todayPlanning = TimeSlot::query()
            ->whereHas('workSession', function ($query) use (
                $organizationId,
                $departmentId
            ) {
                $query
                    ->where('organization_id', $organizationId)
                    ->where('department_id', $departmentId);
            })
            ->whereDate('start_at', today())
            ->with([
                'task:id,title,status_id,priority',
                'task.status:id,name,color,is_closed',
                'members:id,name,email',
                'workSession:id,name',
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

        $projects = Project::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->with([
                'actionPlan:id,name,code',
                'axis:id,name,code',
            ])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get([
                'id',
                'organization_id',
                'department_id',
                'action_plan_id',
                'axis_id',
                'name',
                'code',
                'status',
                'priority',
                'progress',
                'start_date',
                'due_date',
                'created_at',
            ]);

        /*
        |--------------------------------------------------------------------------
        | 15. Répartition des tâches par statut
        |--------------------------------------------------------------------------
        */

        $tasksByStatus = Task::query()
            ->where('tasks.organization_id', $organizationId)
            ->where('tasks.department_id', $departmentId)
            ->join(
                'task_statuses',
                'task_statuses.id',
                '=',
                'tasks.status_id'
            )
            ->selectRaw('
                task_statuses.id,
                task_statuses.name,
                task_statuses.slug,
                task_statuses.color,
                task_statuses.is_closed,
                COUNT(tasks.id) as total
            ')
            ->groupBy(
                'task_statuses.id',
                'task_statuses.name',
                'task_statuses.slug',
                'task_statuses.color',
                'task_statuses.is_closed'
            )
            ->orderBy('task_statuses.position')
            ->get();

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

            /*
            | Progression secondaire :
            | basée uniquement sur les tâches terminées.
            */

            'tasks_progress' => $tasksProgress,

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

            'projects' => $projects,

            'myTasks' => $myTasks,

            'todayPlanning' => $todayPlanning,

            'teamMembers' => $teamMembers,

            'tasksByStatus' => $tasksByStatus,
        ]);
    }
}