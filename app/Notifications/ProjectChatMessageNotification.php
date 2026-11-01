<?php

namespace App\Notifications;

use App\Models\ProjectChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectChatMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public ProjectChatMessage $chatMessage) {}

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
        $project = $this->chatMessage->project;
        $sender = $this->chatMessage->user;
        $body = trim((string) $this->chatMessage->body);
        $viewData = [
            'preheader' => "Nouveau message de {$sender->name} dans {$project->name}",
            'eyebrow' => 'Discussion projet',
            'title' => "Bonjour {$notifiable->name}",
            'intro' => "{$sender->name} a publié un nouveau message dans le groupe du projet {$project->name}.",
            'content' => $body !== '' ? $body : 'Une pièce jointe a été partagée.',
            'details' => [
                'Projet' => $project->name,
                'Expéditeur' => $sender->name,
            ],
            'actionLabel' => 'Ouvrir la discussion',
            'actionUrl' => route('project-chat.index', ['project' => $project->id]),
            'accent' => '#2563eb',
            'badgeBackground' => '#dbeafe',
            'logoUrl' => asset('images/logo/logo_workpilot.png'),
            'professionalDomain' => '@'.config('mail.professional_domain'),
        ];

        return (new MailMessage)
            ->subject("Nouveau message — {$project->name}")
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
        $project = $this->chatMessage->project;
        $sender = $this->chatMessage->user;
        $body = trim((string) $this->chatMessage->body);

        return [
            'title' => 'Nouveau message projet',
            'message' => $sender->name.' · '.($body !== '' ? (string) str($body)->limit(120) : 'Pièce jointe partagée'),
            'icon' => 'message-circle',
            'url' => route('project-chat.index', ['project' => $project->id]),
            'project_id' => $project->id,
            'chat_message_id' => $this->chatMessage->id,
        ];
    }
}
