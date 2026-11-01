<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SystemMailPreviewNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

#[Signature('workpilot:send-mail-preview {email : Adresse professionnelle du destinataire}')]
#[Description('Envoie immédiatement un email de démonstration du thème WorkPilot.')]
class SendMailPreview extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $domain = Str::lower(trim((string) config('mail.professional_domain')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! Str::endsWith($email, '@'.$domain)) {
            $this->error("Utilisez une adresse professionnelle @{$domain} valide.");

            return self::FAILURE;
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->error('Envoi annulé : configurez un mailer SMTP réel avant ce test.');

            return self::FAILURE;
        }

        $name = User::query()->whereRaw('LOWER(email) = ?', [$email])->value('name') ?? $email;

        Notification::route('mail', [$email => $name])
            ->notifyNow(new SystemMailPreviewNotification($name));

        $this->info("Email de démonstration envoyé à {$email}.");

        return self::SUCCESS;
    }
}
