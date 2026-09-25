<?php

namespace App\Http\Controllers;

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
     * 1. Société active
     * 2. Service actif
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

        // Vérifier que la société stockée en session
        // appartient toujours à l'utilisateur.
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
        | 3. Services accessibles dans la société active
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

        // Empêche par exemple un utilisateur Finance
        // d'utiliser manuellement l'ID du service IT.
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

        /*
        | Une tâche est terminée lorsque son statut
        | possède is_closed = true.
        |
        | On ne dépend donc PAS du nom du statut.
        */

        $completedTasksCount = (clone $tasksQuery)
            ->whereHas('status', function ($query) {
                $query->where('is_closed', true);
            })
            ->count();

        /*
        | Tâches en retard :
        |
        | - date dépassée
        | - pas encore terminées
        */

        $overdueTasksCount = (clone $tasksQuery)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereDoesntHave('status', function ($query) {
                $query->where('is_closed', true);
            })
            ->count();

        /*
        |--------------------------------------------------------------------------
        | 7. Avancement global du service
        |--------------------------------------------------------------------------
        */

        $departmentProgress = $tasksCount > 0
            ? round(
                ($completedTasksCount / $tasksCount) * 100
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | 8. Mes tâches
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
        | 9. Sessions de travail
        |--------------------------------------------------------------------------
        */

        $workSessionsCount = WorkSession::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | 10. Planning du jour
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
        | 11. Membres du service
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
        | 12. Liste des projets récents
        |--------------------------------------------------------------------------
        */

        $projects = Project::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $departmentId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get([
                'id',
                'name',
                'code',
                'status',
                'priority',
                'progress',
                'start_date',
                'due_date',
            ]);

        /*
        |--------------------------------------------------------------------------
        | 13. Répartition des tâches par statut
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
        | 14. Contexte utilisateur
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

            'organizations' => $organizations->map(
                fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                ]
            )->values(),

            'departments' => $departments->map(
                fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                ]
            )->values(),
        ];

        /*
        |--------------------------------------------------------------------------
        | 15. Statistiques
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

            'department_progress' => $departmentProgress,
        ];

        /*
        |--------------------------------------------------------------------------
        | 16. Vue
        |--------------------------------------------------------------------------
        */

        return Inertia::render('Dashboard', [
            'context' => $context,

            'stats' => $stats,

            'projects' => $projects,

            'myTasks' => $myTasks,

            'todayPlanning' => $todayPlanning,

            'teamMembers' => $teamMembers,

            'tasksByStatus' => $tasksByStatus,
        ]);
    }
}