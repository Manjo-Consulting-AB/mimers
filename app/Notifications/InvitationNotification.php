<?php

namespace App\Notifications;

use App\Models\Container;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet med inbjudningslänken, se issue 10b § Beslut 2 och § Beslut 4.
 * Skickas bara från App\Http\Controllers\Api\ContainerInvitationController::store(),
 * direkt efter att raden skapats, med den klartext-token som genereras
 * där — det är den ENDA plats klartexten någonsin lämnar processen (issue
 * 10a § Beslut 5), och `$url` är den enda bäraren av den.
 *
 * Mottagaren har per definition inget konto och därmed ingen `User` att
 * notifiera, så notifikationen skickas on-demand
 * (`Notification::route('mail', ...)`) — `$notifiable` är alltså en
 * `AnonymousNotifiable`, aldrig en modell.
 *
 * Mejlleverans är inte den här issuen — använder Laravels mailer som den
 * redan är konfigurerad (`MAIL_MAILER` i .env, `config/mail.php` rörs
 * inte här), Postmark och studshantering kopplas in i M5 (issue 32).
 *
 * Texten ligger i `lang/en/notiser.php` ([[ADR-0034 Engelska vid lansering]]
 * § Beslut: ingen användarvänd sträng utanför `lang/`), se
 * App\Notifications\MagicLinkNotification. Ingen locale sätts: en
 * on-demand-notifikation har ingen `locale` att välja från — mottagaren finns
 * inte som användare — och `en` är den enda katalogen.
 */
class InvitationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $url  Absolut länk till frontendens landningssida,
     *                       `{app.url}/invitations/{token}` — se issue 10b
     *                       § Beslut 3. Sidan är issue 55 (M10); sökvägen
     *                       är kontraktet den ska implementera.
     */
    public function __construct(
        public readonly string $url,
        public readonly Container $container,
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
            ->subject(trans('notiser.invitation.subject', ['container' => $this->container->name]))
            ->line(trans('notiser.invitation.line', ['container' => $this->container->name]))
            ->line(trans('notiser.invitation.line_verify'))
            ->action(trans('notiser.invitation.action'), $this->url)
            ->line(trans('notiser.invitation.expires', ['days' => Invitation::TTL_DAYS]));
    }
}
