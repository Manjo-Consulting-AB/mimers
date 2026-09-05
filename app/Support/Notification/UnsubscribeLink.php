<?php

namespace App\Support\Notification;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Utfärdar avregistreringslänken — en signerad, tidsbegränsad URL till
 * bekräftelsesidan för avanmälan från en notistyp, se issue 32b och
 * [[Notiser]] § email_suppression.
 *
 * Signaturen är Laravel `signed`-middleware (Beslut 3): ingen egen tabell,
 * kolumn eller hash — `APP_KEY` signerar redan och middlewaren verifierar.
 * Länken bär mottagarens `ulid` och notistypen i sökvägen, aldrig
 * e-postadressen (Beslut 3): en adress i en URL hamnar i åtkomstloggar och
 * `Referer`.
 */
final class UnsubscribeLink
{
    /**
     * 30 dagar (Beslut 3): mejl läses sent, men en läckt vidarebefordrad URL
     * ska inte förbli en permanent nyckel.
     */
    private const TTL_DAYS = 30;

    public function for(User $user, string $type): string
    {
        return URL::temporarySignedRoute(
            'notifications.unsubscribe.confirm',
            now()->addDays(self::TTL_DAYS),
            ['user' => $user->ulid, 'type' => $type],
        );
    }
}
