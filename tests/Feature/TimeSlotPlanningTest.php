<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeSlotPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_creator_is_automatically_added_as_owner(): void
    {
        $context = $this->createContext();

        $this->actingAs($context['owner'])
            ->withSession($context['session'])
            ->post(route('work-sessions.store'), [
                'name' => 'Planning IT du 05/10 au 09/10',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-09',
                'visibility' => 'department',
                'status' => 'active',
                'is_active' => true,
            ])
            ->assertRedirect();

        $workSession = WorkSession::query()->where('name', 'Planning IT du 05/10 au 09/10')->firstOrFail();

        $this->assertDatabaseHas('session_members', [
            'work_session_id' => $workSession->id,
            'user_id' => $context['owner']->id,
            'role' => 'owner',
        ]);
    }

    public function test_same_task_can_be_scheduled_in_multiple_slots_with_different_members(): void
    {
        $context = $this->createContext();
        $workSession = $this->createWorkSession($context);
        $workSession->members()->attach([
            $context['owner']->id => ['role' => 'owner'],
            $context['member']->id => ['role' => 'member'],
        ]);

        $firstResponse = $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('time-slots.store'), $this->slotData($context, $workSession, [
                'start_at' => '2026-10-05 08:00:00',
                'end_at' => '2026-10-05 10:00:00',
                'member_ids' => [$context['owner']->id],
            ]));

        $secondResponse = $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('time-slots.store'), $this->slotData($context, $workSession, [
                'start_at' => '2026-10-06 08:00:00',
                'end_at' => '2026-10-06 10:00:00',
                'member_ids' => [$context['member']->id],
            ]));

        $firstResponse->assertRedirect()->assertSessionHasNoErrors();
        $secondResponse->assertRedirect()->assertSessionHasNoErrors();

        $slots = $workSession->timeSlots()->orderBy('start_at')->get();

        $this->assertCount(2, $slots);
        $this->assertSame([$context['task']->id, $context['task']->id], $slots->pluck('task_id')->all());
        $this->assertSame([$context['owner']->id], $slots[0]->members()->pluck('users.id')->all());
        $this->assertSame([$context['member']->id], $slots[1]->members()->pluck('users.id')->all());
    }

    public function test_slot_rejects_assignee_who_is_not_a_session_member(): void
    {
        $context = $this->createContext();
        $workSession = $this->createWorkSession($context);
        $workSession->members()->attach($context['owner']->id, ['role' => 'owner']);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('time-slots.store'), $this->slotData($context, $workSession, [
                'member_ids' => [$context['member']->id],
            ]))
            ->assertSessionHasErrors('member_ids');

        $this->assertDatabaseCount('time_slots', 0);
    }

    public function test_slot_must_stay_inside_session_period(): void
    {
        $context = $this->createContext();
        $workSession = $this->createWorkSession($context);
        $workSession->members()->attach($context['owner']->id, ['role' => 'owner']);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('time-slots.store'), $this->slotData($context, $workSession, [
                'start_at' => '2026-10-10 08:00:00',
                'end_at' => '2026-10-10 10:00:00',
            ]))
            ->assertSessionHasErrors('start_at');

        $this->assertDatabaseCount('time_slots', 0);
    }

    public function test_active_department_user_can_be_added_to_session(): void
    {
        $context = $this->createContext();
        $workSession = $this->createWorkSession($context);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('session-members.store'), [
                'work_session_id' => $workSession->id,
                'user_id' => $context['member']->id,
                'role' => 'member',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('session_members', [
            'work_session_id' => $workSession->id,
            'user_id' => $context['member']->id,
            'role' => 'member',
        ]);
    }

    public function test_task_can_be_created_from_an_empty_time_slot(): void
    {
        $context = $this->createContext();
        $workSession = $this->createWorkSession($context);
        $workSession->members()->attach($context['owner']->id, ['role' => 'owner']);
        $timeSlot = TimeSlot::query()->create([
            'work_session_id' => $workSession->id,
            'created_by' => $context['owner']->id,
            'title' => 'Créneau du lundi matin',
            'start_at' => '2026-10-05 08:00:00',
            'end_at' => '2026-10-05 10:00:00',
            'status' => 'planned',
        ]);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('tasks.store'), [
                'time_slot_id' => $timeSlot->id,
                'status_id' => $context['status']->id,
                'title' => 'Task créée depuis le créneau',
                'priority' => 'medium',
                'assignee_ids' => [$context['owner']->id],
                'start_at' => '2026-10-05 08:00:00',
                'due_at' => '2026-10-05 10:00:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('title', 'Task créée depuis le créneau')->firstOrFail();

        $this->assertSame($task->id, $timeSlot->fresh()->task_id);
        $this->assertSame([$context['owner']->id], $task->assignees()->pluck('users.id')->all());
    }

    /** @return array<string, mixed> */
    private function createContext(): array
    {
        $organization = Organization::query()->create(['name' => 'WorkPilot', 'slug' => 'workpilot']);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'IT']);
        $owner = User::factory()->create();
        $member = User::factory()->create();

        foreach ([$owner, $member] as $user) {
            $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
            $user->departments()->attach($department, ['is_primary' => true, 'is_active' => true]);
        }

        $status = TaskStatus::query()->create(['organization_id' => $organization->id, 'name' => 'À faire', 'slug' => 'todo', 'is_default' => true]);
        $task = Task::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'created_by' => $owner->id,
            'title' => 'Task A',
        ]);

        return [
            'organization' => $organization,
            'department' => $department,
            'owner' => $owner,
            'member' => $member,
            'task' => $task,
            'status' => $status,
            'session' => ['current_organization_id' => $organization->id, 'current_department_id' => $department->id],
        ];
    }

    /** @param array<string, mixed> $context */
    private function createWorkSession(array $context): WorkSession
    {
        return WorkSession::query()->create([
            'organization_id' => $context['organization']->id,
            'department_id' => $context['department']->id,
            'created_by' => $context['owner']->id,
            'name' => 'Planning IT du 05/10 au 09/10',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'visibility' => 'department',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function slotData(array $context, WorkSession $workSession, array $overrides = []): array
    {
        return array_merge([
            'work_session_id' => $workSession->id,
            'task_id' => $context['task']->id,
            'title' => $context['task']->title,
            'start_at' => '2026-10-05 08:00:00',
            'end_at' => '2026-10-05 10:00:00',
            'status' => 'planned',
            'member_ids' => [$context['owner']->id],
        ], $overrides);

    }
}
