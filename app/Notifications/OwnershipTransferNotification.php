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
 * INGEN länk med hemlighet — ett ägarbyte överlåter hela pärmen, och en
 * bärartoken i ett mejl till en overifierad adress vore en kapabilitet att ta
 * emot någon annans båtpärm (Beslut 11). I stället gäller
 * [[ADR-0003 Åtkomstmodell]]:s regel — mottagaren måste ha konto och
 * verifierad adress — och mejlet ber henne skapa ett konto med just den
 * adressen. Sökvägen `{app.url}/transfers` är kontraktet issue 67 (M10) ska
 * implementera.
 *
 * Ingen i18n: en on-demand-notifikation har ingen `locale` att välja språk
 * från — mottagaren finns inte som användare — så texten är svensk, exakt
 * som App\Notifications\InvitationNotification och av samma skäl.
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
            ->subject('Någon vill ta över pärmen "'.$this->container->name.'"')
            ->line('Någon vill överlåta pärmen "'.$this->container->name.'" till dig.')
            ->line('Skapa ett konto med den här e-postadressen och verifiera adressen — alla som läser något i systemet ska vara identifierade. Logga sedan in och gå till fliken för ägarbyte för att se begäran.')
            ->action('Visa ägarbyte', $this->url);
    }
}
