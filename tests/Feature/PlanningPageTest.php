<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskPlanning;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlanningPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_can_open_the_planning_page(): void
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
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Non débutée',
            'slug' => 'todo',
            'is_default' => true,
        ]);
        $task = Task::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'created_by' => $user->id,
            'title' => 'Préparer le déploiement',
        ]);
        $planning = TaskPlanning::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'task_id' => $task->id,
            'created_by' => $user->id,
            'start_at' => '2026-10-14 09:00:00',
            'end_at' => '2026-10-14 12:00:00',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ])
            ->get(route('planning.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Planning/Index')
            ->has('plannings.data', 1)
            ->where('plannings.data.0.id', $planning->id)
            ->has('projects')
            ->has('tasks')
            ->has('members')
            ->has('statuses'));
    }
}
