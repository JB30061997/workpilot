<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_director_can_create_roles_and_permissions(): void
    {
        $director = User::factory()->create();
        [$organization, $department] = $this->createContext($director, true);

        $roleResponse = $this
            ->actingAs($director)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('roles.store'), [
                'name' => 'Responsable qualité',
                'is_active' => true,
            ]);
        $permissionResponse = $this
            ->actingAs($director)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('permissions.store'), [
                'name' => 'Valider les rapports',
                'module' => 'Rapports',
                'is_active' => true,
            ]);

        $roleResponse->assertSessionHasNoErrors()->assertRedirect();
        $permissionResponse->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('roles', [
            'organization_id' => $organization->id,
            'name' => 'Responsable qualité',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'Valider les rapports',
            'module' => 'Rapports',
        ]);
    }

    public function test_regular_department_member_cannot_manage_roles_or_permissions(): void
    {
        $member = User::factory()->create();
        [$organization, $department] = $this->createContext($member, false);

        $roleResponse = $this
            ->actingAs($member)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('roles.store'), ['name' => 'Forbidden role']);
        $permissionResponse = $this
            ->actingAs($member)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('permissions.store'), [
                'name' => 'Forbidden permission',
                'module' => 'Users',
            ]);

        $roleResponse->assertForbidden();
        $permissionResponse->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Forbidden role']);
        $this->assertDatabaseMissing('permissions', ['name' => 'Forbidden permission']);
    }

    /**
     * @return array{Organization, Department}
     */
    private function createContext(User $user, bool $isDirector): array
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
