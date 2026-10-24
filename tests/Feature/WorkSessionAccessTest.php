<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkSessionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_session_is_only_listed_for_its_members(): void
    {
        $context = $this->createContext();
        $privateSession = $this->createSession($context, 'private');
        $privateSession->members()->attach($context['owner'], ['role' => 'owner']);

        $this->actingAs($context['colleague'])->withSession($context['session'])
            ->get(route('work-sessions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('work_sessions.data', 0));

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->get(route('work-sessions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('work_sessions.data', 1)
                ->where('work_sessions.data.0.id', $privateSession->id));
    }

    public function test_department_session_is_visible_to_an_active_department_member(): void
    {
        $context = $this->createContext();
        $workSession = $this->createSession($context, 'department');
        $workSession->members()->attach($context['owner'], ['role' => 'owner']);

        $this->actingAs($context['colleague'])->withSession($context['session'])
            ->get(route('work-sessions.show', $workSession))
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkSessions/Show')
                ->where('work_session.id', $workSession->id)
                ->has('members')
                ->has('task_statuses'));
    }

    public function test_session_creation_adds_selected_members_from_the_same_department(): void
    {
        $context = $this->createContext();

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('work-sessions.store'), [
                'name' => 'Infrastructure - semaine 41',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-09',
                'visibility' => 'private',
                'status' => 'active',
                'member_ids' => [$context['colleague']->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $workSession = WorkSession::query()->where('name', 'Infrastructure - semaine 41')->firstOrFail();
        $this->assertSame(
            [$context['owner']->id, $context['colleague']->id],
            $workSession->members()->orderBy('users.id')->pluck('users.id')->all(),
        );
    }

    public function test_session_creation_rejects_member_from_another_department(): void
    {
        $context = $this->createContext();
        $otherDepartment = Department::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'Finance',
        ]);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($context['organization'], ['status' => 'active', 'is_active' => true]);
        $outsider->departments()->attach($otherDepartment, ['is_active' => true]);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('work-sessions.store'), [
                'name' => 'Session interdite',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-09',
                'visibility' => 'private',
                'status' => 'active',
                'member_ids' => [$outsider->id],
            ])
            ->assertInvalid([
                'member_ids' => 'Un ou plusieurs membres ne font pas partie du département de la session.',
            ]);

        $this->assertDatabaseMissing('work_sessions', ['name' => 'Session interdite']);
    }

    public function test_task_created_from_session_is_linked_and_only_accepts_session_members(): void
    {
        $context = $this->createContext();
        $workSession = $this->createSession($context, 'private');
        $workSession->members()->attach([
            $context['owner']->id => ['role' => 'owner'],
            $context['colleague']->id => ['role' => 'member'],
        ]);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('tasks.store'), [
                'work_session_id' => $workSession->id,
                'title' => 'Configurer le serveur',
                'status_id' => $context['status']->id,
                'priority' => 'high',
                'assignee_ids' => [$context['colleague']->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('title', 'Configurer le serveur')->firstOrFail();
        $this->assertSame($workSession->id, $task->work_session_id);
        $this->assertSame([$context['colleague']->id], $task->assignees()->pluck('users.id')->all());
    }

    public function test_session_task_rejects_department_user_who_is_not_a_session_member(): void
    {
        $context = $this->createContext();
        $workSession = $this->createSession($context, 'private');
        $workSession->members()->attach($context['owner'], ['role' => 'owner']);

        $this->actingAs($context['owner'])->withSession($context['session'])
            ->post(route('tasks.store'), [
                'work_session_id' => $workSession->id,
                'title' => 'Tâche non autorisée',
                'status_id' => $context['status']->id,
                'priority' => 'medium',
                'assignee_ids' => [$context['colleague']->id],
            ])
            ->assertInvalid([
                'assignee_ids' => 'Un ou plusieurs responsables ne sont pas membres de cette session.',
            ]);

        $this->assertDatabaseMissing('tasks', ['title' => 'Tâche non autorisée']);
    }

    /** @return array<string, mixed> */
    private function createContext(): array
    {
        $organization = Organization::query()->create(['name' => 'WorkPilot', 'slug' => 'workpilot']);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'Infrastructure']);
        $owner = User::factory()->create();
        $colleague = User::factory()->create();

        foreach ([$owner, $colleague] as $user) {
            $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
            $user->departments()->attach($department, ['is_active' => true]);
        }

        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'À faire',
            'slug' => 'todo',
            'is_default' => true,
            'is_active' => true,
        ]);

        return [
            'organization' => $organization,
            'department' => $department,
            'owner' => $owner,
            'colleague' => $colleague,
            'status' => $status,
            'session' => [
                'current_organization_id' => $organization->id,
                'current_department_id' => $department->id,
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    private function createSession(array $context, string $visibility): WorkSession
    {
        return WorkSession::query()->create([
            'organization_id' => $context['organization']->id,
            'department_id' => $context['department']->id,
            'created_by' => $context['owner']->id,
            'name' => 'Session Infrastructure',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'visibility' => $visibility,
            'status' => 'active',
        ]);
    }
}
