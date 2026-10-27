<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentContextControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $response = $this->patch(route('context.update'), [
            'organization_id' => 1,
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_select_an_accessible_organization_and_department(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createAccessibleContext($user);

        $response = $this
            ->actingAs($user)
            ->from(route('dashboard'))
            ->patch(route('context.update'), [
                'organization_id' => $organization->id,
                'department_id' => $department->id,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('current_organization_id', $organization->id)
            ->assertSessionHas('current_department_id', $department->id)
            ->assertRedirect(route('dashboard'));
    }

    public function test_user_cannot_select_an_inaccessible_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Other Company',
            'slug' => 'other-company',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('context.update'), [
                'organization_id' => $organization->id,
            ]);

        $response->assertInvalid([
            'organization_id' => 'La société sélectionnée n’est pas accessible.',
        ]);
        $response->assertSessionMissing('current_organization_id');
    }

    public function test_user_cannot_select_a_department_from_another_organization(): void
    {
        $user = User::factory()->create();
        [$organization] = $this->createAccessibleContext($user);
        $otherOrganization = Organization::query()->create([
            'name' => 'Other Company',
            'slug' => 'other-company',
        ]);
        $otherDepartment = Department::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Restricted Department',
        ]);
        $user->organizations()->attach($otherOrganization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $user->departments()->attach($otherDepartment, [
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('context.update'), [
                'organization_id' => $organization->id,
                'department_id' => $otherDepartment->id,
            ]);

        $response->assertInvalid([
            'department_id' => 'Le service sélectionné n’est pas accessible dans cette société.',
        ]);
        $response->assertSessionMissing('current_organization_id');
        $response->assertSessionMissing('current_department_id');
    }

    public function test_primary_department_is_selected_when_department_is_omitted(): void
    {
        $user = User::factory()->create();
        [$organization] = $this->createAccessibleContext($user);
        $primaryDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Primary Department',
        ]);
        $user->departments()->attach($primaryDepartment, [
            'is_active' => true,
            'is_primary' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('context.update'), [
                'organization_id' => $organization->id,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('current_organization_id', $organization->id)
            ->assertSessionHas('current_department_id', $primaryDepartment->id);
    }

    public function test_director_general_can_select_any_active_department_in_the_organization(): void
    {
        $director = User::factory()->create();
        [$organization, $memberDepartment] = $this->createAccessibleContext($director);
        $otherDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance',
        ]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'DG — Directeur Général',
            'slug' => 'director',
            'is_active' => true,
        ]);
        $director->roles()->attach($role, ['organization_id' => $organization->id]);

        $response = $this
            ->actingAs($director)
            ->from(route('dashboard'))
            ->patch(route('context.update'), [
                'organization_id' => $organization->id,
                'department_id' => $otherDepartment->id,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('current_department_id', $otherDepartment->id)
            ->assertRedirect(route('dashboard'));

        $this->actingAs($director)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('workContext.department_id', $otherDepartment->id)
                ->has('workContext.departments', 2)
                ->where('workContext.departments.0.id', $otherDepartment->id)
                ->where('workContext.departments.1.id', $memberDepartment->id));
    }

    public function test_regular_user_cannot_select_an_unassigned_department_in_their_organization(): void
    {
        $user = User::factory()->create();
        [$organization] = $this->createAccessibleContext($user);
        $otherDepartment = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Finance',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('context.update'), [
                'organization_id' => $organization->id,
                'department_id' => $otherDepartment->id,
            ]);

        $response->assertInvalid([
            'department_id' => 'Le service sélectionné n’est pas accessible dans cette société.',
        ]);
    }

    public function test_selected_context_is_used_by_project_endpoints(): void
    {
        $user = User::factory()->create();
        $this->createAccessibleContext($user);
        $selectedOrganization = Organization::query()->create([
            'name' => 'Selected Company',
            'slug' => 'selected-company',
        ]);
        $selectedDepartment = Department::query()->create([
            'organization_id' => $selectedOrganization->id,
            'name' => 'Selected Department',
        ]);
        $user->organizations()->attach($selectedOrganization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $user->departments()->attach($selectedDepartment, [
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->patch(route('context.update'), [
                'organization_id' => $selectedOrganization->id,
                'department_id' => $selectedDepartment->id,
            ])
            ->assertSessionHasNoErrors();

        $response = $this
            ->actingAs($user)
            ->getJson(route('projects.index'));

        $response
            ->assertOk()
            ->assertJsonPath('organization.id', $selectedOrganization->id)
            ->assertJsonPath('department.id', $selectedDepartment->id);
    }

    /**
     * @return array{Organization, Department}
     */
    private function createAccessibleContext(User $user): array
    {
        $organization = Organization::query()->create([
            'name' => 'WorkPilot Company',
            'slug' => 'workpilot-company',
        ]);
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Operations',
        ]);
        $user->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $user->departments()->attach($department, [
            'is_active' => true,
            'is_primary' => false,
        ]);

        return [$organization, $department];
    }
}
