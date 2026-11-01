<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\DailyWorkSummaryNotification;
use App\Notifications\DeadlineReminderNotification;
use App\Notifications\ProjectChatMessageNotification;
use App\Notifications\SystemMailPreviewNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_chat_message_notifies_other_members_by_database_and_mail(): void
    {
        Notification::fake();
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'recipient@holged.com']);
        $project = $this->project($sender);
        $project->members()->attach([
            $sender->id => ['role' => 'member', 'joined_at' => now()],
            $recipient->id => ['role' => 'member', 'joined_at' => now()],
        ]);

        $this->actingAs($sender)
            ->postJson(route('project-chat.store', $project), [
                'body' => 'Le dossier fournisseur est prêt.',
            ])
            ->assertCreated();

        Notification::assertSentTo(
            $recipient,
            ProjectChatMessageNotification::class,
            fn (ProjectChatMessageNotification $notification, array $channels): bool => in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && $notification->chatMessage->body === 'Le dossier fournisseur est prêt.',
        );
        Notification::assertNotSentTo($sender, ProjectChatMessageNotification::class);
    }

    public function test_deadline_command_respects_priority_lead_time(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $organization = $this->organization();
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'À faire',
            'slug' => 'todo',
            'is_default' => true,
            'is_closed' => false,
            'is_active' => true,
        ]);
        $task = Task::query()->create([
            'organization_id' => $organization->id,
            'status_id' => $status->id,
            'created_by' => $owner->id,
            'title' => 'Tâche urgente',
            'priority' => 'medium',
            'due_at' => now()->addDay(),
        ]);
        $task->assignees()->attach($owner, ['assigned_by' => $owner->id, 'assigned_at' => now()]);
        Task::query()->create([
            'organization_id' => $organization->id,
            'status_id' => $status->id,
            'created_by' => $owner->id,
            'title' => 'Tâche faible non imminente',
            'priority' => 'low',
            'due_at' => now()->addDay(),
        ]);
        Action::query()->create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => 'Action critique',
            'priority' => 'critical',
            'status' => 'in_progress',
            'due_date' => today()->addDays(7),
        ]);

        $this->artisan('workpilot:send-deadline-reminders')->assertSuccessful();

        Notification::assertSentToTimes($owner, DeadlineReminderNotification::class, 2);
        Notification::assertSentTo(
            $owner,
            DeadlineReminderNotification::class,
            fn (DeadlineReminderNotification $notification): bool => $notification->title === 'Tâche urgente',
        );
        Notification::assertSentTo(
            $owner,
            DeadlineReminderNotification::class,
            fn (DeadlineReminderNotification $notification): bool => $notification->title === 'Action critique',
        );
    }

    public function test_daily_summary_lists_today_and_overdue_work_once_for_every_professional_user(): void
    {
        $this->travelTo('2026-10-29 07:30:00');
        Notification::fake();
        $owner = User::factory()->create(['email' => 'owner@holged.com']);
        $freeUser = User::factory()->create(['email' => 'free@holged.com']);
        $organization = $this->organization();
        $status = TaskStatus::query()->create([
            'organization_id' => $organization->id,
            'name' => 'À faire',
            'slug' => 'todo-daily',
            'is_default' => true,
            'is_closed' => false,
            'is_active' => true,
        ]);
        $task = Task::query()->create([
            'organization_id' => $organization->id,
            'status_id' => $status->id,
            'created_by' => $owner->id,
            'title' => 'Préparer le rapport du jour',
            'priority' => 'high',
            'start_at' => now(),
            'due_at' => now()->setTime(16, 0),
        ]);
        $task->assignees()->attach($owner, ['assigned_by' => $owner->id, 'assigned_at' => now()]);
        Action::query()->create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => 'Clôturer le dossier en retard',
            'priority' => 'critical',
            'status' => 'in_progress',
            'due_date' => today()->subDay(),
        ]);

        $this->artisan('workpilot:send-daily-summaries')->assertSuccessful();
        $this->artisan('workpilot:send-daily-summaries')->assertSuccessful();

        Notification::assertSentToTimes($owner, DailyWorkSummaryNotification::class, 1);
        Notification::assertSentTo(
            $owner,
            DailyWorkSummaryNotification::class,
            fn (DailyWorkSummaryNotification $notification): bool => count($notification->tasks) === 1
                && count($notification->actions) === 1
                && $notification->tasks[0]['title'] === 'Préparer le rapport du jour'
                && $notification->actions[0]['overdue'],
        );
        Notification::assertSentTo(
            $freeUser,
            DailyWorkSummaryNotification::class,
            fn (DailyWorkSummaryNotification $notification): bool => $notification->tasks === []
                && $notification->actions === [],
        );
    }

    public function test_user_can_mark_only_their_own_notification_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = $this->storeDeadlineNotification($user);
        $otherNotification = $this->storeDeadlineNotification($otherUser);

        $this->actingAs($user)
            ->patchJson(route('notifications.read', $notification->id))
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($user)
            ->patchJson(route('notifications.read', $otherNotification->id))
            ->assertNotFound();
    }

    public function test_system_preview_email_renders_the_workpilot_brand(): void
    {
        $mail = (new SystemMailPreviewNotification('Utilisateur Test'))
            ->toMail((object) []);

        $html = (string) $mail->render();

        $this->assertStringContainsString('WorkPilot', $html);
        $this->assertStringContainsString('logo_workpilot.png', $html);
        $this->assertStringContainsString('Bienvenue dans les alertes WorkPilot', $html);
        $this->assertStringContainsString('Connexion active', $html);
    }

    public function test_preview_command_refuses_to_claim_delivery_with_the_log_mailer(): void
    {
        config()->set('mail.default', 'log');

        $this->artisan('workpilot:send-mail-preview', ['email' => 'preview@holged.com'])
            ->expectsOutput('Envoi annulé : configurez un mailer SMTP réel avant ce test.')
            ->assertFailed();
    }

    private function organization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Notification Organization',
            'slug' => 'notification-organization',
        ]);
    }

    private function project(User $creator): Project
    {
        return Project::query()->create([
            'organization_id' => $this->organization()->id,
            'created_by' => $creator->id,
            'name' => 'Projet notifications',
        ]);
    }

    private function storeDeadlineNotification(User $user): DatabaseNotification
    {
        $user->notifyNow(new DeadlineReminderNotification(
            itemType: 'task',
            itemId: 1,
            title: 'Échéance test',
            priority: 'high',
            dueAt: '30/10/2026 10:00',
            stage: 'due_soon',
            url: route('tasks.index'),
            dedupeKey: 'task:1:2026-10-30:due_soon',
        ), ['database']);

        return $user->notifications()->firstOrFail();
    }
}
