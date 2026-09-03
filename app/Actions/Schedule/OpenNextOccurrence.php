<?php

namespace App\Actions\Schedule;

use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Öppnar nästa förekomst för ett schema — den ENDA vägen in i
 * `schedule_occurrence`, se issue 22. En förekomst skapas aldrig av en
 * klient; den är systemets bokföring av ett schema.
 *
 * Anropas på exakt tre ställen (Beslut 3): när ett schema skapas med
 * `is_active = true` (App\Http\Controllers\Api\ScheduleController::store, i
 * samma transaktion som schemat), när ett pausat schema aktiveras och saknar
 * en öppen förekomst (update), och när en förekomst stängs (issue 22b — som
 * anropar samma Action med `$from` satt).
 *
 * Returvärdet är medvetet nullbart: `recurrence_type: none` har ingen nästa
 * när engångsförekomsten väl är stängd, och ett schema som redan har en öppen
 * förekomst ska inte få en andra. Det är dokumenterade returvärden, inte
 * undantag (Beslut 4, § Att se upp med).
 *
 * Invarianten — exakt en öppen förekomst per aktivt schema, även under
 * samtidighet — hålls här i kod (Beslut 7): transaktion, `lockForUpdate()` på
 * SCHEMATADEN (som alltid finns), kontroll att ingen öppen förekomst finns,
 * och skapande först då. Låset ligger aldrig på förekomsttabellen — en tom
 * mängd rader är ett gap lock i MySQL, exakt den fällan issue 16a gick i.
 */
class OpenNextOccurrence
{
    public function handle(Schedule $schedule, ?Carbon $from = null): ?ScheduleOccurrence
    {
        return DB::transaction(function () use ($schedule, $from): ?ScheduleOccurrence {
            $låst = $schedule->newQuery()->whereKey($schedule->id)->lockForUpdate()->first();

            if ($låst === null) {
                return null;
            }

            if ($låst->occurrences()->where('status', ScheduleOccurrence::STATUS_OPEN)->exists()) {
                return null;
            }

            $dueAt = $this->dueAt($låst, $from);

            if ($dueAt === null) {
                return null;
            }

            $occurrence = new ScheduleOccurrence;
            $occurrence->schedule_id = $låst->id;
            // Glappet fryses i raden: ändras `lead_days` senare rör det nästa
            // förekomst, inte den öppna (Beslut 5).
            $occurrence->visible_from = $dueAt->copy()->subDays($låst->lead_days);
            $occurrence->due_at = $dueAt;
            $occurrence->status = 'open';
            $occurrence->save();

            // Arvet från schemanivån (23b § Beslut 2), i SAMMA transaktion som
            // förekomsten skapas: för varje schema den nya förekomstens schema
            // beror på, koppla den till motpartens NUVARANDE öppna förekomst.
            // Arvet är en engångshändelse vid skapandet — ett schemaberoende
            // som läggs till senare rör den redan öppna förekomsten inte.
            //
            // Ordningen är den som gör arvet tryggt: anropas det här inifrån
            // CloseOccurrence har den stängda förekomsten redan skrivits (steg
            // 2 i flödet) INNAN den här Actionen körs (steg 4) — en läsning av
            // motpartens status här ser alltså aldrig en rad som håller på att
            // stängas i samma transaktion (23b § Att se upp med).
            $this->inheritScheduleDependencies($låst, $occurrence);

            return $occurrence;
        });
    }

    /**
     * Ärver schemanivåns beroenden till en NY förekomst (23b § Beslut 2):
     * schemats `schedule_dependency`-rader säger vilka scheman det här
     * schemat väntar på; var och en av dem kopplas till motpartens NUVARANDE
     * öppna förekomst — i en fråga, aldrig en per beroende.
     *
     * Har motparten ingen öppen förekomst skrivs ingen rad. Ett mjukraderat
     * eller pausat schema räknas inte ens om dess öppna rad ligger kvar: en
     * beroenderad mot något som aldrig kommer att stängas är en uppgift
     * användaren aldrig kan bocka av, och ett beroende som inte kan uppfyllas
     * är värre än inget beroende (Beslut 2). Mjukraderade scheman faller ut
     * genom SoftDeletes globala scope på `schedule`-relationen.
     */
    private function inheritScheduleDependencies(Schedule $schedule, ScheduleOccurrence $occurrence): void
    {
        $dependedScheduleIds = ScheduleDependency::query()
            ->where('schedule_id', $schedule->id)
            ->pluck('depends_on_schedule_id');

        if ($dependedScheduleIds->isEmpty()) {
            return;
        }

        $openTargets = ScheduleOccurrence::query()
            ->whereIn('schedule_id', $dependedScheduleIds)
            ->where('status', ScheduleOccurrence::STATUS_OPEN)
            ->whereHas('schedule', fn ($query) => $query->where('is_active', true))
            ->get(['id']);

        foreach ($openTargets as $target) {
            $dependency = new OccurrenceDependency;
            $dependency->occurrence_id = $occurrence->id;
            $dependency->depends_on_occurrence_id = $target->id;
            $dependency->save();
        }
    }

    /**
     * Förfallodatumet för nästa förekomst, per återkommandetyp (Beslut 4):
     *
     * - `none`: `anchor_date`, men bara om schemat aldrig öppnat en förekomst
     *   — när engångsuppgiften väl är stängd finns ingen nästa, inte heller
     *   vid en senare återaktivering (Beslut 3).
     * - `fixed`: räknar ALLTID från kalendern (`anchor_date`), framflyttat i
     *   seriens steg tills det inte längre ligger i det förflutna — oavsett
     *   `$from` och oavsett när jobbet gjordes.
     * - `interval`: `anchor_date` för den första förekomsten; `$from`
     *   (datumdelen) plus intervallet när 22b anropar med `completed_at`.
     *
     * @return Carbon|null null när det inte finns någon nästa förekomst.
     */
    private function dueAt(Schedule $schedule, ?Carbon $from): ?Carbon
    {
        $anchor = $schedule->anchor_date;

        if ($schedule->recurrence_type === 'none') {
            if ($anchor === null) {
                throw $this->programmeringsfel('Ett schema med recurrence_type none utan anchor_date kan inte öppna en förekomst.');
            }

            if ($schedule->occurrences()->exists()) {
                return null;
            }

            return $anchor->copy();
        }

        if ($schedule->recurrence_type === 'interval') {
            if ($from !== null) {
                return $this->addInterval($from->copy()->startOfDay(), $schedule);
            }

            if ($anchor === null) {
                throw $this->programmeringsfel('Ett schema med recurrence_type interval utan anchor_date kan inte öppna sin första förekomst.');
            }

            return $anchor->copy();
        }

        // recurrence_type === 'fixed'
        if ($anchor === null) {
            throw $this->programmeringsfel('Ett schema med recurrence_type fixed utan anchor_date kan inte öppna en förekomst.');
        }

        return $this->nextCalendarDue($anchor, $schedule);
    }

    /**
     * Lägger schemats intervall till $date. `month`/`year` stegas med
     * `addMonthsNoOverflow()`/`addYearsNoOverflow()`, aldrig `addMonths()` —
     * 31 januari plus en månad är 28 februari, inte 3 mars (Beslut 6).
     */
    private function addInterval(Carbon $date, Schedule $schedule): Carbon
    {
        $unit = $schedule->interval_unit;
        $count = $schedule->interval_count;

        if (! is_int($count) || $count < 1) {
            throw $this->programmeringsfel('Ett schema med recurrence_type fixed/interval utan positivt interval_count kan inte öppna en förekomst.');
        }

        return match ($unit) {
            'day' => $date->addDays($count),
            'week' => $date->addWeeks($count),
            'month' => $date->addMonthsNoOverflow($count),
            'year' => $date->addYearsNoOverflow($count),
            default => throw $this->programmeringsfel('Okänd interval_unit på schemat: '.($unit ?? 'null')),
        };
    }

    /**
     * Nästa `fixed`-förfall räknat från KALENDERN: `anchor_date` framflyttat
     * med `interval_count × interval_unit` tills `due_at >= today` (Beslut 4).
     * Ett `anchor_date` som redan är idag används som det är.
     *
     * Framflyttningen RÄKNAS, inte loopas dag för dag (§ Att se upp med): för
     * dag/vecka direkt aritmetiskt på dagskillnaden, för månad/år som hela
     * månader mellan datumen (med en korrektion på sin höjd ett steg för
     * månadssluts-klampningen). Ett `anchor_date` från 1990 med dagsintervall
     * blir inte 13 000 varv i en loop.
     */
    private function nextCalendarDue(Carbon $anchor, Schedule $schedule): Carbon
    {
        $today = Carbon::today();
        $unit = $schedule->interval_unit;
        $count = $schedule->interval_count;

        if (! is_int($count) || $count < 1) {
            throw $this->programmeringsfel('Ett schema med recurrence_type fixed/interval utan positivt interval_count kan inte öppna en förekomst.');
        }

        if (! $anchor->lessThan($today)) {
            return $anchor->copy();
        }

        if ($unit === 'day' || $unit === 'week') {
            $periodDays = $unit === 'day' ? $count : $count * 7;
            $diffDays = (int) $anchor->diffInDays($today);
            $steps = (int) ceil($diffDays / $periodDays);

            return $anchor->copy()->addDays($steps * $periodDays);
        }

        $wholeMonths = $this->wholeMonthsBetween($anchor, $today);

        if ($unit === 'month') {
            $steps = max(1, intdiv($wholeMonths, $count));
            $due = $anchor->copy()->addMonthsNoOverflow($steps * $count);

            // Månadssluts-klampningen kan lägga ett steg utöver det räknade —
            // ett fåtal korrektioner, aldrig en loop över dagar.
            while ($due->lessThan($today)) {
                $steps++;
                $due = $anchor->copy()->addMonthsNoOverflow($steps * $count);
            }

            return $due;
        }

        $steps = max(1, intdiv($wholeMonths, $count * 12));
        $due = $anchor->copy()->addYearsNoOverflow($steps * $count);

        while ($due->lessThan($today)) {
            $steps++;
            $due = $anchor->copy()->addYearsNoOverflow($steps * $count);
        }

        return $due;
    }

    /**
     * Antalet HELA kalendermånader från $from till $to, så att
     * `$from + $result` inte passerar $to. Räknas på datumkomponenterna med
     * en korrektion för att `addMonthsNoOverflow` klampat mot månadens sista
     * dag (den 30:e januari till den 1:a mars är en hel månad, inte två).
     */
    private function wholeMonthsBetween(Carbon $from, Carbon $to): int
    {
        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month);

        if ($from->copy()->addMonthsNoOverflow($months)->greaterThan($to)) {
            $months--;
        }

        return $months;
    }

    private function programmeringsfel(string $meddelande): RuntimeException
    {
        return new RuntimeException($meddelande);
    }
}
