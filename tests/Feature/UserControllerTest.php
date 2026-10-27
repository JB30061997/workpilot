<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_page_renders_members_of_the_current_department(): void
    {
        $director = User::factory()->create();
        [$organization, $department] = $this->createContext($director);

        $response = $this
            ->actingAs($director)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('users.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Users/Index')
            ->where('organization.id', $organization->id)
            ->where('department.id', $department->id)
            ->where('canManageUsers', true)
            ->has('users.data', 1)
            ->has('departments', 1)
            ->has('roles'));
    }

    public function test_director_can_update_a_users_department_role_and_job_title(): void
    {
        $director = User::factory()->create();
        [$organization, $department] = $this->createContext($director);
        $otherDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance',
        ]);
        $oldRole = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Collaborateur',
            'slug' => 'collaborator',
            'is_active' => true,
        ]);
        $newRole = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Chef de projet',
            'slug' => 'project-manager',
            'is_active' => true,
        ]);
        $member = User::factory()->create();
        $member->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $member->departments()->attach($department, [
            'is_primary' => true,
            'is_active' => true,
        ]);
        $member->roles()->attach($oldRole, ['organization_id' => $organization->id]);

        $response = $this
            ->actingAs($director)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('users.update', $member), [
                'name' => 'Updated Member',
                'email' => $member->email,
                'department_id' => $otherDepartment->id,
                'role_id' => $newRole->id,
                'job_title' => 'Responsable finance',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Updated Member']);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'job_title' => 'Responsable finance',
        ]);
        $this->assertDatabaseMissing('department_members', [
            'department_id' => $department->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('department_members', [
            'department_id' => $otherDepartment->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('user_roles', [
            'role_id' => $oldRole->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'organization_id' => $organization->id,
            'role_id' => $newRole->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_non_director_cannot_create_a_user(): void
    {
        $member = User::factory()->create();
        [$organization, $department] = $this->createContext($member, false);

        $response = $this
            ->actingAs($member)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('users.store'), [
                'name' => 'Blocked User',
                'email' => 'blocked@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'department_id' => $department->id,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_valid_payload_creates_user_with_organization_department_and_role_assignments(): void
    {
        $director = User::factory()->create();
        [$organization, $department] = $this->createContext($director);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Project Manager',
            'slug' => 'project-manager',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($director)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('users.store'), [
                'name' => 'New Member',
                'email' => 'member@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'department_id' => $department->id,
                'role_id' => $role->id,
                'job_title' => 'Chef de projet',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $user = User::query()->where('email', 'member@example.test')->firstOrFail();
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'job_title' => 'Chef de projet',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('department_members', [
            'department_id' => $department->id,
            'user_id' => $user->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_director_can_assign_a_management_role_to_multiple_departments(): void
    {
        $director = User::factory()->create();
        [$organization, $department] = $this->createContext($director);
        $finance = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance',
        ]);
        $humanResources = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Ressources Humaines',
        ]);
        $managerRole = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directeur de département',
            'slug' => 'department-manager',
            'is_active' => true,
        ]);
        $member = User::factory()->create();
        $member->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $member->departments()->attach($department, ['is_primary' => true, 'is_active' => true]);

        $response = $this
            ->actingAs($director)
            ->withSession($this->contextSession($organization, $department))
            ->patch(route('users.update', $member), [
                'role_id' => $managerRole->id,
                'department_ids' => [$finance->id, $humanResources->id],
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseMissing('department_members', [
            'department_id' => $department->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('department_members', [
            'department_id' => $finance->id,
            'user_id' => $member->id,
            'is_manager' => true,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('department_members', [
            'department_id' => $humanResources->id,
            'user_id' => $member->id,
            'is_manager' => true,
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role_id' => $managerRole->id,
        ]);
    }

    /**
     * @return array{Organization, Department}
     */
    private function createContext(User $user, bool $isDirector = true): array
    {
        $organization = Organization::query()->create([
            'name' => 'WorkPilot Company',
            'slug' => 'workpilot-company',
        ]);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'IT',
        ]);
        $user->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $user->departments()->attach($department, [
            'is_manager' => $isDirector,
            'is_primary' => true,
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => $isDirector ? 'Directeur' : 'Collaborateur',
            'slug' => $isDirector ? 'director' : 'collaborator',
            'is_active' => true,
        ]);
        $user->roles()->attach($role, ['organization_id' => $organization->id]);

        return [$organization, $department];
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
