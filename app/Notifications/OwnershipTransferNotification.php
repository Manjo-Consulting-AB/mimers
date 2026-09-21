<?php

namespace App\Notifications;

use App\Models\Container;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet till en ägarbytesmottagare som INTE har konto — on-demand till
 * `to_email`, se issue 39a § Beslut 12.
 *
 * Skickas bara från
 * App\Http\Controllers\Api\OwnershipTransferController::store() när
 * initieringen saknar `to_account`. Mottagaren har per definition inget
 * konto och ingen `User` att notifiera, så notifikationen skickas on-demand
 * (`Notification::route('mail', ...)`) — `$notifiable` är alltså en
 * `AnonymousNotifiable`, aldrig en modell.
 *
 * Till skillnad från inbjudningsmejlet bär det här mejlet INGEN token och
 * INGEN länk med hemlighet — ett ägarbyte överlåter hela containern, och en
 * bärartoken i ett mejl till en overifierad adress vore en kapabilitet att ta
 * emot någon annans båtcontainer (Beslut 11). I stället gäller
 * [[ADR-0003 Åtkomstmodell]]:s regel — mottagaren måste ha konto och
 * verifierad adress — och mejlet ber henne skapa ett konto med just den
 * adressen. Sökvägen `{app.url}/transfers` är kontraktet issue 67 (M10) ska
 * implementera.
 *
 * Texten ligger i `lang/en/notiser.php` ([[ADR-0034 Engelska vid lansering]]
 * § Beslut: ingen användarvänd sträng utanför `lang/`). Ingen locale sätts:
 * en on-demand-notifikation har ingen `locale` att välja från — mottagaren
 * finns inte som användare — och `en` är den enda katalogen. Se
 * App\Notifications\InvitationNotification.
 */
class OwnershipTransferNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $url  Absolut länk till frontendens ägarbytesvy,
     *                       `{app.url}/transfers` — se Beslut 12. Sidan är
     *                       issue 67 (M10); sökvägen är kontraktet den ska
     *                       implementera.
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
            ->subject(trans('notiser.ownership_transfer.subject', ['container' => $this->container->name]))
            ->line(trans('notiser.ownership_transfer.line', ['container' => $this->container->name]))
            ->line(trans('notiser.ownership_transfer.line_verify'))
            ->action(trans('notiser.ownership_transfer.action'), $this->url);
    }
}
