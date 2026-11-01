<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DeadlineReminderNotification;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Signature('workpilot:send-deadline-reminders')]
#[Description('Envoie les alertes WorkPilot pour les échéances des tâches et des actions.')]
class SendDeadlineReminders extends Command
{
    /** @var array<string, int> */
    private const LEAD_DAYS = [
        'low' => 0,
        'medium' => 1,
        'high' => 3,
        'critical' => 7,
    ];

    public function handle(): int
    {
        $sent = 0;

        Task::query()
            ->with(['assignees:id,name,email', 'creator:id,name,email', 'status:id,is_closed'])
            ->whereNotNull('due_at')
            ->whereNull('completed_at')
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->where('due_at', '<=', now()->addDays(max(self::LEAD_DAYS)))
            ->chunkById(100, function (Collection $tasks) use (&$sent): void {
                foreach ($tasks as $task) {
                    $sent += $this->notifyRecipients(
                        $task,
                        $task->assignees->isNotEmpty() ? $task->assignees : collect([$task->creator]),
                        'task',
                        $task->due_at,
                        route('tasks.index'),
                    );
                }
            });

        Action::query()
            ->with(['owner:id,name,email', 'creator:id,name,email'])
            ->whereNotNull('due_date')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereDate('due_date', '<=', today()->addDays(max(self::LEAD_DAYS)))
            ->chunkById(100, function (Collection $actions) use (&$sent): void {
                foreach ($actions as $action) {
                    $sent += $this->notifyRecipients(
                        $action,
                        collect([$action->owner ?? $action->creator]),
                        'action',
                        $action->due_date->endOfDay(),
                        route('action-plans.index'),
                    );
                }
            });

        $this->info("{$sent} alerte(s) programmée(s).");

        return self::SUCCESS;
    }

    /** @param Collection<int, User|null> $recipients */
    private function notifyRecipients(
        Model $item,
        Collection $recipients,
        string $itemType,
        CarbonInterface $dueAt,
        string $url,
    ): int {
        $priority = (string) ($item->priority ?: 'medium');
        $daysUntilDue = now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false);
        $stage = $daysUntilDue < 0 ? 'overdue' : 'due_soon';

        if ($stage === 'due_soon' && $daysUntilDue > (self::LEAD_DAYS[$priority] ?? self::LEAD_DAYS['medium'])) {
            return 0;
        }

        $dedupeKey = implode(':', [
            $itemType,
            $item->getKey(),
            $dueAt->toDateString(),
            $stage,
        ]);
        $sent = 0;

        foreach ($recipients->filter()->unique('id') as $recipient) {
            if (! $recipient->email || $recipient->notifications()->where('data->dedupe_key', $dedupeKey)->exists()) {
                continue;
            }

            $recipient->notify((new DeadlineReminderNotification(
                itemType: $itemType,
                itemId: (int) $item->getKey(),
                title: (string) $item->title,
                priority: $priority,
                dueAt: $dueAt->format('d/m/Y H:i'),
                stage: $stage,
                url: $url,
                dedupeKey: $dedupeKey,
            ))->afterCommit());
            $sent++;
        }

        return $sent;
    }
}
