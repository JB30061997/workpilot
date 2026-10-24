<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_sees_current_department_sessions_page(): void
    {
        $user = User::factory()->create();
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
            'is_primary' => true,
            'is_active' => true,
        ]);
        $session = WorkSession::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
            'name' => 'Sprint octobre',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'visibility' => 'department',
            'status' => 'active',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('work-sessions.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('WorkSessions/Index')
            ->where('organization.id', $organization->id)
            ->where('department.id', $department->id)
            ->has('work_sessions.data', 1)
            ->where('work_sessions.data.0.id', $session->id)
            ->has('projects')
            ->has('filters'));
    }
}
