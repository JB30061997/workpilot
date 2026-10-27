<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessControlControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_control_page_provisions_and_displays_default_roles_for_current_organization(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, true);
        $otherOrganization = Organization::factory()->create();
        Role::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Hidden role',
            'slug' => 'hidden-role',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('access-control.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('AccessControl/Index')
            ->where('organization.id', $organization->id)
            ->has('roles', 6)
            ->where('canManageAccess', true)
            ->has('departments', 1)
            ->has('members', 1)
            ->has('permissions'));

        $this->assertDatabaseHas('roles', [
            'organization_id' => $organization->id,
            'slug' => 'director',
            'name' => 'DG — Directeur Général',
            'description' => 'Description personnalisée',
            'is_system' => true,
        ]);
        $this->assertDatabaseHas('roles', [
            'organization_id' => $organization->id,
            'slug' => 'deputy-director',
            'name' => 'DGA — Directeur Général Adjoint',
        ]);
        $this->assertDatabaseMissing('roles', [
            'organization_id' => $organization->id,
            'slug' => 'hidden-role',
        ]);
    }

    public function test_regular_collaborator_can_view_but_cannot_manage_access(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, false);

        $this
            ->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('access-control.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('AccessControl/Index')
                ->where('canManageAccess', false));
    }

    /** @return array{Organization, Department} */
    private function createContext(User $user, bool $manager): array
    {
        $organization = Organization::factory()->create();
        $department = Department::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Informatique',
        ]);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, [
            'is_manager' => $manager,
            'is_primary' => true,
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => $manager ? 'DG' : 'Collaborateur temporaire',
            'slug' => $manager ? 'director' : 'temporary-collaborator',
            'description' => $manager ? 'Description personnalisée' : null,
        ]);
        $user->roles()->attach($role, ['organization_id' => $organization->id]);

        return [$organization, $department];
    }

    /** @return array{current_organization_id: int, current_department_id: int} */
    private function contextSession(Organization $organization, Department $department): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_department_id' => $department->id,
        ];
    }
}
