<?php

namespace App\Notifications;

use App\Actions\Account\RequestEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet med bekräftelselänken till den NYA adressen, se [[M20 Kontot]]
 * § 130. Skickas bara från App\Actions\Account\RequestEmailChange, med
 * klartext-token i `$url` — det är den ENDA plats klartexten någonsin lämnar
 * processen, och `$url` är den enda bäraren av den. Databasen har bara
 * SHA-256-hashen (app/Models/EmailChange.php).
 *
 * **Mottagaren är den nya adressen och inte användaren.** Adressen har inget
 * konto än — det är hela ärendet — så notifikationen skickas on-demand
 * (`Notification::route('mail', ...)`) och `$notifiable` är en
 * `AnonymousNotifiable`, aldrig en modell. Samma form som
 * App\Notifications\InvitationNotification.
 *
 * **Ingen locale sätts på mailet.** En on-demand-notifikation har ingen
 * `locale` att välja ur — mottagaren finns inte som användare — och `en` är
 * den enda katalogen ([[ADR-0034 Engelska vid lansering]]). Texten ligger i
 * `lang/en/notiser.php` och inte i klassen, samma regel som för
 * MagicLinkNotification.
 *
 * **Ett transaktionellt mejl och ingen rad i `notification`.** Det svarar på
 * något användaren själv just gjorde, och det finns ingenting att läsa vidare
 * i klockan: `via()` är `['mail']`, och ingen leveransrad skapas.
 */
class EmailChangeConfirmationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $url  Absolut länk till
     *                       `GET /settings/profile/email/{token}` — rutten
     *                       som genomför bytet när en inloggad användare
     *                       öppnar den.
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
            ->subject(trans('notiser.email_change.confirm.subject'))
            ->line(trans('notiser.email_change.confirm.line'))
            ->action(trans('notiser.email_change.confirm.action'), $this->url)
            ->line(trans('notiser.email_change.confirm.expires', ['minutes' => RequestEmailChange::TTL_MINUTES]))
            ->line(trans('notiser.email_change.confirm.not_you'));
    }
}
