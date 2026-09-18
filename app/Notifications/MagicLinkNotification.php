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
 * Texten ligger i `lang/en/notiser.php` och inte i klassen ([[ADR-0034
 * Engelska vid lansering]] § Beslut: ingen användarvänd sträng utanför
 * `lang/`). Den låg här som hårdkodad svenska fram till M13, och mötte då
 * Laravels engelska ramverkstext i samma utskick — det blandade
 * inloggningsmejl ADR-0034 nämner som beviset för att två språk inte hålls i
 * synk.
 *
 * Ingen locale sätts på mailet: `en` är enda katalogen, och en mottagare utan
 * konto har ingen `locale` att välja ur. Leveransloopens mottagare har en, och
 * den ägs av App\Support\Notification\LocaleResolver (ADR-0034).
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
            ->subject(trans('notiser.magic_link.subject'))
            ->line(trans('notiser.magic_link.line'))
            ->action(trans('notiser.magic_link.action'), $this->url)
            ->line(trans('notiser.magic_link.expires', ['minutes' => MagicLinkBroker::TTL_MINUTES]));
    }
}
