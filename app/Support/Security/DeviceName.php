<?php

namespace App\Support\Security;

/**
 * Webbläsarsträngen tolkad till ett kort enhetsnamn — *Firefox · macOS* — se
 * [[ADR-0043 Tre loggar]] § Säkerhetsloggen.
 *
 * **En enkel regel utan nytt beroende.** En fullständig
 * `User-Agent`-databas är ett paket som måste uppdateras för att fortsätta
 * vara sant, och den svarar på fler frågor än loggen ställer. Loggen behöver
 * veta *ungefär vilken enhet* — issue 117 visar den för användaren, som
 * känner igen sin egen dator — och den frågan besvarar en handfull
 * `str_contains()`.
 *
 * **Strängen kastas efter tolkningen.** Returvärdet är en sträng ur en sluten
 * mängd, byggd av webbläsare och operativsystem, och ingenting av det
 * användarens webbläsare själv har skrivit följer med: ingen version, ingen
 * plattformssträng, inget tillägg. Att spara hela strängen vore att spara en
 * fingeravtrycksyta som pekar ut en enskild enhet mycket skarpare än en
 * pseudonym gör.
 *
 * **Ordningen mellan `str_contains()`-erna är kontraktet.** `Edg/` före
 * `Chrome/` (Edge säger att den är Chrome), `Chrome/` före `Safari/` (Chrome
 * säger att den är Safari), och `Android` och `iPhone` före `Linux` och
 * `Mac OS X` (Android säger att den är Linux, iPhone att den är Mac OS X).
 * Byt inte ordning utan att byta testet.
 *
 * Okänd webbläsare eller okänt operativsystem ger `null` för den delen, och
 * en sträng helt utan igenkännbar del ger `null` — issue 117 renderar det som
 * *okänd enhet*. En halv tolkning (*Chrome* utan operativsystem) är bättre än
 * ingen: den säger fortfarande något sant.
 */
class DeviceName
{
    /**
     * Avgränsaren mellan webbläsare och operativsystem.
     */
    public const SEPARATOR = ' · ';

    /**
     * Det tolkade enhetsnamnet, eller `null` när ingenting gick att tolka
     * (ingen sträng alls, eller en klient vi inte känner igen).
     */
    public static function from(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $delar = array_filter([
            self::browser($userAgent),
            self::operatingSystem($userAgent),
        ]);

        if ($delar === []) {
            return null;
        }

        return implode(self::SEPARATOR, $delar);
    }

    /**
     * Webbläsaren, i den ordning som krävs för att svaret ska bli rätt — se
     * klassdocblocket.
     */
    private static function browser(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/'), str_contains($userAgent, 'EdgA/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    /**
     * Operativsystemet. `iPhone`, `iPad` och `Android` står före `Mac OS X`
     * och `Linux` med flit: de två senare finns med i de förras strängar.
     */
    private static function operatingSystem(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
