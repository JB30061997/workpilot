<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectChatAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_director_can_access_every_project_chat_in_their_department(): void
    {
        $director = User::factory()->create();
        [$organization, $directedDepartment, $otherDepartment] = $this->organizationWithDepartments();
        $this->assignDepartmentDirector($director, $organization, $directedDepartment);
        $accessibleProject = $this->createProject($organization, $directedDepartment, $director, 'Accessible Chat');
        $this->createProject($organization, $otherDepartment, $director, 'Other Department Chat');

        $this->actingAs($director)
            ->get(route('project-chat.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectChat/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $accessibleProject->id));

        $this->actingAs($director)
            ->getJson(route('project-chat.show', $accessibleProject))
            ->assertOk()
            ->assertJsonPath('project.id', $accessibleProject->id);
    }

    public function test_director_general_cannot_access_project_chat_without_membership(): void
    {
        $directorGeneral = User::factory()->create();
        [$organization, $department] = $this->organizationWithDepartments();
        $directorGeneral->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $directorGeneral->departments()->attach($department, ['is_manager' => true, 'is_active' => true]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directeur Général',
            'slug' => 'director',
            'is_active' => true,
        ]);
        $directorGeneral->roles()->attach($role, ['organization_id' => $organization->id]);
        $project = $this->createProject($organization, $department, $directorGeneral, 'Protected Chat');

        $this->actingAs($directorGeneral)
            ->getJson(route('project-chat.show', $project))
            ->assertForbidden();
    }

    /** @return array{Organization, Department, Department} */
    private function organizationWithDepartments(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Chat Authorization Organization',
            'slug' => 'chat-authorization-organization',
        ]);
        $directedDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directed Department',
        ]);
        $otherDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Other Department',
        ]);

        return [$organization, $directedDepartment, $otherDepartment];
    }

    private function assignDepartmentDirector(
        User $user,
        Organization $organization,
        Department $department,
    ): void {
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_manager' => true, 'is_active' => true]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directeur de département',
            'slug' => 'department-manager',
            'is_active' => true,
        ]);
        $user->roles()->attach($role, ['organization_id' => $organization->id]);
    }

    private function createProject(
        Organization $organization,
        Department $department,
        User $creator,
        string $name,
    ): Project {
        return Project::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $creator->id,
            'name' => $name,
        ]);
    }
}
