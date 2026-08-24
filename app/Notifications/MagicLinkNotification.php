<?php

namespace App\Notifications;

use App\Support\Auth\MagicLinkBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mejlet med inloggningslänken, se issue 5. Mejlleverans är inte den här
 * issuen — använder Laravels mailer som den redan är konfigurerad
 * (`MAIL_MAILER` i .env, `config/mail.php` rörs inte här), Postmark kopplas
 * in i issue 32. Skickas bara från App\Support\Auth\MagicLinkBroker::issue(),
 * som i sin tur bara anropas när en användare med adressen redan finns —
 * se issue #18 § Beslut som redan är fattade punkt 6.
 *
 * Serverrenderat innehåll väljer språk från mottagarens `locale`, inte
 * requestens `Accept-Language` — se AGENTS.md § Sådant som är lätt att
 * göra fel. `User::locale` kan vara NULL (kontots värde gäller då i
 * stället, se App\Models\User), men den här notifikationen känner bara
 * till e-postadressen som skickades in, inte kontots inställningar — att
 * slå upp rätt locale hör inte till den här issuen (ingen i18n-fil finns
 * ännu, se AGENTS.md § Dokumentationen om att inte bygga i förväg det som
 * inte behövs). `MailMessage` renderar med Laravels standardspråk tills
 * i18n för mejl byggs.
 */
class MagicLinkNotification extends Notification
{
    use Queueable;

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
            ->subject('Din inloggningslänk')
            ->line('Klicka på länken nedan för att logga in.')
            ->action('Logga in', $this->url)
            ->line('Länken slutar fungera om '.MagicLinkBroker::TTL_MINUTES.' minuter, och kan bara användas en gång.');
    }
}
