<?php

namespace App\Notifications;

use App\Actions\User\RequestUserDeletion;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet med bekräftelselänken för en personradering, se [[M22 Redo för
 * testare]] § 145. Skickas bara från App\Actions\User\RequestUserDeletion, med
 * klartext-token i `$url` — det är den ENDA plats klartexten någonsin lämnar
 * processen, och `$url` är den enda bäraren av den. Databasen har bara
 * SHA-256-hashen (app/Models/UserDeletion.php).
 *
 * **Mottagaren är användarens EGEN adress**, som
 * App\Notifications\PasswordChangeConfirmationNotification och till skillnad
 * från App\Notifications\EmailChangeConfirmationNotification, vars mottagare är
 * den nya adressen. Här är beviset att hon når `user.email`, den adress konto
 * redan har, och notifikationen skickas med `$user->notify(...)`.
 *
 * **Ingen locale sätts på mailet.** Mottagaren är en inloggad användare med
 * ett konto och därmed en `locale` — App\Models\User bär
 * `HasLocalePreference`, och Laravel läser den själv när notisen renderas.
 * Texten ligger i `lang/en/notiser.php` och inte i klassen, samma regel som
 * för PasswordChangeConfirmationNotification ([[ADR-0034 Engelska vid
 * lansering]]).
 *
 * **Ärendet är allvarligare än de andra bekräftelsemejlen, och texten säger
 * det.** Ett lösenordsbyte kan göras om; en personradering har ingen
 * ångerfrist (ADR-0045 § Beslut 3). `not_you` är därför en uppmaning att byta
 * lösenord, inte att ignorera mejlet — den som får det oombett sitter i ett
 * kapat konto, och den insikten är det enda mejlet kan ge henne.
 *
 * **Ett transaktionellt mejl och ingen rad i `notification`.** Det svarar på
 * något användaren själv just gjorde, och det finns ingenting att läsa vidare
 * i klockan: `via()` är `['mail']`, och ingen leveransrad skapas.
 */
class UserDeletionConfirmationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $url  Absolut länk till
     *                       `GET /settings/delete-user/{token}` — rutten som
     *                       raderar personen när en inloggad användare
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
            ->subject(trans('notiser.user_deletion.confirm.subject'))
            ->line(trans('notiser.user_deletion.confirm.line'))
            ->action(trans('notiser.user_deletion.confirm.action'), $this->url)
            ->line(trans('notiser.user_deletion.confirm.expires', ['minutes' => RequestUserDeletion::TTL_MINUTES]))
            ->line(trans('notiser.user_deletion.confirm.not_you'));
    }
}
