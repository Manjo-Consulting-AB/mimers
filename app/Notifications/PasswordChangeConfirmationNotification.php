<?php

namespace App\Notifications;

use App\Actions\Account\RequestPasswordChange;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet med bekräftelselänken för ett lösenordsbyte, se [[M20 Kontot]]
 * § 140. Skickas bara från App\Actions\Account\RequestPasswordChange, med
 * klartext-token i `$url` — det är den ENDA plats klartexten någonsin lämnar
 * processen, och `$url` är den enda bäraren av den. Databasen har bara
 * SHA-256-hashen (app/Models/PasswordChange.php), och lösenordet självt finns
 * bara som en hash i samma rad.
 *
 * **Mottagaren är användarens EGEN adress**, till skillnad från
 * App\Notifications\EmailChangeConfirmationNotification, vars mottagare är
 * den nya adressen och därför skickas on-demand. Här är beviset att hon når
 * `user.email`, den adress kontot redan har, och notifikationen skickas med
 * `$user->notify(...)` — samma väg som
 * App\Notifications\PasswordChangedNotification.
 *
 * **Ingen locale sätts på mailet.** Mottagaren är en inloggad användare med
 * ett konto och därmed en `locale` — App\Models\User bär
 * `HasLocalePreference`, och Laravel läser den själv när notisen renderas.
 * Texten ligger i `lang/en/notiser.php` och inte i klassen, samma regel som
 * för MagicLinkNotification ([[ADR-0034 Engelska vid lansering]]).
 *
 * **Ett transaktionellt mejl och ingen rad i `notification`.** Det svarar på
 * något användaren själv just gjorde, och det finns ingenting att läsa vidare
 * i klockan: `via()` är `['mail']`, och ingen leveransrad skapas.
 */
class PasswordChangeConfirmationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $url  Absolut länk till
     *                       `GET /settings/security/password/{token}` —
     *                       rutten som genomför bytet när en inloggad
     *                       användare öppnar den.
     */
    public function __construct(
        public readonly string $url,
    ) {}

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
            ->subject(trans('notiser.password_change.confirm.subject'))
            ->line(trans('notiser.password_change.confirm.line'))
            ->action(trans('notiser.password_change.confirm.action'), $this->url)
            ->line(trans('notiser.password_change.confirm.expires', ['minutes' => RequestPasswordChange::TTL_MINUTES]))
            ->line(trans('notiser.password_change.confirm.not_you'));
    }
}
