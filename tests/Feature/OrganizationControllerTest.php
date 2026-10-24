<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_update_an_accessible_organization(): void
    {
        $director = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Before update',
            'slug' => 'before-update',
        ]);
        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Directeur',
            'slug' => 'director',
            'is_system' => true,
            'is_active' => true,
        ]);
        $director->organizations()->attach($organization, [
            'status' => 'active',
            'is_owner' => false,
            'is_active' => true,
        ]);
        $director->roles()->attach($role, [
            'organization_id' => $organization->id,
        ]);

        $response = $this
            ->actingAs($director)
            ->patch(route('organizations.update', $organization), [
                'name' => 'After update',
                'email' => 'contact@example.test',
                'is_active' => true,
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'After update',
            'email' => 'contact@example.test',
        ]);
    }
}
