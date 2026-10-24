<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionComment;
use App\Models\ActionPlan;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPlanning;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_portfolio_includes_actions_comments_and_management_permission(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $plan = $this->createActionPlan($user, $organization, $department, 'PAC-SOLUTIONS-MODAL');
        $project = $this->createProject($user, $organization, $department, $plan, 'Modal Project', 35);
        $project->update(['manager_id' => $user->id]);
        $action = Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'title' => 'Action visible',
            'status' => 'in_progress',
            'priority' => 'high',
            'progress' => 45,
            'position' => 1,
        ]);
        $comment = ActionComment::query()->create([
            'action_id' => $action->id,
            'user_id' => $user->id,
            'content' => 'Commentaire visible dans le modal.',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('projectPortfolios.solutions.data.0.id', $project->id)
            ->where('projectPortfolios.solutions.data.0.can_manage', true)
            ->where('projectPortfolios.solutions.data.0.can_manage_actions', true)
            ->where('projectPortfolios.solutions.data.0.actions.0.id', $action->id)
            ->where('projectPortfolios.solutions.data.0.actions.0.comments.0.id', $comment->id)
            ->where('projectPortfolios.solutions.data.0.actions.0.comments.0.can_edit', true)
            ->where('projectPortfolios.solutions.data.0.actions.0.comments.0.user.name', $user->name));
    }

    public function test_dashboard_separates_and_orders_project_portfolios_by_progress(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $solutionsPlan = $this->createActionPlan($user, $organization, $department, 'PAC-SOLUTIONS-26-27');
        $infrastructurePlan = $this->createActionPlan($user, $organization, $department, 'PAC-INFRA-26-27');

        $this->createProject($user, $organization, $department, $solutionsPlan, 'Solutions 30', 30);
        $this->createProject($user, $organization, $department, $solutionsPlan, 'Solutions complete', 100);
        $this->createProject($user, $organization, $department, $solutionsPlan, 'Solutions zero', 0);
        $this->createProject($user, $organization, $department, $solutionsPlan, 'Solutions 80', 80);
        $infrastructureProject = $this->createProject($user, $organization, $department, $infrastructurePlan, 'Infrastructure 60', 60);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('portfolioTab', 'solutions')
            ->has('projectPortfolios.solutions.data', 4)
            ->where('projectPortfolios.solutions.data.0.name', 'Solutions 80')
            ->where('projectPortfolios.solutions.data.1.name', 'Solutions 30')
            ->where('projectPortfolios.solutions.data.2.name', 'Solutions zero')
            ->where('projectPortfolios.solutions.data.3.name', 'Solutions complete')
            ->has('projectPortfolios.infrastructure.data', 1)
            ->where('projectPortfolios.infrastructure.data.0.id', $infrastructureProject->id));
    }

    public function test_action_plans_include_project_actual_durations_for_charts(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $plan = $this->createActionPlan($user, $organization, $department, 'PAC-INFRA-CHART');
        $secondProject = $this->createProject($user, $organization, $department, $plan, 'Beta Project', 65);
        $firstProject = $this->createProject($user, $organization, $department, $plan, 'Alpha Project', 25);
        Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $firstProject->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'title' => 'Alpha first action',
            'actual_start_date' => '2026-10-01',
            'actual_end_date' => '2026-10-05',
            'status' => 'completed',
            'priority' => 'medium',
            'progress' => 100,
        ]);
        Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $firstProject->id,
            'action_plan_id' => $plan->id,
            'created_by' => $user->id,
            'title' => 'Alpha last action',
            'actual_start_date' => '2026-10-06',
            'actual_end_date' => '2026-10-10',
            'status' => 'completed',
            'priority' => 'medium',
            'progress' => 100,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('actionPlans', 1)
            ->where('actionPlans.0.id', $plan->id)
            ->has('actionPlans.0.projects', 2)
            ->where('actionPlans.0.projects.0.id', $firstProject->id)
            ->where('actionPlans.0.projects.0.progress', 100)
            ->where('actionPlans.0.projects.0.actual_start_date', '2026-10-01')
            ->where('actionPlans.0.projects.0.actual_end_date', '2026-10-10')
            ->where('actionPlans.0.projects.0.actual_duration_days', 10)
            ->where('actionPlans.0.projects.1.id', $secondProject->id)
            ->where('actionPlans.0.projects.1.progress', 65)
            ->where('actionPlans.0.projects.1.actual_duration_days', 0));
    }

    public function test_each_project_portfolio_has_independent_pagination(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $infrastructurePlan = $this->createActionPlan($user, $organization, $department, 'PAC-INFRA-26-27');

        foreach (range(1, 11) as $position) {
            $this->createProject($user, $organization, $department, $infrastructurePlan, 'Infrastructure '.str_pad((string) $position, 2, '0', STR_PAD_LEFT), 0);
        }

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard', [
                'portfolio' => 'infrastructure',
                'infrastructure_page' => 2,
            ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('portfolioTab', 'infrastructure')
            ->where('projectPortfolios.infrastructure.current_page', 2)
            ->where('projectPortfolios.infrastructure.per_page', 10)
            ->where('projectPortfolios.infrastructure.total', 11)
            ->has('projectPortfolios.infrastructure.data', 1));
    }

    public function test_project_portfolio_can_be_filtered_and_sorted_like_the_projects_page(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $plan = $this->createActionPlan($user, $organization, $department, 'PAC-SOLUTIONS-FILTERS');
        $laterProject = $this->createProject($user, $organization, $department, $plan, 'Matching Later', 20);
        $firstProject = $this->createProject($user, $organization, $department, $plan, 'Matching First', 10);
        $this->createProject($user, $organization, $department, $plan, 'Excluded Project', 30);
        $laterProject->update(['priority' => 'high', 'position' => 2]);
        $firstProject->update(['priority' => 'high', 'position' => 1]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard', [
                'project_search' => 'Matching',
                'project_status' => 'in_progress',
                'project_priority' => 'high',
                'project_sort' => 'position',
                'project_direction' => 'asc',
            ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('projectFilters.project_search', 'Matching')
            ->where('projectFilters.project_sort', 'position')
            ->has('projectPortfolios.solutions.data', 2)
            ->where('projectPortfolios.solutions.data.0.id', $firstProject->id)
            ->where('projectPortfolios.solutions.data.1.id', $laterProject->id)
            ->has('projectStatuses', 6)
            ->has('projectPriorities', 4));
    }

    public function test_team_members_include_their_department_tasks_with_progress_and_status(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $teamMember = User::factory()->create(['name' => '000 Assigned Member']);
        $teamMember->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $teamMember->departments()->attach($department, ['is_active' => true]);
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'En cours',
            'slug' => 'in-progress',
            'color' => '#f59e0b',
            'position' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);
        $task = Task::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'created_by' => $user->id,
            'title' => 'Prepare director presentation',
            'priority' => 'high',
            'progress' => 60,
        ]);
        $task->assignees()->attach($teamMember, [
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers.0.id', $teamMember->id)
            ->has('teamMembers.0.assigned_tasks', 1)
            ->where('teamMembers.0.assigned_tasks.0.id', $task->id)
            ->where('teamMembers.0.assigned_tasks.0.progress', 60)
            ->where('teamMembers.0.assigned_tasks.0.status.name', 'En cours'));
    }

    public function test_today_planning_only_contains_tasks_assigned_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        [$organization, $department] = $this->createContext($user);
        $otherUser->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $otherUser->departments()->attach($department, ['is_active' => true]);
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Planifiée',
            'slug' => 'planned-dashboard',
            'is_default' => true,
            'is_active' => true,
        ]);
        $assignedTask = Task::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'created_by' => $user->id,
            'title' => 'Ma tâche planifiée',
            'priority' => 'medium',
            'progress' => 25,
        ]);
        $otherTask = Task::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'created_by' => $otherUser->id,
            'title' => 'Tâche d’un autre utilisateur',
            'priority' => 'medium',
            'progress' => 10,
        ]);
        $assignedTask->assignees()->attach($user, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $otherTask->assignees()->attach($otherUser, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $myPlanning = TaskPlanning::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'task_id' => $assignedTask->id,
            'created_by' => $user->id,
            'start_at' => today()->setTime(9, 0),
            'end_at' => today()->setTime(11, 0),
        ]);
        TaskPlanning::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'task_id' => $otherTask->id,
            'created_by' => $otherUser->id,
            'start_at' => today()->setTime(10, 0),
            'end_at' => today()->setTime(12, 0),
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('todayPlanning', 1)
            ->where('todayPlanning.0.id', $myPlanning->id)
            ->where('todayPlanning.0.task.id', $assignedTask->id)
            ->where('todayPlanning.0.task.title', 'Ma tâche planifiée'));
    }

    /**
     * @return array{Organization, Department}
     */
    private function createContext(User $user): array
    {
        $organization = Organization::query()->create([
            'name' => 'Dashboard Company',
            'slug' => 'dashboard-company',
        ]);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Dashboard Department',
        ]);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true]);

        return [$organization, $department];
    }

    private function createActionPlan(User $user, Organization $organization, Department $department, string $code): ActionPlan
    {
        return ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => $code,
            'code' => $code,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function createProject(User $user, Organization $organization, Department $department, ActionPlan $actionPlan, string $name, int $progress): Project
    {
        return Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'action_plan_id' => $actionPlan->id,
            'created_by' => $user->id,
            'name' => $name,
            'status' => $progress === 100 ? 'completed' : 'in_progress',
            'priority' => 'medium',
            'progress' => $progress,
        ]);
    }

    /**
     * @return array{current_organization_id: int, current_department_id: int}
     */
    private function contextSession(Organization $organization, Department $department): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];
    }
}
