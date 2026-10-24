<?php

namespace App\Http\Controllers;

use App\Models\ActionPlan;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Resolve the current WorkPilot context from session.
     *
     * This intentionally follows the same context logic
     * already used by DashboardController.
     *
     * @return array{
     *     organization: Organization,
     *     department: Department
     * }
     */
    private function getContext(Request $request): array
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Accessible organizations
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
        | Current organization
        |--------------------------------------------------------------------------
        */

        $organizationId = session('current_organization_id');

        if (
            ! $organizationId
            || ! $organizations->contains(
                'id',
                (int) $organizationId
            )
        ) {
            $organizationId = $organizations->first()->id;

            session([
                'current_organization_id' => $organizationId,
            ]);
        }

        $organization = Organization::query()
            ->findOrFail($organizationId);

        /*
        |--------------------------------------------------------------------------
        | Accessible departments
        |--------------------------------------------------------------------------
        */

        $departments = $user->departments()
            ->where(
                'departments.organization_id',
                $organization->id
            )
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
        | Current department
        |--------------------------------------------------------------------------
        */

        $departmentId = session('current_department_id');

        if (
            ! $departmentId
            || ! $departments->contains(
                'id',
                (int) $departmentId
            )
        ) {
            $departmentId = $departments->first()->id;

            session([
                'current_department_id' => $departmentId,
            ]);
        }

        $department = Department::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->findOrFail($departmentId);

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    /**
     * Global report for the current service.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->getContext($request);

        $organizationId =
            $context['organization']->id;

        $departmentId =
            $context['department']->id;

        /*
        |--------------------------------------------------------------------------
        | Projects
        |--------------------------------------------------------------------------
        */

        $projectsQuery = Project::query()
            ->where(
                'organization_id',
                $organizationId
            )
            ->where(
                'department_id',
                $departmentId
            );

        $projectsCount =
            (clone $projectsQuery)->count();

        $activeProjectsCount =
            (clone $projectsQuery)
                ->where('status', 'in_progress')
                ->count();

        $averageProjectProgress =
            (clone $projectsQuery)
                ->where('is_active', true)
                ->avg('progress');

        /*
        |--------------------------------------------------------------------------
        | Tasks
        |--------------------------------------------------------------------------
        */

        $tasksQuery = Task::query()
            ->where(
                'organization_id',
                $organizationId
            )
            ->where(
                'department_id',
                $departmentId
            );

        $tasksCount =
            (clone $tasksQuery)->count();

        $completedTasksCount =
            (clone $tasksQuery)
                ->whereHas(
                    'status',
                    function ($query) {
                        $query->where(
                            'is_closed',
                            true
                        );
                    }
                )
                ->count();

        $openTasksCount =
            (clone $tasksQuery)
                ->whereDoesntHave(
                    'status',
                    function ($query) {
                        $query->where(
                            'is_closed',
                            true
                        );
                    }
                )
                ->count();

        $overdueTasksCount =
            (clone $tasksQuery)
                ->whereNotNull('due_at')
                ->where(
                    'due_at',
                    '<',
                    now()
                )
                ->whereDoesntHave(
                    'status',
                    function ($query) {
                        $query->where(
                            'is_closed',
                            true
                        );
                    }
                )
                ->count();

        $tasksProgress =
            $tasksCount > 0
            ? (int) round(
                (
                    $completedTasksCount
                    / $tasksCount
                ) * 100
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Action plans
        |--------------------------------------------------------------------------
        */

        $actionPlans = ActionPlan::query()
            ->where(
                'organization_id',
                $organizationId
            )
            ->where(
                'department_id',
                $departmentId
            )
            ->where(
                'is_active',
                true
            )
            ->withCount([
                'axes',
                'projects',
            ])
            ->orderBy('name')
            ->get();

        $actionPlansCount =
            $actionPlans->count();

        $serviceProgress =
            $actionPlans->isNotEmpty()
            ? (int) round(
                $actionPlans->avg('progress')
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Work sessions
        |--------------------------------------------------------------------------
        */

        $workSessionsQuery =
            WorkSession::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'department_id',
                    $departmentId
                );

        $workSessionsCount =
            (clone $workSessionsQuery)->count();

        /*
        |--------------------------------------------------------------------------
        | Team
        |--------------------------------------------------------------------------
        */

        $teamMembersCount =
            User::query()
                ->whereHas(
                    'departments',
                    function ($query) use (
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
                    }
                )
                ->count();

        /*
        |--------------------------------------------------------------------------
        | Tasks by status
        |--------------------------------------------------------------------------
        */

        $tasksByStatus = Task::query()
            ->where(
                'tasks.organization_id',
                $organizationId
            )
            ->where(
                'tasks.department_id',
                $departmentId
            )
            ->join(
                'task_statuses',
                'task_statuses.id',
                '=',
                'tasks.status_id'
            )
            ->selectRaw(
                '
                task_statuses.id,
                task_statuses.name,
                task_statuses.slug,
                task_statuses.color,
                task_statuses.is_closed,
                COUNT(tasks.id) as total
                '
            )
            ->groupBy(
                'task_statuses.id',
                'task_statuses.name',
                'task_statuses.slug',
                'task_statuses.color',
                'task_statuses.is_closed'
            )
            ->orderBy(
                'task_statuses.position'
            )
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Projects by status
        |--------------------------------------------------------------------------
        */

        $projectsByStatus =
            Project::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'department_id',
                    $departmentId
                )
                ->selectRaw(
                    'status, COUNT(*) as total'
                )
                ->groupBy('status')
                ->orderBy('status')
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Projects by priority
        |--------------------------------------------------------------------------
        */

        $projectsByPriority =
            Project::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'department_id',
                    $departmentId
                )
                ->selectRaw(
                    'priority, COUNT(*) as total'
                )
                ->groupBy('priority')
                ->orderBy('priority')
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Action plan report
        |--------------------------------------------------------------------------
        */

        $actionPlanReport =
            $actionPlans
                ->map(function ($plan) {
                    return [
                        'id' => $plan->id,

                        'name' => $plan->name,

                        'code' => $plan->code,

                        'status' => $plan->status,

                        'progress' => (int) $plan->progress,

                        'start_date' => $plan->start_date,

                        'end_date' => $plan->end_date,

                        'axes_count' => (int) $plan->axes_count,

                        'projects_count' => (int) $plan->projects_count,
                    ];
                })
                ->values();

        /*
        |--------------------------------------------------------------------------
        | Recent projects
        |--------------------------------------------------------------------------
        */

        $recentProjects =
            Project::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'department_id',
                    $departmentId
                )
                ->with([
                    'manager:id,name,email',
                    'actionPlan:id,name,code',
                    'axis:id,name,code',
                ])
                ->latest('created_at')
                ->limit(10)
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Overdue tasks
        |--------------------------------------------------------------------------
        */

        $overdueTasks =
            Task::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'department_id',
                    $departmentId
                )
                ->whereNotNull('due_at')
                ->where(
                    'due_at',
                    '<',
                    now()
                )
                ->whereDoesntHave(
                    'status',
                    function ($query) {
                        $query->where(
                            'is_closed',
                            true
                        );
                    }
                )
                ->with([
                    'status:id,name,slug,color,is_closed',
                    'project:id,name,code',
                    'assignees:id,name,email',
                ])
                ->orderBy('due_at')
                ->limit(20)
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'context' => [
                'organization' => [
                    'id' => $context['organization']->id,

                    'name' => $context['organization']->name,
                ],

                'department' => [
                    'id' => $context['department']->id,

                    'name' => $context['department']->name,

                    'code' => $context['department']->code,
                ],
            ],

            'summary' => [
                'action_plans' => $actionPlansCount,

                'projects' => $projectsCount,

                'active_projects' => $activeProjectsCount,

                'average_project_progress' => (int) round(
                    $averageProjectProgress ?? 0
                ),

                'tasks' => $tasksCount,

                'open_tasks' => $openTasksCount,

                'completed_tasks' => $completedTasksCount,

                'overdue_tasks' => $overdueTasksCount,

                'tasks_progress' => $tasksProgress,

                /*
                 * Main service progress:
                 * average progress of active action plans.
                 */
                'service_progress' => $serviceProgress,

                'work_sessions' => $workSessionsCount,

                'team_members' => $teamMembersCount,
            ],

            'action_plans' => $actionPlanReport,

            'tasks_by_status' => $tasksByStatus,

            'projects_by_status' => $projectsByStatus,

            'projects_by_priority' => $projectsByPriority,

            'recent_projects' => $recentProjects,

            'overdue_tasks' => $overdueTasks,
        ]);
    }
}
