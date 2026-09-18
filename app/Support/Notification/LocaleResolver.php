<?php

namespace App\Support\Notification;

use App\Models\User;

/**
 * Översätter mottagarens locale till en språkkatalog, se issue 32a § Beslut 3,
 * [[ADR-0013 Språk och i18n]] och [[ADR-0034 Engelska vid lansering]].
 * Språkkatalogerna heter `sv` och `en`, inte `sv_SE` och `en_GB`: användarens
 * `locale` är en fullständig locale-sträng (`sv_SE`, `en_GB`), men
 * `config('app.fallback_locale')` är `en` — namnger man katalogerna med den
 * fullständiga strängen pekar reservspråket på en katalog som inte finns, och
 * en saknad nyckel renderas som `notiser.task_due.subject` i ett riktigt mejl.
 *
 * **Bara levererade kataloger väljs.** `en` är enda katalogen ADR-0034
 * levererar, så en användare med `sv_SE` får `en` — samma svar som en
 * användare utan locale. Regeln är katalogen och inte språklistan: den som
 * lägger till ett språk lägger till en katalog under `lang/`, och då väljs
 * den igen utan att någon rör den här klassen.
 *
 * Klassen äger BARA översättningen till en katalog. Ordningen mellan användare
 * och konto ägs av `User::preferredLocale()` (issue 3) — den här klassen
 * normaliserar bara resultatet. Klassen är en injicerbar stödklass, samma form
 * som App\Support\Notification\NotificationPreferences.
 */
final class LocaleResolver
{
    /** Katalogen en användare utan levererad locale möts av. */
    private const STANDARD = 'en';

    /**
     * Katalognamnet för en användare. `preferredLocale()` först, sedan de två
     * första tecknen i gemener — och resultatet måste ha en katalog under
     * `lang/`, annars blir det `en`. `null` (användare utan locale och utan
     * entydigt konto) ger `en`. Se [[ADR-0034 Engelska vid lansering]].
     */
    public function forUser(?User $user): string
    {
        $locale = $user?->preferredLocale();

        if ($locale === null) {
            return self::STANDARD;
        }

        $katalog = strtolower(substr($locale, 0, 2));

        return is_dir(lang_path($katalog)) ? $katalog : self::STANDARD;
    }
}
