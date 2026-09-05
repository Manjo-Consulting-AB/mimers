<?php

namespace App\Support\Notification;

use App\Mail\NotificationMail;
use App\Models\EmailSuppression;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * E-postkanalen — gör en `notification_delivery`-rad till ett skickat mejl på
 * mottagarens språk, se issue 32a och [[Notiser]] § E-post. Kanalen är en
 * utbytbar adapter i [[ADR-0010 Notisarkitektur]]s mening: den tar en rad och
 * skickar eller kastar, och vet ingenting om loopen som plockar raderna.
 *
 * Kanalen rör INTE leveransradens `status`, `attempts`, `last_error` eller
 * `sent_at` — de ägs av leveransloopen (34a), och en klass som både skickar
 * och bokför blir omöjlig att testa isolerat (issue 32a § Beslut 8). En
 * undertryckt mottagare (33a) kastar AddressSuppressedException före språkval
 * och rendering; 34a fångar den och sätter `suppressed`.
 *
 * Språket väljs av LocaleResolver och bärs av själva mailet via
 * `$mailable->locale()` — Laravel sätter och återställer appens locale runt
 * renderingen (issue 32a § Beslut 3). Kanalen byter aldrig appens locale
 * själv; en kvarglömd locale i en loop över många mottagare (34a) vore precis
 * den bugg beslutet finns för.
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Notification\NotificationPreferences.
 */
final class EmailChannel
{
    public function __construct(
        private readonly LocaleResolver $locales,
    ) {}

    public function send(NotificationDelivery $delivery): void
    {
        $notification = $delivery->notification;
        $user = $notification->user;

        if ($user === null) {
            // Ett programmeringsfel, inte ett leveransfel (Beslut 8):
            // CreateNotification ska inte ha skapat en e-postleverans för en
            // notis utan mottagare, och 31a:s kanalval gör inte det. Kastet
            // här är den enda platsen där felet kan upptäckas.
            throw new InvalidArgumentException('En e-postleverans kräver en mottagande användare, men notisen har user_id = null.');
        }

        // En undertryckt adress kostar inget mallanrop (Beslut 3): frågan
        // ligger före språkvalet och före renderingen, så ordningen gör testet
        // entydigt — ingenting hann hända. Kanalen kastar; det är 34a:s loop
        // som bokför `status = 'suppressed'` (Beslut 4). Loggen vid kastet
        // finns där för att spåret ska finnas den dag regeln mot
        // kontolivscykeln beslutas (Beslut 6).
        if (EmailSuppression::isSuppressed($user->email)) {
            Log::warning('notification.address_suppressed', [
                'account_ulid' => $notification->account->ulid,
                'user_ulid' => $user->ulid,
                'reason' => EmailSuppression::reasonFor($user->email),
            ]);

            throw new AddressSuppressedException($user->email);
        }

        $locale = $this->locales->forUser($user);

        // En okänd typ kastar i stället för att skicka ett tomt mejl (Beslut
        // 6): M6 lägger till `loan.due` och kommer att glömma språkfilen om
        // felet är tyst. Saknar den aktiva katalogen typen är det samma fel
        // som en okänd typ — de två språkfilerna ska ha samma nyckeluppsättning
        // (Beslut 5).
        $key = str_replace('.', '_', $notification->type);
        if (! Lang::has("notiser.{$key}", $locale, false)) {
            throw UnknownNotificationTypeException::forType($notification->type);
        }

        // Språket sätts på PendingMail, som lägger det på mailet innan det
        // skickas. `Mail::to($user)` skulle annars välja råa `preferredLocale()`
        // (`sv_SE`), som ingen katalog heter (Beslut 3).
        Mail::to($user)->locale($locale)->send(
            new NotificationMail($user, $notification->type, $notification->payload)
        );
    }
}
