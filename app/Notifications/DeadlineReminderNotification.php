<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $itemType,
        public int $itemId,
        public string $title,
        public string $priority,
        public string $dueAt,
        public string $stage,
        public string $url,
        public string $dedupeKey,
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
        $isOverdue = $this->stage === 'overdue';
        $kind = $this->itemType === 'task' ? 'tâche' : 'action';
        $accent = $isOverdue ? '#dc2626' : match ($this->priority) {
            'critical' => '#dc2626',
            'high' => '#ea580c',
            'medium' => '#2563eb',
            default => '#0891b2',
        };
        $badgeBackground = $isOverdue ? '#fee2e2' : match ($this->priority) {
            'critical' => '#fee2e2',
            'high' => '#ffedd5',
            'medium' => '#dbeafe',
            default => '#cffafe',
        };
        $viewData = [
            'preheader' => ($isOverdue ? 'Échéance dépassée' : 'Échéance proche')." : {$this->title}",
            'eyebrow' => $isOverdue ? 'Action requise' : 'Rappel échéance',
            'title' => $isOverdue ? 'Une échéance est dépassée' : 'Une échéance approche',
            'intro' => "Bonjour {$notifiable->name}, WorkPilot vous rappelle une échéance importante.",
            'content' => "La {$kind} « {$this->title} » ".($isOverdue ? 'a dépassé son échéance.' : 'arrive bientôt à échéance.'),
            'details' => [
                'Échéance' => $this->dueAt,
                'Priorité' => ucfirst($this->priority),
                'Type' => ucfirst($kind),
            ],
            'actionLabel' => 'Consulter dans WorkPilot',
            'actionUrl' => $this->url,
            'accent' => $accent,
            'badgeBackground' => $badgeBackground,
            'logoUrl' => asset('images/logo/logo_workpilot.png'),
            'professionalDomain' => '@'.config('mail.professional_domain'),
        ];

        return (new MailMessage)
            ->subject(($isOverdue ? 'Échéance dépassée' : 'Échéance proche')." — {$this->title}")
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
            'title' => $this->stage === 'overdue' ? 'Échéance dépassée' : 'Échéance proche',
            'message' => $this->title.' · '.$this->dueAt.' · '.ucfirst($this->priority),
            'icon' => 'calendar',
            'url' => $this->url,
            'item_type' => $this->itemType,
            'item_id' => $this->itemId,
            'stage' => $this->stage,
            'dedupe_key' => $this->dedupeKey,
        ];
    }
}
