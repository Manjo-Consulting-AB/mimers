<?php

namespace App\Support\Notification;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * `available_at` utifrån mottagarens tysta timmar, i mottagarens tidszon
 * (issue 31a § Beslut 5). Se [[Notiser]] § Tysta timmar och tidszon: `available_at`
 * sätts när notisen skapas, och kön plockar aldrig rader vars `available_at`
 * ligger i framtiden.
 *
 * Allt lagras i UTC — den här klassen använder tidszonen bara för att avgöra
 * VILKEN UTC-tidpunkt som är rätt ([[Datamodell – översikt]] § Konventioner),
 * och returnerar alltid en UTC-tidsstämpel.
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Notification\NotificationPreferences.
 */
final class QuietHours
{
    /**
     * Tidigaste leverans för en notis till `$user`, skapad vid `$now`.
     *
     * `$user = null` (ren kontonotis) har ingen mottagare med tysta timmar och
     * levereras direkt. Likaså när något av fönstrets klockslag saknas, och
     * när start === slut — ett "dygn av tystnad" vore en notis som aldrig går
     * ut, och den vanligaste vägen dit är ett formulär som skickade samma
     * värde två gånger (Beslut 5).
     */
    public function availableAt(?User $user, CarbonInterface $now): CarbonInterface
    {
        if ($user === null || $user->quiet_hours_start === null || $user->quiet_hours_end === null) {
            return $now;
        }

        $start = self::timeToSeconds($user->quiet_hours_start);
        $end = self::timeToSeconds($user->quiet_hours_end);

        if ($start === $end) {
            return $now;
        }

        $timezone = $this->timezoneFor($user);

        // setTimezone() flyttar inte tidpunkten, den byter vy — samma ögonblick
        // uttryckt i mottagarens tid (issue 31a § Att se upp med).
        $localNow = Carbon::instance($now)->setTimezone($timezone);
        $nowSeconds = self::clockToSeconds($localNow);

        if (! self::insideWindow($nowSeconds, $start, $end)) {
            return $now;
        }

        // Inuti fönstret: nästa quiet_hours_end i mottagarens tidszon,
        // tillbakaräknat till UTC. "Nästa" räknas från lokalt midnatt så att
        // ett fönster över midnatt (22:00–07:00) slutar nästa morgon.
        $nextEnd = (clone $localNow)->startOfDay()->addSeconds($end);

        if ($nextEnd->lte($localNow)) {
            $nextEnd->addDay();
        }

        return $nextEnd->setTimezone('UTC');
    }

    /**
     * Är `$time` (sekunder sedan midnatt, i mottagarens tid) inuti det tysta
     * fönstret `[$start, $end)`? Fönstret kan sträcka sig över midnatt:
     * `22:00`–`07:00` betyder 22:00 till 07:00 NÄSTA dag, `07:00`–`22:00`
     * betyder dagtid. Villkoret är alltså `start <= t < end` när `start < end`,
     * och `t >= start || t < end` när `start > end`. Det här är den enda rad i
     * klassen någon kommer att läsa fel, så den står som egen metod med
     * förklaring i stället för som ett inlinevillkor (issue 31a § Beslut 5).
     */
    private static function insideWindow(int $time, int $start, int $end): bool
    {
        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    /**
     * Mottagarens tidszon: användarens egen om den är satt, annars kontots,
     * annars appens. Samma fallande ordning som `User::preferredLocale()` har
     * för språk (Beslut 5). `user.timezone` är nullable, `account.timezone` är
     * det inte; `$user->accounts->first()` är godtyckligt när användaren har
     * flera konton — accepterat här, en gissning är bättre än UTC.
     */
    private function timezoneFor(User $user): string
    {
        $timezone = $user->timezone;

        if ($timezone === null && $user->accounts->isNotEmpty()) {
            // first() är godtyckligt när användaren har flera konton — accepterat
            // här, en gissning är bättre än UTC (samma resonemang som
            // User::preferredLocale()).
            $timezone = $user->accounts->first()->timezone;
        }

        return $timezone ?? config('app.timezone');
    }

    /**
     * Ett klockslag som DB:en ger som sträng (`'22:00:00'`, ibland utan
     * sekunder) till sekunder sedan midnatt. TIME-kolumnen är INTE en
     * tidsstämpel — att casta den till datetime skulle ge `1970-01-01
     * 22:00:00` och en tidszonskonvertering ingen bad om (issue 31a § Att se
     * upp med), så den läses som timme och minut.
     */
    private static function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) $parts[0]) * 3600
            + (isset($parts[1]) ? (int) $parts[1] : 0) * 60
            + (isset($parts[2]) ? (int) $parts[2] : 0);
    }

    /**
     * Ett Carbon (lokal tid) till sekunder sedan midnatt.
     */
    private static function clockToSeconds(CarbonInterface $time): int
    {
        return $time->hour * 3600 + $time->minute * 60 + $time->second;
    }
}
