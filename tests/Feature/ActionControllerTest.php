<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_can_be_created_inside_a_project_with_a_position(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'create-action');
        $project = $this->createProject($user, $organization, $department, 'Project X');

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('actions.store'), [
                'project_id' => $project->id,
                'title' => 'First project action',
                'status' => 'not_started',
                'priority' => 'high',
                'progress' => 0,
                'position' => 1,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('actions', [
            'project_id' => $project->id,
            'title' => 'First project action',
            'position' => 1,
        ]);
    }

    public function test_project_action_can_be_updated_and_reordered(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'update-action');
        $project = $this->createProject($user, $organization, $department, 'Project X');
        $action = $this->createAction($user, $organization, $project, 'Before update', 1);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('actions.update', $action), [
                'title' => 'After update',
                'progress' => 60,
                'position' => 3,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'title' => 'After update',
            'progress' => 60,
            'position' => 3,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'progress' => 60,
        ]);
    }

    public function test_project_action_can_be_duplicated_immediately_after_original(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'duplicate-action');
        $project = $this->createProject($user, $organization, $department, 'Project X');
        $action = $this->createAction($user, $organization, $project, 'Original action', 1);
        $action->update([
            'description' => 'Description copied',
            'owner_id' => $user->id,
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-10',
            'status' => 'completed',
            'progress' => 100,
        ]);
        $this->createAction($user, $organization, $project, 'Following action', 2);

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('actions.duplicate', $action));

        $response->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('actions', [
            'project_id' => $project->id,
            'title' => 'Original action (Copie)',
            'description' => 'Description copied',
            'owner_id' => null,
            'start_date' => null,
            'due_date' => null,
            'status' => 'not_started',
            'progress' => 0,
            'position' => 2,
        ]);
        $this->assertDatabaseHas('actions', [
            'project_id' => $project->id,
            'title' => 'Following action',
            'position' => 3,
        ]);
    }

    public function test_project_progress_is_the_rounded_average_of_its_actions(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'average-progress');
        $project = $this->createProject($user, $organization, $department, 'Average Project');
        $firstAction = $this->createAction($user, $organization, $project, 'First action', 1);
        $secondAction = $this->createAction($user, $organization, $project, 'Second action', 2);
        $firstAction->update(['progress' => 20]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('actions.update', $secondAction), ['progress' => 81]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'progress' => 51,
        ]);
    }

    public function test_project_action_without_tasks_can_be_deleted(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'delete-action');
        $project = $this->createProject($user, $organization, $department, 'Project X');
        $action = $this->createAction($user, $organization, $project, 'Delete me', 1);
        $remainingAction = $this->createAction($user, $organization, $project, 'Keep me', 2);
        $action->update(['progress' => 20]);
        $remainingAction->update(['progress' => 80]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->delete(route('actions.destroy', $action));

        $response->assertSessionHasNoErrors();
        $this->assertModelMissing($action);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'progress' => 80,
        ]);
    }

    public function test_action_cannot_be_created_for_a_project_from_another_context(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'current-action');
        [$otherOrganization, $otherDepartment] = $this->createContext($user, 'restricted-action');
        $project = $this->createProject($user, $otherOrganization, $otherDepartment, 'Restricted Project');

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('actions.store'), [
                'project_id' => $project->id,
                'title' => 'Unauthorized action',
                'status' => 'not_started',
                'priority' => 'medium',
                'progress' => 0,
                'position' => 1,
            ]);

        $response->assertInvalid(['project_id']);
        $this->assertDatabaseMissing('actions', ['title' => 'Unauthorized action']);
    }

    public function test_assigned_project_manager_can_update_project_actions(): void
    {
        $director = User::factory()->create();
        $manager = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'manager-action');
        $manager->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $manager->departments()->attach($department, ['is_active' => true]);
        $project = $this->createProject($director, $organization, $department, 'Managed Project');
        $project->update(['manager_id' => $manager->id]);
        $action = $this->createAction($director, $organization, $project, 'Managed Action', 1);

        $response = $this
            ->actingAs($manager)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('actions.update', $action), ['title' => 'Manager Updated Action']);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'title' => 'Manager Updated Action',
        ]);
    }

    public function test_starred_department_project_responsible_can_update_action_dates(): void
    {
        $director = User::factory()->create();
        $responsible = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'starred-project-responsible');
        $responsible->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $responsible->departments()->attach($department, ['is_active' => true]);
        $project = $this->createProject($director, $organization, $department, '9 Box Project');
        $project->departmentResponsibles()->create([
            'department_id' => $department->id,
            'user_id' => $responsible->id,
        ]);
        $project->members()->attach($responsible, [
            'role' => 'manager',
            'joined_at' => now(),
        ]);
        $action = $this->createAction($director, $organization, $project, 'Planning 9 Box', 1);

        $response = $this
            ->actingAs($responsible)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('actions.update', $action), [
                'start_date' => '2027-01-04',
                'due_date' => '2027-01-15',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'start_date' => '2027-01-04 00:00:00',
            'due_date' => '2027-01-15 00:00:00',
        ]);
    }

    public function test_project_creator_can_update_actions_without_being_the_director_or_manager(): void
    {
        $director = User::factory()->create();
        $creator = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'creator-action');
        $creator->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $creator->departments()->attach($department, ['is_active' => true]);
        $project = $this->createProject($creator, $organization, $department, 'Creator Project');
        $action = $this->createAction($director, $organization, $project, 'Creator Action', 1);

        $response = $this
            ->actingAs($creator)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('actions.update', $action), ['title' => 'Updated by Creator', 'progress' => 70]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'title' => 'Updated by Creator',
            'progress' => 70,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'progress' => 70,
        ]);
    }

    public function test_regular_member_cannot_modify_project_actions(): void
    {
        $director = User::factory()->create();
        $member = User::factory()->create();
        [$organization, $department] = $this->createContext($director, 'restricted-action-write');
        $member->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $member->departments()->attach($department, ['is_active' => true]);
        $project = $this->createProject($director, $organization, $department, 'Protected Project');
        $action = $this->createAction($director, $organization, $project, 'Protected Action', 1);

        $response = $this
            ->actingAs($member)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('actions.update', $action), ['title' => 'Forbidden Update']);

        $response->assertForbidden();
        $this->assertDatabaseHas('actions', ['id' => $action->id, 'title' => 'Protected Action']);
    }

    /**
     * @return array{Organization, Department}
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
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_manager' => true, 'is_active' => true]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directeur',
            'slug' => 'director',
            'is_active' => true,
        ]);
        $user->roles()->attach($role, ['organization_id' => $organization->id]);

        return [$organization, $department];
    }

    private function createProject(
        User $user,
        Organization $organization,
        Department $department,
        string $name,
    ): Project {
        return Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => $name,
            'status' => 'planned',
            'priority' => 'medium',
        ]);
    }

    private function createAction(
        User $user,
        Organization $organization,
        Project $project,
        string $title,
        int $position,
    ): Action {
        return Action::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => $title,
            'status' => 'not_started',
            'priority' => 'medium',
            'progress' => 0,
            'position' => $position,
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
