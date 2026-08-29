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
 * Ingen i18n: ingen `lang/`-fil finns ännu och ingen `__()` används, exakt
 * som App\Notifications\MagicLinkNotification och av samma skäl — se den
 * klassens docblock. En on-demand-notifikation har dessutom ingen
 * `locale` att välja språk från; mottagaren finns inte som användare.
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
            ->subject('Du har blivit inbjuden till '.$this->container->name)
            ->line('Du har blivit inbjuden att dela "'.$this->container->name.'".')
            ->line('För att komma åt den behöver du skapa ett konto med den här e-postadressen och verifiera adressen — alla som läser något i systemet ska vara identifierade.')
            ->action('Öppna inbjudan', $this->url)
            ->line('Inbjudan går ut om '.Invitation::TTL_DAYS.' dagar.');
    }
}
