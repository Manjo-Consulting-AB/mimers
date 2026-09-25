<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Varningen till den GAMLA adressen om att ett byte har begärts, se
 * [[M20 Kontot]] § 130. Skickas av App\Actions\Account\RequestEmailChange,
 * samtidigt som bekräftelselänken går till den nya adressen.
 *
 * **Mejlet har ingen länk och nämner inte den nya adressen.** Det är hela
 * dess värde. En bekräftelselänk hit vore en andra väg att godkänna bytet,
 * och den nya adressen är något den som just nu läser den här brevlådan inte
 * har rätt att få veta — hon kanske inte är kontoinnehavaren. Meddelandet
 * säger bara att ett byte är på väg och var det går att stoppa: den som
 * sitter i en kapad session kan skicka formuläret, men bara innehavaren av
 * den gamla brevlådan får veta att det hände, och kan byta lösenordet innan
 * länken i det andra mejlet används. Samma funktion som
 * App\Notifications\PasswordChangedNotification fyller efter ett
 * lösenordsbyte — den här kommer före i stället för efter.
 *
 * **`$user->notify()` och inte en on-demand-notifikation:** mottagaren ÄR
 * användaren, och `user.email` är fortfarande den gamla adressen — bytet har
 * inte skett. App\Models\User bär `HasLocalePreference`, så Laravel väljer
 * språk själv; ingen locale sätts här.
 *
 * **Ett transaktionellt mejl och ingen rad i `notification`**, som
 * PasswordChangedNotification: `via()` är `['mail']`, och klockan i
 * sidhuvudet ska inte få en rad för något användaren själv beställde.
 */
class EmailChangeRequestedNotification extends Notification
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
            ->subject(trans('notiser.email_change.requested.subject'))
            ->line(trans('notiser.email_change.requested.line'))
            ->line(trans('notiser.email_change.requested.not_you'));
    }
}
