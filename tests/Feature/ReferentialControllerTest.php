<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\PlanningYear;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReferentialControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_referential_page_only_displays_data_from_the_current_organization(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
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
            ->has('planningYears', 1)
            ->where('planningYears.0.label', '2026-2027')
            ->where('planningYears.0.start_date', '2026-09-01T00:00:00.000000Z')
            ->where('planningYears.0.end_date', '2027-08-31T00:00:00.000000Z')
            ->where('planningYears.0.is_current', true)
            ->where('canManageAccess', false));

        $this->assertDatabaseHas('planning_years', [
            'organization_id' => $organization->id,
            'label' => '2026-2027',
        ]);
        $this->assertDatabaseMissing('planning_years', [
            'organization_id' => $otherOrganization->id,
        ]);

    }

    public function test_referential_accepts_the_years_tab(): void
    {
        $user = User::factory()->create();
        [$organization, $department] = $this->createContext($user, 'current');
        PlanningYear::factory()->for($organization)->create();

        $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('referential.index', ['tab' => 'years']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Referential/Index')
                ->where('activeTab', 'years'));
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
