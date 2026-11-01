<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyWorkSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<int, array{title: string, priority: string, due_at: string|null, overdue: bool}>  $tasks
     * @param  array<int, array{title: string, priority: string, due_at: string|null, overdue: bool}>  $actions
     */
    public function __construct(
        public string $recipientName,
        public string $summaryDate,
        public array $tasks,
        public array $actions,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->hasProfessionalEmail()
            ? ['database', 'mail']
            : ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $taskLines = collect($this->tasks)->map(fn (array $task): string => $this->itemLine($task, 'Tâche'));
        $actionLines = collect($this->actions)->map(fn (array $action): string => $this->itemLine($action, 'Action'));
        $items = $taskLines->merge($actionLines);
        $content = $items->isEmpty()
            ? 'Aucune tâche ni action prioritaire n’est prévue pour vous aujourd’hui.'
            : $items->implode("\n");
        $viewData = [
            'preheader' => $this->preheader(),
            'eyebrow' => 'Votre journée',
            'title' => "Bonjour {$this->recipientName}",
            'intro' => "Voici votre résumé WorkPilot pour le {$this->summaryDate}.",
            'content' => $content,
            'details' => [
                'Tâches' => (string) count($this->tasks),
                'Actions' => (string) count($this->actions),
                'En retard' => (string) $this->overdueCount(),
            ],
            'actionLabel' => 'Voir ma journée',
            'actionUrl' => route('tasks.index'),
            'accent' => '#2563eb',
            'badgeBackground' => '#dbeafe',
            'logoUrl' => asset('images/logo/logo_workpilot.png'),
            'professionalDomain' => '@'.config('mail.professional_domain'),
        ];

        return (new MailMessage)
            ->subject("Votre journée WorkPilot — {$this->summaryDate}")
            ->view([
                'html' => 'mail.workpilot-notification',
                'text' => 'mail.workpilot-notification-text',
            ], $viewData);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Bonjour {$this->recipientName}",
            'message' => $this->preheader(),
            'icon' => 'calendar',
            'url' => route('tasks.index'),
            'summary_date' => $this->summaryDate,
            'task_count' => count($this->tasks),
            'action_count' => count($this->actions),
            'overdue_count' => $this->overdueCount(),
            'dedupe_key' => 'daily-summary:'.$notifiable->getKey().':'.$this->summaryDate,
        ];
    }

    /** @param array{title: string, priority: string, due_at: string|null, overdue: bool} $item */
    private function itemLine(array $item, string $type): string
    {
        $status = $item['overdue'] ? 'EN RETARD' : 'AUJOURD’HUI';
        $dueAt = $item['due_at'] ? ' · '.$item['due_at'] : '';

        return "• {$type} · {$status} · ".ucfirst($item['priority'])." — {$item['title']}{$dueAt}";
    }

    private function overdueCount(): int
    {
        return collect($this->tasks)
            ->merge($this->actions)
            ->where('overdue', true)
            ->count();
    }

    private function preheader(): string
    {
        $total = count($this->tasks) + count($this->actions);

        return $total === 0
            ? 'Votre journée est libre : aucun travail prioritaire prévu.'
            : "{$total} élément(s) à suivre aujourd’hui, dont {$this->overdueCount()} en retard.";
    }
}
