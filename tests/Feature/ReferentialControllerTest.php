<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReferentialControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_referential_page_only_displays_data_from_the_current_organization(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'current');
        [$otherOrganization] = $this->createContext($user, 'other');
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Visible status',
            'slug' => 'visible-status',
            'position' => 1,
        ]);
        TaskStatus::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Hidden status',
            'slug' => 'hidden-status',
            'position' => 1,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('referential.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Referential/Index')
            ->where('organization.id', $organization->id)
            ->has('taskStatuses', 1)
            ->where('taskStatuses.0.id', $status->id)
            ->has('departments', 1)
            ->has('organizations', 2)
            ->has('roles')
            ->has('permissions')
            ->where('canManageAccess', false));
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
        $user->organizations()->attach($organization, [
            'status' => 'active',
            'is_active' => true,
        ]);
        $user->departments()->attach($department, ['is_active' => true]);

        return [$organization, $department];
    }
}
