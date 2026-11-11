<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet om att återställningskoderna har bytts. Samma form som
 * App\Notifications\PasswordChangedNotification: texten ligger i
 * `lang/en/notiser.php` och klassens enda uppgift är att välja nycklar.
 *
 * Bytet kräver ingen kodbekräftelse (App\Support\Auth\RecoveryCodeBroker
 * § Beslut 4), så en kapad session kan göra det i tysthet. Mejlet är det som
 * gör ett byte som ägaren inte själv gjort synligt för henne.
 *
 * Transaktionellt och inte köat: `via()` är `['mail']` och ingen rad skrivs i
 * `notification`. Ingen locale sätts — mottagaren är en inloggad användare
 * med `locale`, som Laravel läser själv.
 */
class RecoveryCodesRegeneratedNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(trans('notiser.recovery_codes_regenerated.subject'))
            ->line(trans('notiser.recovery_codes_regenerated.line'))
            ->line(trans('notiser.recovery_codes_regenerated.not_you'));
    }
}
