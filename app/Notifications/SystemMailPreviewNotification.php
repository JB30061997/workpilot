<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemMailPreviewNotification extends Notification
{
    public function __construct(public string $recipientName) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $viewData = [
            'preheader' => 'Votre boîte professionnelle est prête à recevoir les alertes WorkPilot.',
            'eyebrow' => 'Email de démonstration',
            'title' => 'Bienvenue dans les alertes WorkPilot',
            'intro' => "Bonjour {$this->recipientName}, ce message confirme que votre boîte professionnelle est correctement reliée au système d’alertes.",
            'content' => 'Vous recevrez ici les nouveaux messages de vos groupes projet ainsi que les rappels d’échéance des tâches et des actions dont vous êtes responsable.',
            'details' => [
                'Canal' => 'Boîte professionnelle',
                'Alertes' => 'Messages, tâches et actions',
                'Statut' => 'Connexion active',
            ],
            'actionLabel' => 'Ouvrir WorkPilot',
            'actionUrl' => route('dashboard'),
            'accent' => '#2563eb',
            'badgeBackground' => '#dbeafe',
            'logoUrl' => asset('images/logo/logo_workpilot.png'),
            'professionalDomain' => '@'.config('mail.professional_domain'),
        ];

        return (new MailMessage)
            ->subject('Votre système d’alertes WorkPilot est prêt')
            ->view([
                'html' => 'mail.workpilot-notification',
                'text' => 'mail.workpilot-notification-text',
            ], $viewData);
    }
}
