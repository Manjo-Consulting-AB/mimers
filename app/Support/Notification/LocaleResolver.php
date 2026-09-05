<?php

namespace App\Support\Notification;

use App\Models\User;

/**
 * Översätter mottagarens locale till en språkkatalog, se issue 32a § Beslut 3
 * och [[ADR-0013 Språk och i18n]]. Språkkatalogerna heter `sv` och `en`, inte
 * `sv_SE` och `en_GB`: användarens `locale` är en fullständig locale-sträng
 * (`sv_SE`, `en_GB`), men `config('app.fallback_locale')` är `en` — namnger
 * man katalogerna med den fullständiga strängen pekar reservspråket på en
 * katalog som inte finns, och en saknad nyckel renderas som
 * `notiser.task_due.subject` i ett riktigt mejl.
 *
 * Klassen äger BARA översättningen till en katalog. Ordningen mellan användare
 * och konto ägs av `User::preferredLocale()` (issue 3) — den här klassen
 * normaliserar bara resultatet. Klassen är en injicerbar stödklass, samma form
 * som App\Support\Notification\NotificationPreferences.
 */
final class LocaleResolver
{
    /**
     * `'sv'` | `'en'` för en användare. Regeln (issue 32a § Beslut 3):
     * `preferredLocale()` först, sedan de två första tecknen i gemener. Är
     * resultatet inte `sv` blir det `en`. `null` (användare utan locale och
     * utan entydigt konto) ger `en`.
     */
    public function forUser(?User $user): string
    {
        $locale = $user?->preferredLocale();

        if ($locale === null) {
            return 'en';
        }

        return strtolower(substr($locale, 0, 2)) === 'sv' ? 'sv' : 'en';
    }
}
