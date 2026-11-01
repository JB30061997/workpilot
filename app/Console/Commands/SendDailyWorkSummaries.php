<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyWorkSummaryNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Signature('workpilot:send-daily-summaries')]
#[Description('Envoie à chaque utilisateur son résumé WorkPilot de la journée.')]
class SendDailyWorkSummaries extends Command
{
    public function handle(): int
    {
        $sent = 0;
        $today = today();
        $tasksByUser = $this->tasksByUser();
        $actionsByUser = $this->actionsByUser();

        User::query()
            ->whereRaw('LOWER(email) LIKE ?', ['%@'.mb_strtolower((string) config('mail.professional_domain'))])
            ->chunkById(100, function (Collection $users) use ($actionsByUser, &$sent, $tasksByUser, $today): void {
                foreach ($users as $user) {
                    $cacheKey = "workpilot:daily-summary:{$today->toDateString()}:{$user->id}";

                    if (! Cache::add($cacheKey, true, now()->addHours(30))) {
                        continue;
                    }

                    $user->notify((new DailyWorkSummaryNotification(
                        recipientName: $user->name,
                        summaryDate: $today->format('d/m/Y'),
                        tasks: $tasksByUser->get($user->id, []),
                        actions: $actionsByUser->get($user->id, []),
                    ))->afterCommit());
                    $sent++;
                }
            });

        $this->info("{$sent} résumé(s) quotidien(s) programmé(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, array<int, array{title: string, priority: string, due_at: string|null, overdue: bool}>> */
    private function tasksByUser(): Collection
    {
        $itemsByUser = collect();

        Task::query()
            ->with(['assignees:id', 'creator:id', 'status:id,is_closed'])
            ->whereNull('completed_at')
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->where(function ($query): void {
                $query->whereDate('start_at', today())
                    ->orWhere('due_at', '<=', today()->endOfDay());
            })
            ->get()
            ->each(function (Task $task) use ($itemsByUser): void {
                $userIds = $task->assignees->pluck('id');

                if ($userIds->isEmpty()) {
                    $userIds->push($task->created_by);
                }

                foreach ($userIds->unique() as $userId) {
                    $items = $itemsByUser->get($userId, []);
                    $items[] = [
                        'title' => $task->title,
                        'priority' => $task->priority,
                        'due_at' => $task->due_at?->format('d/m/Y H:i'),
                        'overdue' => $task->due_at?->isPast() ?? false,
                    ];
                    $itemsByUser->put($userId, $items);
                }
            });

        return $itemsByUser;
    }

    /** @return Collection<int, array<int, array{title: string, priority: string, due_at: string|null, overdue: bool}>> */
    private function actionsByUser(): Collection
    {
        return Action::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('owner_id')
            ->where(function ($query): void {
                $query->whereDate('start_date', today())
                    ->orWhereDate('due_date', '<=', today());
            })
            ->get()
            ->groupBy('owner_id')
            ->map(fn (Collection $actions): array => $actions->map(fn (Action $action): array => [
                'title' => $action->title,
                'priority' => $action->priority,
                'due_at' => $action->due_date?->format('d/m/Y'),
                'overdue' => $action->due_date?->isBefore(today()) ?? false,
            ])->all());
    }
}
