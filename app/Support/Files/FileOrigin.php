<?php

namespace App\Support\Files;

/**
 * Filoriginet — den egna subdomänen användarfiler levereras från, se
 * [[ADR-0019 Filleverans]] § Uppföljning 2026-09-15.
 *
 * `config('files.url')` är osatt tills handpåläggningen sätter den, och så
 * länge den är det beter sig appen exakt som i dag: leveransen ligger kvar på
 * appdomänen och allt är `attachment` (uppföljningen 2026-08-31). Det är
 * därför hela den här klassen kan svara "ingen egen origin" och koden runt
 * omkring faller tillbaka på det gamla beteendet utan en enda gren till.
 *
 * **Ett värde vars värdnamn är appens eget räknas inte som en egen origin.**
 * En felkonfiguration ska bli en tråkigare leverans, aldrig en osäker: pekar
 * `files.url` på appen själv finns ingen annan origin att gömma en `inline`-
 * leverans bakom, och då gäller `attachment` utan undantag. Samma villkor
 * styr om leveransrutten registreras alls (routes/web.php) — hade den
 * registrerats på appens eget värdnamn hade den kolliderat med
 * `files.download`, som delar både metod och sökväg.
 */
final class FileOrigin
{
    /**
     * Filoriginets bas-URL, eller null när ingen är satt.
     */
    public static function url(): ?string
    {
        $url = config('files.url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * Filoriginets värdnamn — eller null när leveransen ligger på appdomänen,
     * antingen för att `files.url` är osatt eller för att den pekar på appen
     * själv. Null betyder alltså "ingen egen origin", inte "okänt".
     */
    public static function host(): ?string
    {
        $url = self::url();

        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $host === parse_url((string) config('app.url'), PHP_URL_HOST) ? null : $host;
    }

    /**
     * Appens eget origin, utan sökväg och utan avslutande snedstreck.
     * `frame-ancestors` i leveransens CSP pekar på det här värdet: bara appen
     * får rama in en levererad PDF, se issue 61a § Beslut 6.
     */
    public static function appOrigin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
