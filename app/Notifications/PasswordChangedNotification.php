<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet om att lösenordet har ändrats, se [[M20 Kontot]] § 129. Samma form
 * som App\Notifications\MagicLinkNotification: texten ligger i
 * `lang/en/notiser.php` och inte i klassen ([[ADR-0034 Engelska vid
 * lansering]] § Beslut: ingen användarvänd sträng utanför `lang/`), och
 * klassens enda uppgift är att välja nycklar.
 *
 * **Ett transaktionellt mejl och ingen rad i `notification`.** Det svarar på
 * något användaren själv just gjorde, och det finns ingenting att läsa
 * vidare: `via()` är `['mail']` och ingen köad leveransrad skrivs. Samma
 * skillnad som [[Notiser]] § notification gör mellan en notis och ett
 * transaktionellt utskick — klockan i sidhuvudet ska inte få en rad för ett
 * byte användaren själv beställde.
 *
 * **Ingen locale sätts på mailet.** Mottagaren är en inloggad användare med
 * ett konto och därmed en `locale` — App\Models\User bär
 * `HasLocalePreference`, och Laravel läser den själv när notisen renderas.
 * MagicLinkNotification undantar sig den regeln därför att dess mottagare
 * kan sakna konto; det gäller inte här.
 *
 * **Raden i säkerhetsloggen är den andra halvan av samma händelse**
 * (App\Http\Controllers\Settings\PasswordController): mejlet går till
 * användaren, loggen stannar hos oss. Ingen av dem bär ett lösenord.
 */
class PasswordChangedNotification extends Notification
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
            ->subject(trans('notiser.password_changed.subject'))
            ->line(trans('notiser.password_changed.line'))
            ->line(trans('notiser.password_changed.not_you'));
    }
}
