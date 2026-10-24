<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskPlanning;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPlanningControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_create_update_duplicate_and_remove_a_planning_without_deleting_task(): void
    {
        $context = $this->createContext();
        $this->actingAs($context['user'])->withSession($context['session'])
            ->post(route('planning.store'), [
                'task_id' => $context['task']->id,
                'start_at' => '2026-10-14 09:00:00',
                'end_at' => '2026-10-14 12:00:00',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $planning = TaskPlanning::query()->firstOrFail();
        $this->assertSame(180.0, $planning->start_at->diffInMinutes($planning->end_at));

        $this->actingAs($context['user'])->withSession($context['session'])
            ->put(route('planning.update', $planning), [
                'start_at' => '2026-10-15 10:00:00',
                'end_at' => '2026-10-15 14:00:00',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-10-15 10:00:00', $planning->fresh()->start_at->format('Y-m-d H:i:s'));

        $this->actingAs($context['user'])->withSession($context['session'])
            ->post(route('planning.duplicate', $planning), [
                'start_at' => '2026-10-16 10:00:00',
                'end_at' => '2026-10-16 12:00:00',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('task_plannings', 2);

        $this->actingAs($context['user'])->withSession($context['session'])
            ->delete(route('planning.destroy', $planning))->assertRedirect();
        $this->assertDatabaseCount('task_plannings', 1);
        $this->assertDatabaseHas('tasks', ['id' => $context['task']->id]);
    }

    public function test_end_must_be_after_start(): void
    {
        $context = $this->createContext();
        $this->actingAs($context['user'])->withSession($context['session'])
            ->post(route('planning.store'), [
                'task_id' => $context['task']->id,
                'start_at' => '2026-10-14 12:00:00',
                'end_at' => '2026-10-14 09:00:00',
            ])->assertSessionHasErrors('end_at');
        $this->assertDatabaseCount('task_plannings', 0);
    }

    public function test_department_administrator_can_manage_a_planning_created_by_another_member(): void
    {
        $context = $this->createContext();
        $administrator = User::factory()->create();
        $administrator->organizations()->attach($context['organization'], [
            'status' => 'active',
            'is_active' => true,
        ]);
        $administrator->departments()->attach($context['department'], [
            'is_manager' => true,
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'Administrateur',
            'slug' => 'administrator',
            'is_active' => true,
        ]);
        $administrator->roles()->attach($role, [
            'organization_id' => $context['organization']->id,
        ]);
        $planning = TaskPlanning::query()->create([
            'organization_id' => $context['organization']->id,
            'department_id' => $context['department']->id,
            'task_id' => $context['task']->id,
            'created_by' => $context['user']->id,
            'start_at' => '2026-10-14 09:00:00',
            'end_at' => '2026-10-14 12:00:00',
        ]);
        $session = [
            'current_organization_id' => $context['organization']->id,
            'current_department_id' => $context['department']->id,
        ];

        $this->actingAs($administrator)->withSession($session)
            ->put(route('planning.update', $planning), [
                'start_at' => '2026-10-15 10:00:00',
                'end_at' => '2026-10-15 14:00:00',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-10-15 10:00:00', $planning->fresh()->start_at->format('Y-m-d H:i:s'));

        $this->actingAs($administrator)->withSession($session)
            ->post(route('planning.duplicate', $planning), [
                'start_at' => '2026-10-16 10:00:00',
                'end_at' => '2026-10-16 12:00:00',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('task_plannings', 2);

        $this->actingAs($administrator)->withSession($session)
            ->delete(route('planning.destroy', $planning))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('task_plannings', 1);
    }

    public function test_task_from_another_organization_cannot_be_scheduled(): void
    {
        $context = $this->createContext();
        $other = $this->createContext('other-company');
        $this->actingAs($context['user'])->withSession($context['session'])
            ->post(route('planning.store'), [
                'task_id' => $other['task']->id,
                'start_at' => '2026-10-14 09:00:00',
                'end_at' => '2026-10-14 12:00:00',
            ])->assertNotFound();
        $this->assertDatabaseCount('task_plannings', 0);
    }

    public function test_combined_filters_only_return_matching_plannings(): void
    {
        $context = $this->createContext();
        $context['task']->assignees()->attach($context['user']->id, ['assigned_by' => $context['user']->id, 'assigned_at' => now()]);
        TaskPlanning::query()->create([
            'organization_id' => $context['organization']->id, 'department_id' => $context['department']->id,
            'task_id' => $context['task']->id, 'created_by' => $context['user']->id,
            'start_at' => '2026-10-14 09:00:00', 'end_at' => '2026-10-14 12:00:00',
        ]);

        $this->actingAs($context['user'])->withSession($context['session'])
            ->get(route('planning.index', [
                'search' => 'déploiement', 'assignee_id' => $context['user']->id,
                'status_id' => $context['status']->id, 'from' => '2026-10-14', 'to' => '2026-10-14',
            ]))->assertOk()->assertInertia(fn ($page) => $page->has('plannings.data', 1));
    }

    /** @return array<string, mixed> */
    private function createContext(string $slug = 'workpilot'): array
    {
        $organization = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'IT']);
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_primary' => true, 'is_active' => true]);
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id, 'name' => 'Non débutée', 'slug' => 'todo', 'is_default' => true,
        ]);
        $task = Task::query()->create([
            'organization_id' => $organization->id, 'department_id' => $department->id,
            'status_id' => $status->id, 'created_by' => $user->id,
            'title' => 'Préparer le déploiement', 'priority' => 'high',
        ]);

        return [
            'organization' => $organization, 'department' => $department, 'user' => $user,
            'status' => $status, 'task' => $task,
            'session' => ['current_organization_id' => $organization->id, 'current_department_id' => $department->id],
        ];
    }
}
