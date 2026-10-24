<?php

namespace Tests\Feature;

use App\Models\ActionPlan;
use App\Models\Axis;
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

class TaskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_page_only_displays_tasks_from_selected_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'selected');
        [$otherOrganization, $otherDepartment, $otherStatus] = $this->createContext($user, 'other');
        $task = $this->createTask($user, $organization, $department, $status, 'Visible Task');
        $this->createTask($user, $otherOrganization, $otherDepartment, $otherStatus, 'Hidden Task');
        $actionPlan = ActionPlan::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => 'Digital Plan',
            'code' => 'PAC-DIGITAL',
            'status' => 'active',
            'is_active' => true,
        ]);
        $axis = Axis::query()->create([
            'action_plan_id' => $actionPlan->id,
            'name' => 'Automation Axis',
            'code' => 'AX-AUTO',
            'position' => 1,
            'is_active' => true,
        ]);
        Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'action_plan_id' => $actionPlan->id,
            'axis_id' => $axis->id,
            'created_by' => $user->id,
            'name' => 'Automation Project',
            'status' => 'planned',
            'priority' => 'medium',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('tasks.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Index')
            ->where('organization.id', $organization->id)
            ->where('department.id', $department->id)
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $task->id)
            ->where('tasks.data.0.title', 'Visible Task')
            ->has('projects', 1)
            ->where('projects.0.name', 'Automation Project')
            ->where('projects.0.action_plan.name', 'Digital Plan')
            ->where('projects.0.axis.name', 'Automation Axis'));
    }

    public function test_valid_payload_creates_task_and_assigns_active_department_members(): void
    {
        $user = User::factory()->create();
        $assignee = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'create');
        $assignee->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $assignee->departments()->attach($department, ['is_active' => true]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->from(route('tasks.index'))
            ->post(route('tasks.store'), [
                'title' => 'Assigned Task',
                'status_id' => $status->id,
                'priority' => 'high',
                'progress' => 20,
                'assignee_ids' => [$assignee->id],
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'title' => 'Assigned Task',
            'status_id' => $status->id,
            'priority' => 'high',
        ]);
        $task = Task::query()->where('title', 'Assigned Task')->firstOrFail();
        $this->assertDatabaseHas('task_assignees', [
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $user->id,
        ]);
    }

    public function test_creating_a_dated_task_automatically_adds_it_to_the_planning(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'automatic-planning');

        $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('tasks.store'), [
                'title' => 'Automatically planned task',
                'status_id' => $status->id,
                'priority' => 'high',
                'start_at' => '2026-11-10 09:00:00',
                'due_at' => '2026-11-10 11:00:00',
            ])
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('title', 'Automatically planned task')->firstOrFail();

        $this->assertDatabaseHas('task_plannings', [
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'task_id' => $task->id,
            'created_by' => $user->id,
            'start_at' => '2026-11-10 09:00:00',
            'end_at' => '2026-11-10 11:00:00',
            'is_automatic' => true,
        ]);
    }

    public function test_updating_task_dates_updates_the_automatic_planning_without_duplication(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'planning-update');
        $task = $this->createTask($user, $organization, $department, $status, 'Rescheduled task');
        $task->update([
            'start_at' => '2026-11-10 09:00:00',
            'due_at' => '2026-11-10 11:00:00',
        ]);
        TaskPlanning::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'task_id' => $task->id,
            'created_by' => $user->id,
            'start_at' => $task->start_at,
            'end_at' => $task->due_at,
            'is_automatic' => true,
        ]);

        $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('tasks.update', $task), [
                'start_at' => '2026-11-12 13:00:00',
                'due_at' => '2026-11-12 16:30:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_plannings', [
            'task_id' => $task->id,
            'start_at' => '2026-11-12 13:00:00',
            'end_at' => '2026-11-12 16:30:00',
            'is_automatic' => true,
        ]);
        $this->assertSame(1, $task->plannings()->where('is_automatic', true)->count());
    }

    public function test_clearing_task_dates_removes_only_its_automatic_planning(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'planning-clear');
        $task = $this->createTask($user, $organization, $department, $status, 'Unscheduled task');
        $task->update([
            'start_at' => '2026-11-10 09:00:00',
            'due_at' => '2026-11-10 11:00:00',
        ]);

        foreach ([true, false] as $isAutomatic) {
            TaskPlanning::query()->create([
                'organization_id' => $organization->id,
                'department_id' => $department->id,
                'task_id' => $task->id,
                'created_by' => $user->id,
                'start_at' => $task->start_at,
                'end_at' => $task->due_at,
                'is_automatic' => $isAutomatic,
            ]);
        }

        $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('tasks.update', $task), [
                'start_at' => null,
                'due_at' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('task_plannings', [
            'task_id' => $task->id,
            'is_automatic' => true,
        ]);
        $this->assertDatabaseHas('task_plannings', [
            'task_id' => $task->id,
            'is_automatic' => false,
        ]);
    }

    public function test_user_outside_current_department_cannot_be_assigned(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'restricted');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('tasks.store'), [
                'title' => 'Restricted Assignment',
                'status_id' => $status->id,
                'priority' => 'medium',
                'assignee_ids' => [$outsider->id],
            ]);

        $response->assertInvalid([
            'assignee_ids' => 'Un ou plusieurs responsables ne sont pas membres actifs du service actuel.',
        ]);
        $this->assertDatabaseMissing('tasks', ['title' => 'Restricted Assignment']);
    }

    public function test_invalid_priority_is_rejected(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'invalid');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->post(route('tasks.store'), [
                'title' => 'Invalid Task',
                'status_id' => $status->id,
                'priority' => 'urgent',
            ]);

        $response->assertInvalid(['priority']);
        $this->assertDatabaseMissing('tasks', ['title' => 'Invalid Task']);
    }

    public function test_task_from_another_context_returns_not_found(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'current');
        [$otherOrganization, $otherDepartment, $otherStatus] = $this->createContext($user, 'foreign');
        $task = $this->createTask($user, $otherOrganization, $otherDepartment, $otherStatus, 'Foreign Task');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->getJson(route('tasks.show', $task));

        $response->assertNotFound();
    }

    public function test_updating_assignees_replaces_the_existing_assignment(): void
    {
        $user = User::factory()->create();
        $oldAssignee = User::factory()->create();
        $newAssignee = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'assignment-update');

        foreach ([$oldAssignee, $newAssignee] as $member) {
            $member->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
            $member->departments()->attach($department, ['is_active' => true]);
        }

        $task = $this->createTask($user, $organization, $department, $status, 'Reassigned Task');
        $task->assignees()->attach($oldAssignee, [
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('tasks.update', $task), [
                'assignee_ids' => [$newAssignee->id],
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('task_assignees', [
            'task_id' => $task->id,
            'user_id' => $oldAssignee->id,
        ]);
        $this->assertDatabaseHas('task_assignees', [
            'task_id' => $task->id,
            'user_id' => $newAssignee->id,
            'assigned_by' => $user->id,
        ]);
    }

    public function test_closed_status_completes_the_task(): void
    {
        $user = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($user, 'completion');
        $closedStatus = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Terminé',
            'slug' => 'done',
            'position' => 2,
            'is_closed' => true,
            'is_active' => true,
        ]);
        $task = $this->createTask($user, $organization, $department, $status, 'Completable Task');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('tasks.update', $task), [
                'status_id' => $closedStatus->id,
            ]);

        $response->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertSame($closedStatus->id, $task->status_id);
        $this->assertSame(100, $task->progress);
        $this->assertNotNull($task->completed_at);
    }

    public function test_department_member_cannot_update_a_task_created_by_another_user(): void
    {
        $creator = User::factory()->create();
        $otherMember = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($creator, 'creator-only');
        $otherMember->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $otherMember->departments()->attach($department, ['is_active' => true]);
        $task = $this->createTask($creator, $organization, $department, $status, 'Creator task');

        $this->actingAs($otherMember)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('tasks.update', $task), [
                'title' => 'Unauthorized update',
            ])
            ->assertForbidden();

        $this->assertSame('Creator task', $task->fresh()->title);
    }

    public function test_department_member_can_update_the_status_of_a_visible_task(): void
    {
        $creator = User::factory()->create();
        $otherMember = User::factory()->create();
        [$organization, $department, $status] = $this->createContext($creator, 'status-update');
        $otherMember->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $otherMember->departments()->attach($department, ['is_active' => true]);
        $closedStatus = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Terminée',
            'slug' => 'done',
            'position' => 2,
            'is_closed' => true,
            'is_active' => true,
        ]);
        $task = $this->createTask($creator, $organization, $department, $status, 'Shared task');

        $this->actingAs($otherMember)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->patch(route('tasks.status.update', $task), [
                'status_id' => $closedStatus->id,
            ])
            ->assertSessionHasNoErrors();

        $task->refresh();

        $this->assertSame($closedStatus->id, $task->status_id);
        $this->assertSame(100, $task->progress);
        $this->assertNotNull($task->completed_at);
    }

    /**
     * @return array{Organization, Department, TaskStatus}
     */
    private function createContext(User $user, string $key): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($key).' Company',
            'slug' => $key.'-company',
        ]);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => ucfirst($key).' Department',
        ]);
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'À faire',
            'slug' => 'todo',
            'position' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true]);

        return [$organization, $department, $status];
    }

    private function createTask(
        User $user,
        Organization $organization,
        Department $department,
        TaskStatus $status,
        string $title,
    ): Task {
        return Task::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'created_by' => $user->id,
            'title' => $title,
            'priority' => 'medium',
        ]);
    }
}
