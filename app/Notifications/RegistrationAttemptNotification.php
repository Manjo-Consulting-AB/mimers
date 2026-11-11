<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet till den som äger en adress när någon försöker registrera den igen
 * (issue 1057). Registreringens svar är detsamma för en ny och en befintlig
 * adress, så det här mejlet är det enda ägaren får veta. Samma form som
 * App\Notifications\PasswordChangedNotification: transaktionellt, inte köat,
 * texten i `lang/en/notiser.php`.
 */
class RegistrationAttemptNotification extends Notification
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
            ->subject(trans('notiser.registration_attempt.subject'))
            ->line(trans('notiser.registration_attempt.line'))
            ->line(trans('notiser.registration_attempt.not_you'));
    }
}
