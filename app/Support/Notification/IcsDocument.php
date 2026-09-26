<?php

namespace App\Support\Notification;

use App\Models\ScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bygger ICS-texten för kalenderfeeden för hand, se issue 36b § Beslut 4 och
 * [[Notiser]] § ICS-kalenderfeed. Formatet som behövs är en handfull rader,
 * och kraven som gör det klurigt — radbrytning, radvikning, teckenflykt —
 * är tolv rader kod. Ett paket vore ett beroende för livet.
 *
 * Fyra krav som är fel i varje handskriven ICS-generator:
 *
 * - CRLF (`\r\n`) mellan alla rader. RFC 5545 är kategorisk och Outlook
 *   vägrar filen utan det.
 * - DTEND är dagen EFTER due_at. Heldagshändelsers slut är exklusivt; skriver
 *   man samma datum i båda blir händelsen nolldagar lång och försvinner i
 *   flera klienter.
 * - Teckenflykt i TEXT-värden: `\` → `\\`, `;` → `\;`, `,` → `\,`,
 *   radbrytning → `\n`. En båt som heter "Vega, S/Y" bryter annars filen.
 * - Radvikning vid 75 oktetter, fortsättningsrad inledd med ett mellanslag,
 *   och aldrig mitt i ett UTF-8-tecken. Räkna oktetter, inte tecken — ett
 *   `ö` är två.
 *
 * Klassens enda ansvar är texten. Språkvalet (och återställningen av
 * appens locale) ägs av CalendarFeedDownloadController, som översätter
 * `calendarName` och `overduePrefix` INNAN dokumentet byggs — schematitel,
 * itemnamn och containernamn är användarens egna data och översätts aldrig
 * (issue 36b § Beslut 6). UID:en måste vara stabil mellan hämtningar: samma
 * förekomst ger samma UID, annars visar klienten en ny händelse varje gång i
 * stället för att uppdatera den gamla (Beslut 4).
 *
 * **Dagen kommer in som argument, dokumentet hämtar ingen användare själv**
 * ([[ADR-0044 Användarens dag]] § Beslut 2): vilken dag `due_at` jämförs mot
 * för försenad-prefixet är FEEDENS användares dag, och den räknar
 * CalendarFeedDownloadController med `$feed->user->today()` — samma anrop som
 * löste upp omfånget. Hade dokumentet kallat `Carbon::today()` själv hade
 * prefixet följt serverns klocka och sagt "försenad" om en uppgift som
 * användaren ännu ser som dagens.
 */
final class IcsDocument
{
    /**
     * Högsta antal oktetter per fysisk rad, RFC 5545 § 3.1.
     */
    private const MAX_LINE_LENGTH = 75;

    /**
     * @param  Collection<int, ScheduleOccurrence>  $occurrences  containerns öppna förekomster
     * @param  Carbon  $today  feedens användares kalenderdag, se klassdocblocket
     */
    public function __construct(
        private readonly string $calendarName,
        private readonly string $overduePrefix,
        private readonly Collection $occurrences,
        private readonly Carbon $today,
    ) {}

    public function render(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $dtstamp = Carbon::now()->utc()->format('Ymd\THis\Z');

        $rader = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // Produktidentifieraren är engelsk som resten av dokumentet:
            // `SV` och "Kalenderfeed" var svenskt i en fil vars övriga rader
            // kommer ur `lang/en/` ([[ADR-0034 Engelska vid lansering]]).
            'PRODID:-//Mimers//Calendar feed//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($this->calendarName),
        ];

        foreach ($this->occurrences as $occurrence) {
            $dueAt = $occurrence->due_at;
            $summary = ($dueAt->lessThan($this->today) ? $this->overduePrefix : '').$occurrence->schedule->title;

            array_push($rader,
                'BEGIN:VEVENT',
                'UID:'.$occurrence->ulid.'@'.$host,
                'DTSTAMP:'.$dtstamp,
                'DTSTART;VALUE=DATE:'.$dueAt->format('Ymd'),
                'DTEND;VALUE=DATE:'.$dueAt->copy()->addDay()->format('Ymd'),
                'SUMMARY:'.$this->escape($summary),
                'DESCRIPTION:'.$this->escape($occurrence->schedule->item->name),
                'END:VEVENT',
            );
        }

        $rader[] = 'END:VCALENDAR';

        return $this->fold($rader);
    }

    /**
     * Viker varje logisk rad till fysiska rader på högst 75 oktetter och
     * fogar ihop allt med CRLF. En oktett är en byte — `strlen()`, inte
     * `mb_strlen()`: ett `ö` är två oktetter. Fortsättningsrader inleds med
     * ett mellanslag som RFC 5545 föreskriver; mellanslaget är en del av
     * radens 75 oktetter, så innehållet i en fortsättningsrad får vara högst
     * 74 oktetter (därav `MAX_LINE_LENGTH - 1`).
     *
     * @param  list<string>  $rader
     */
    private function fold(array $rader): string
    {
        $fysiska = [];

        foreach ($rader as $rad) {
            if (strlen($rad) <= self::MAX_LINE_LENGTH) {
                $fysiska[] = $rad;

                continue;
            }

            $bitar = $this->utf8Bitar($rad, self::MAX_LINE_LENGTH - 1);

            $fysiska[] = array_shift($bitar);

            foreach ($bitar as $bit) {
                $fysiska[] = ' '.$bit;
            }
        }

        return implode("\r\n", $fysiska)."\r\n";
    }

    /**
     * Delar en sträng i bitar om högst $max OTTETTER utan att klyva en
     * UTF-8-sekvens. Pekar byten på klyvningspunkten på en fortsättningsbyte
     * (0b10xxxxxx) klyver vi ett flerbytetecken mitt itu och går bakåt till
     * nästa teckenledande byte — fortsättningsbyten kan aldrig inleda ett
     * giltigt tecken, så bakåtsvepet stannar på en teckengräns.
     *
     * @return list<string>
     */
    private function utf8Bitar(string $värde, int $max): array
    {
        $bitar = [];

        while (strlen($värde) > $max) {
            $klyv = $max;

            while ($klyv > 0 && (ord($värde[$klyv]) & 0xC0) === 0x80) {
                $klyv--;
            }

            $bitar[] = substr($värde, 0, $klyv);
            $värde = substr($värde, $klyv);
        }

        if ($värde !== '') {
            $bitar[] = $värde;
        }

        return $bitar;
    }

    /**
     * Teckenflykt för TEXT-värden (RFC 5545 § 3.3.11). Radbrytningar
     * normaliseras först så att CRLF och ensamt CR blir samma sak som LF.
     * Kolon flyktas inte: i ett egenskapsvärde är kolon en vanlig texttecken
     * och bara avgränsaren mellan namn och värde är helig.
     */
    private function escape(string $värde): string
    {
        $värde = str_replace(["\r\n", "\r"], "\n", $värde);

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $värde,
        );
    }
}
