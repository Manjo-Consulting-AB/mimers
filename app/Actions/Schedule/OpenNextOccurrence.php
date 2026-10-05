<?php

namespace App\Actions\Schedule;

use App\Actions\Notification\ReleaseTaskReminders;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Öppnar nästa förekomst för ett schema — den ENDA vägen in i
 * `schedule_occurrence`, se issue 22. En förekomst skapas aldrig av en
 * klient; den är systemets bokföring av ett schema.
 *
 * **`handle()` anropas på exakt tre ställen** (Beslut 3): när ett schema
 * skapas med `is_active = true`
 * (App\Http\Controllers\Api\ScheduleController::store, i samma transaktion
 * som schemat), när ett pausat schema aktiveras och saknar en öppen förekomst
 * (update), och när en förekomst stängs (issue 22b — som anropar samma Action
 * med `$from` satt).
 *
 * **`moveOpen()` skapar aldrig en förekomst.** Den flyttar den redan öppna
 * raden när schemats återkommande ändras (M24, issue 699), och är därmed den
 * enda vägen in som RÖR en rad som finns sedan förut — men den lägger aldrig
 * till en. Samma ULID, samma `id`, inga beroenden rivs (Beslut 3 och 7).
 *
 * Bara avslutsanropet har en stängd förekomst att förhålla sig till, och
 * skickar då också dess `due_at` (132): nästa förfall ligger alltid strikt
 * efter det stängda. Skapande och återaktivering har ingen stängd förekomst,
 * skickar ingen, och räknar som förut.
 *
 * **Dagen är ett argument** ([[ADR-0044 Användarens dag]] § Beslut 3, issue
 * 517): `fixed` räknar mot den dag anroparen skickar, aldrig mot serverns
 * `Carbon::today()`. Åtgärden hämtar ingen användare själv — den som agerar
 * räknar ut sin dag med `User::today()` och skickar den. Anroparen skickar
 * också `$from` som en DAG: för `complete` den lokala dagen för
 * `completed_at`, räknad i samma tidszon som dagen. Se CloseOccurrence, där
 * båda räknas ut.
 *
 * Returvärdet är medvetet nullbart: `recurrence_type: none` har ingen nästa
 * när engångsförekomsten väl är stängd, och ett schema som redan har en AKTIV
 * förekomst ska inte få en andra. Det är dokumenterade returvärden, inte
 * undantag (Beslut 4, § Att se upp med).
 *
 * **En engångsuppgift utan `anchor_date` öppnar en förekomst UTAN datum**
 * (ADR-0052 § 3): `due_at` och `visible_from` blir null, och raden bär bara
 * sin titel och sin lista. Ännu finns ingen väg in för användaren — den
 * kommer i issue 236 och 238 — så raderna skapas av proven. `fixed` och
 * `interval` kräver fortfarande `anchor_date` och är ett programmeringsfel
 * utan det: utan ett första datum finns ingen serie att räkna nästa förfall ur.
 *
 * Invarianten — exakt en aktiv förekomst per aktivt schema, även under
 * samtidighet — hålls här i kod (Beslut 7): transaktion, `lockForUpdate()` på
 * SCHEMATADEN (som alltid finns), kontroll att ingen aktiv förekomst finns,
 * och skapande först då. Låset ligger aldrig på förekomsttabellen — en tom
 * mängd rader är ett gap lock i MySQL, exakt den fällan issue 16a gick i.
 */
class OpenNextOccurrence
{
    public function __construct(
        private readonly ReleaseTaskReminders $releaseTaskReminders,
    ) {}

    /**
     * @param  Carbon  $today  Den agerandes kalenderdatum, midnatt i appens
     *                         tidszon — `User::today()` för den som agerar.
     *                         Dagen avgör vad `fixed` räknar mot (Beslut 3).
     *                         Stängs en förekomst utan användare faller dagen
     *                         tillbaka på containerns ägarkonto,
     *                         `account.timezone`; anroparen räknar ut den.
     * @param  Carbon|null  $from  Nästa förfalls utgångspunkt: den lokala dagen
     *                             för `completed_at` vid `complete`, den
     *                             överhoppade förekomstens `due_at` vid `skip`.
     *                             Null vid skapande och återaktivering.
     * @param  Carbon|null  $closedDueAt  Den stängda förekomstens `due_at`, så att
     *                                    nästa förfall hamnar strikt efter den (132).
     *                                    Null när ingen förekomst har stängts.
     * @param  string|null  $gtdList  Listan den nya förekomsten ska bära, när
     *                                anroparen väljer den uttryckligen. Bara
     *                                App\Actions\Schedule\CreateSchedule gör det:
     *                                den första förekomsten får användarens val,
     *                                inte schemats förval — väljs *Waiting* blir
     *                                förekomsten `waiting` medan förvalet blir
     *                                `next` (ADR-0054 § 5). Null — alla andra
     *                                anropare — ger förvalet, och null när
     *                                schemat ligger i inboxen (§ 5 och 6).
     */
    public function handle(Schedule $schedule, Carbon $today, ?Carbon $from = null, ?Carbon $closedDueAt = null, ?string $gtdList = null): ?ScheduleOccurrence
    {
        return DB::transaction(function () use ($schedule, $today, $from, $closedDueAt, $gtdList): ?ScheduleOccurrence {
            $lockedSchedule = $schedule->newQuery()->whereKey($schedule->id)->lockForUpdate()->first();

            if ($lockedSchedule === null) {
                return null;
            }

            if ($lockedSchedule->occurrences()->active()->exists()) {
                return null;
            }

            $dueAt = $this->dueAt($lockedSchedule, $today, $from, $closedDueAt);

            // `due_at === null` betyder två saker för `none` (ADR-0052 § 3):
            // en engångsuppgift UTAN datum öppnar en förekomst ändå, medan en
            // engångsuppgift som redan öppnat sin förekomst inte har någon
            // nästa. Skillnaden är om schemat har en förekomst sedan förut —
            // har det en stängd finns ingen nästa, har det ingen alls är raden
            // en uppgift utan datum.
            $dateless = $dueAt === null && ! $lockedSchedule->occurrences()->exists();

            if ($dueAt === null && ! $dateless) {
                return null;
            }

            $occurrence = new ScheduleOccurrence;
            $occurrence->schedule_id = $lockedSchedule->id;
            // Glappet är förfallet minus schemats `lead_days`. Det räknas om
            // av moveOpen() om `lead_days` ändras medan raden är öppen (M24,
            // issue 699 § Beslut 2) — det är inte fryst. Utan förfall finns
            // inget glapp: båda datumen är null (ADR-0052 § 3).
            $occurrence->visible_from = $dueAt?->copy()->subDays($lockedSchedule->lead_days);
            $occurrence->due_at = $dueAt;
            $occurrence->status = ScheduleOccurrence::STATUS_OPEN;
            // Listan en ny förekomst hamnar i (ADR-0052 § 2, M27 · issue 244):
            // schemats förval, så att en återkommande uppgift bara behöver
            // bearbetas en gång — nästa års service ärver listan utan att någon
            // rör den. Anroparens uttryckliga val går före: den allra första
            // förekomsten får användarens lista, inte ett förval.
            //
            // **Ligger schemat i inboxen är listan null** (ADR-0054 § 5): en
            // obearbetad uppgift har ingen lista, och förvalet är null av samma
            // skäl. Kontrollen står här och inte bara i anroparen, så att en
            // återaktivering eller en stängning av en inbox-uppgift inte kan
            // ge nästa förekomst en lista ingen har valt.
            //
            // **Utanför inboxen har varje förekomst ett värde** (ADR-0054 § 5,
            // andra punkten). Är förvalet tömt — redigeringssidan tillåter det,
            // och en återaktivering kan möta ett schema som aldrig fick något —
            // faller raden på `next` i stället för på null: null betyder *i
            // inboxen* sedan den här issuen, och en uppgift på ett riktigt item
            // får inte läsas som obearbetad.
            $occurrence->gtd_list = $this->inInbox($lockedSchedule)
                ? null
                : ($gtdList ?? $lockedSchedule->default_gtd_list ?? ScheduleOccurrence::GTD_NEXT);
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
            $this->inheritScheduleDependencies($lockedSchedule, $occurrence);

            return $occurrence;
        });
    }

    /**
     * Ligger schemat på ett inbox-item ([[ADR-0054 Inboxen]] § 5)? Frågan
     * ställs på en färsk läsning av itemet och dess container — `$schedule`
     * är den LÅSTA raden, och itemet kan inte läsas ur den utan ett uppslag.
     * Är itemet borta (mjukraderat) är svaret nej: en försvunnen plats är
     * ingen inbox.
     */
    private function inInbox(Schedule $schedule): bool
    {
        $item = Item::query()->find($schedule->item_id);

        return (bool) $item?->container?->isInbox();
    }

    /**
     * Flyttar den ÖPPNA förekomsten när schemats återkommande ändras (M24,
     * issue 699). Ingen ny rad skapas: samma ULID, samma `id` — och därmed
     * står `occurrence_dependency` orörd, för den pekar på förekomstens `id`
     * (Beslut 3 och 7).
     *
     * **Förekomsten får det förfall den hade fått om schemat haft sina nya
     * värden när den öppnades** (Beslut 1). Indata är därför öppningens:
     * `$today` är den agerandes dag, och `$from`/`$closedDueAt` kommer ur den
     * senaste STÄNGDA förekomsten — `$from` är dess `due_at` vid `skip` och
     * den lokala dagen för `completed_at` vid `complete`, precis som
     * CloseOccurrence räknar dem. Saknas en stängd förekomst är båda null och
     * förfallet räknas som vid en öppning.
     *
     * **Ett ändrat `anchor_date` går före regeln ovan** (Beslut 1b):
     * användaren äger uppgiften och ska kunna flytta varje typ utan att
     * radera och skapa ny. För `interval` betyder det att `$from` inte
     * används alls — förfallet är det nya `anchor_date` — och för `fixed`
     * räknar `nextCalendarDue()` därifrån. Att `anchor_date` ändrades läses
     * ur den SPARADE modellen (`wasChanged`), eftersom anroparen sparar före
     * anropet (Beslut 5).
     *
     * **`$recalculateDue`** är den ändrandes `getDirty()`: sann när
     * `recurrence_type`, `interval_unit`, `interval_count` eller
     * `anchor_date` ändrades. Är den falsk ligger `due_at` kvar och bara
     * `visible_from` räknas om (Beslut 2) — `visible_from = due_at −
     * lead_days` i båda fallen.
     *
     * **Ett ändrat `due_at` frigör uppgiftsnotisernas dedupe-nycklar** (M24,
     * issue 700 § Beslut 3 och 4): en flyttad förekomst behåller sin ULID, så
     * utan frigöringen hade en redan påmind förekomst aldrig påmints igen på
     * sitt nya datum. Bara förfallet räknas — en ändrad `lead_days` lämnar
     * nycklarna i fred, och det gör också en PATCH som landar på samma datum.
     */
    public function moveOpen(Schedule $schedule, User $actor, bool $recalculateDue): ?ScheduleOccurrence
    {
        return DB::transaction(function () use ($schedule, $actor, $recalculateDue): ?ScheduleOccurrence {
            $lockedSchedule = $schedule->newQuery()->whereKey($schedule->getKey())->lockForUpdate()->first();

            if ($lockedSchedule === null) {
                return null;
            }

            $occurrence = $lockedSchedule->occurrences()
                ->active()
                ->first();

            if ($occurrence === null) {
                return null;
            }

            // Förfallet FÖRE flytten, läst medan det ännu är det gamla — det
            // är skillnaden mot det nya som avgör om påminnelserna frigörs.
            // Null när raden saknade datum (ADR-0052 § 3).
            $before = $occurrence->due_at?->toDateString();

            if ($recalculateDue) {
                $occurrence->due_at = $this->movedDueAt($schedule, $lockedSchedule, $actor);
            }

            $occurrence->visible_from = $occurrence->due_at?->copy()->subDays($lockedSchedule->lead_days);
            $occurrence->save();

            // Bara ett ändrat `due_at` frigör (Beslut 4). Ändras bara
            // `lead_days` flyttas `visible_from` men förfallet är detsamma, och
            // en andra påminnelse om samma datum vore en dubblett — samma sak
            // när en PATCH räknar fram samma `due_at`. Frigöringen ligger i
            // transaktionen (Beslut 3): antingen flyttas förekomsten och
            // nycklarna frigörs, eller händer ingendera. Ett datum som sätts
            // eller tas bort är också en ändring: null mot ett datum skiljer
            // sig från alla datum, i båda riktningarna.
            if ($occurrence->due_at?->toDateString() !== $before) {
                $this->releaseTaskReminders->handle($occurrence);
            }

            return $occurrence;
        });
    }

    /**
     * Förfallet den öppna förekomsten ska få (Beslut 1 och 1b): den senaste
     * stängda förekomsten ger samma indata en öppning hade fått, och
     * `dueAt()` räknar.
     *
     * `none` är undantaget: förfallet är `anchor_date`, och saknas det har
     * engångsuppgiften inget datum (ADR-0052 § 3). Null är alltså ett giltigt
     * svar här, inte ett programmeringsfel — flytten tömmer `due_at` och
     * `visible_from` när användaren tar bort datumet.
     */
    private function movedDueAt(Schedule $schedule, Schedule $lockedSchedule, User $actor): ?Carbon
    {
        $previous = $lockedSchedule->occurrences()
            ->whereIn('status', [ScheduleOccurrence::STATUS_COMPLETED, ScheduleOccurrence::STATUS_SKIPPED])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        $closedDueAt = $previous?->due_at;

        $from = match (true) {
            $previous === null => null,
            $previous->status === ScheduleOccurrence::STATUS_SKIPPED => $previous->due_at,
            default => $this->completionDay($previous, $previous->completedByUser ?? $actor),
        };

        // Beslut 1b: ett ändrat anchor_date går före de stängda
        // förekomsterna. Bara `interval` behöver veta det — `fixed` räknar
        // redan från `anchor_date`, och `none` nedan bortser från `$from`.
        if ($lockedSchedule->recurrence_type === 'interval' && $schedule->wasChanged('anchor_date')) {
            $from = null;
        }

        if ($lockedSchedule->recurrence_type === 'none') {
            // Null när `anchor_date` saknas: en engångsuppgift utan datum
            // (ADR-0052 § 3), inte ett programmeringsfel.
            return $lockedSchedule->anchor_date?->copy();
        }

        $dueAt = $this->dueAt($lockedSchedule, $actor->today(), $from, $closedDueAt);

        if ($dueAt === null) {
            throw $this->programmingError('Ett schema med en öppen förekomst kan inte sakna ett nästa förfall.');
        }

        return $dueAt;
    }

    /**
     * Den lokala dagen för `completed_at` — dagen `interval` räknar nästa
     * förfall från ([[ADR-0044 Användarens dag]] § Beslut 3).
     *
     * `completed_at` är en tidsstämpel i UTC, men en avbockning 01:30 svensk
     * tid den 25:e är klockan 23:30 UTC den 24:e, och den som trycker räknar
     * från den 25:e. Dagen tas därför ut i HENNES tidszon, precis som
     * kostnadskrokens `incurred_on` (issue 136 § Beslut 3), och byggs sedan om
     * till midnatt i APPENS tidszon — samma form som `User::today()` ger
     * ([[ADR-0044 Användarens dag]] § Beslut 5): ett datum utan tidszon, så
     * att den kan jämföras med `due_at` och `closedDueAt` som datum.
     *
     * För `complete` blir dagen identisk med `$user->today()`, eftersom
     * `completed_at` sätts till `now()` i samma transaktion. Den räknas ändå
     * ur tidsstämpeln och inte ur dagens datum: regeln är `completed_at`s dag,
     * och två uttryck för samma regel driver isär.
     *
     * Ligger här och inte i CloseOccurrence sedan M24 (issue 699 § Beslut 4):
     * både avslutet och flytten av en öppen förekomst räknar ur samma regel,
     * och en regel ska ha ett uttryck.
     */
    public function completionDay(ScheduleOccurrence $occurrence, User $user): Carbon
    {
        return Carbon::parse(
            $occurrence->completed_at->copy()->setTimezone($user->preferredTimezone())->toDateString()
        );
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
            ->active()
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
     *   vid en senare återaktivering (Beslut 3). Saknas `anchor_date` har
     *   uppgiften inget datum: svaret är null, och `handle()` öppnar en
     *   förekomst utan datum (ADR-0052 § 3). Ett `none` utan `anchor_date`
     *   är alltså INTE ett programmeringsfel.
     * - `fixed`: räknar ALLTID från kalendern (`anchor_date`), framflyttat i
     *   seriens steg tills det är både `>= $today` och `> $closedDueAt` (132) —
     *   oavsett `$from` och oavsett när jobbet gjordes. `$today` är den
     *   agerandes dag och inte serverns (Beslut 3).
     * - `interval`: `anchor_date` för den första förekomsten; `$from`
     *   (datumdelen) plus intervallet när 22b anropar med den lokala dagen för
     *   `completed_at`. Ligger resultatet på eller före `$closedDueAt` stegas
     *   det fram med intervallet tills det ligger efter (132).
     *
     * @return Carbon|null null när det inte finns någon nästa förekomst, eller
     *                     när en `none`-uppgift saknar datum.
     */
    private function dueAt(Schedule $schedule, Carbon $today, ?Carbon $from, ?Carbon $closedDueAt): ?Carbon
    {
        $anchor = $schedule->anchor_date;

        if ($schedule->recurrence_type === 'none') {
            // Utan `anchor_date` har engångsuppgiften inget datum (ADR-0052
            // § 3). Null betyder "öppna utan datum", och `handle()` skiljer
            // det från "ingen nästa" med hjälp av förekomsthistoriken.
            if ($anchor === null) {
                return null;
            }

            if ($schedule->occurrences()->exists()) {
                return null;
            }

            return $anchor->copy();
        }

        if ($schedule->recurrence_type === 'interval') {
            if ($from !== null) {
                return $this->nextIntervalDue($from->copy()->startOfDay(), $schedule, $closedDueAt);
            }

            if ($anchor === null) {
                throw $this->programmingError('Ett schema med recurrence_type interval utan anchor_date kan inte öppna sin första förekomst.');
            }

            return $anchor->copy();
        }

        // recurrence_type === 'fixed'
        if ($anchor === null) {
            throw $this->programmingError('Ett schema med recurrence_type fixed utan anchor_date kan inte öppna en förekomst.');
        }

        return $this->nextCalendarDue($anchor, $schedule, $today, $closedDueAt);
    }

    /**
     * Nästa `interval`-förfall: `$from` plus intervallet (Beslut 4), och
     * därpå regeln i 132 — datumet ligger alltid STRIKT efter den stängda
     * förekomstens `due_at`.
     *
     * Ett intervall som landar på eller före den stängda dagen stegas fram
     * tills det ligger efter. En daglig uppgift som bockas av dagen innan
     * sitt förfall hoppar därför till dagen efter sitt eget förfall i stället
     * för att ge samma dag igen. Ett oljebyte som görs två månader i förväg
     * räknas däremot fortfarande från bytet: tolv månader från `completed_at`
     * ligger redan efter `due_at`. `skip` räknar från den överhoppade
     * förekomstens `due_at` och uppfyller regeln redan — `due_at` plus
     * intervallet är alltid efter `due_at`.
     */
    private function nextIntervalDue(Carbon $from, Schedule $schedule, ?Carbon $closedDueAt): Carbon
    {
        $due = $this->addIntervals($from->copy(), $schedule, 1);

        if ($closedDueAt === null || $due->greaterThan($closedDueAt)) {
            return $due;
        }

        return $this->addIntervals($from->copy(), $schedule, $this->intervalsPast($from, $schedule, $closedDueAt));
    }

    /**
     * Minsta antalet intervall från $from som ger ett datum STRIKT efter
     * $closedDueAt. Anropas bara när ett enda intervall inte räcker, så $from
     * ligger alltid före $closedDueAt.
     *
     * Framflyttningen RÄKNAS, den loopas inte intervall för intervall (§ Att
     * se upp med, samma krav som `nextCalendarDue` har): en daglig uppgift
     * avbockad långt före sitt förfall får inte bli tusentals varv.
     */
    private function intervalsPast(Carbon $from, Schedule $schedule, Carbon $closedDueAt): int
    {
        $unit = $schedule->interval_unit;
        $count = $schedule->interval_count;

        if ($unit === 'day' || $unit === 'week') {
            $periodDays = $unit === 'day' ? $count : $count * 7;
            $diffDays = (int) $from->diffInDays($closedDueAt);
            $steps = intdiv($diffDays, $periodDays) + 1;

            // diffInDays räknar hela dygn — en korrektion på sin höjd ett steg.
            while ($this->addIntervals($from->copy(), $schedule, $steps)->lessThanOrEqualTo($closedDueAt)) {
                $steps++;
            }

            return $steps;
        }

        $monthsPerStep = $unit === 'month' ? $count : $count * 12;
        $steps = max(1, intdiv($this->wholeMonthsBetween($from, $closedDueAt), $monthsPerStep) + 1);

        // Månadssluts-klampningen kan lägga ett steg utöver det räknade —
        // ett fåtal korrektioner, aldrig en loop över dagar.
        while ($this->addIntervals($from->copy(), $schedule, $steps)->lessThanOrEqualTo($closedDueAt)) {
            $steps++;
        }

        return $steps;
    }

    /**
     * Lägger `$steps` av schemats intervall till $date. `month`/`year` stegas
     * med `addMonthsNoOverflow()`/`addYearsNoOverflow()`, aldrig
     * `addMonths()` — 31 januari plus en månad är 28 februari, inte 3 mars
     * (Beslut 6). $steps > 1 används bara av framflyttningen i 132.
     */
    private function addIntervals(Carbon $date, Schedule $schedule, int $steps): Carbon
    {
        $unit = $schedule->interval_unit;
        $count = $schedule->interval_count;

        if (! is_int($count) || $count < 1) {
            throw $this->programmingError('Ett schema med recurrence_type fixed/interval utan positivt interval_count kan inte öppna en förekomst.');
        }

        $total = $count * $steps;

        return match ($unit) {
            'day' => $date->addDays($total),
            'week' => $date->addWeeks($total),
            'month' => $date->addMonthsNoOverflow($total),
            'year' => $date->addYearsNoOverflow($total),
            default => throw $this->programmingError('Okänd interval_unit på schemat: '.($unit ?? 'null')),
        };
    }

    /**
     * Nästa `fixed`-förfall räknat från KALENDERN: `anchor_date` framflyttat
     * med `interval_count × interval_unit` tills det ligger både `>= $today`
     * och `> $closedDueAt` (Beslut 4, 132). Ett `anchor_date` som redan
     * uppfyller båda används som det är.
     *
     * De två villkoren blir ett golv: den stängda förekomstens `due_at` plus
     * en dag, lyft till `$today` när den ligger bakom. Utan en stängd
     * förekomst är golvet `$today`, precis som förut.
     *
     * Framflyttningen RÄKNAS, inte loopas dag för dag (§ Att se upp med): för
     * dag/vecka direkt aritmetiskt på dagskillnaden, för månad/år som hela
     * månader mellan datumen (med en korrektion på sin höjd ett steg för
     * månadssluts-klampningen). Ett `anchor_date` från 1990 med dagsintervall
     * blir inte 13 000 varv i en loop.
     */
    private function nextCalendarDue(Carbon $anchor, Schedule $schedule, Carbon $today, ?Carbon $closedDueAt): Carbon
    {
        $unit = $schedule->interval_unit;
        $count = $schedule->interval_count;

        if (! is_int($count) || $count < 1) {
            throw $this->programmingError('Ett schema med recurrence_type fixed/interval utan positivt interval_count kan inte öppna en förekomst.');
        }

        $bound = $today->copy();

        if ($closedDueAt !== null) {
            $floor = $closedDueAt->copy()->startOfDay()->addDay();

            if ($floor->greaterThan($bound)) {
                $bound = $floor;
            }
        }

        if (! $anchor->lessThan($bound)) {
            return $anchor->copy();
        }

        if ($unit === 'day' || $unit === 'week') {
            $periodDays = $unit === 'day' ? $count : $count * 7;
            $diffDays = (int) $anchor->diffInDays($bound);
            $steps = (int) ceil($diffDays / $periodDays);

            return $anchor->copy()->addDays($steps * $periodDays);
        }

        $wholeMonths = $this->wholeMonthsBetween($anchor, $bound);

        if ($unit === 'month') {
            $steps = max(1, intdiv($wholeMonths, $count));
            $due = $anchor->copy()->addMonthsNoOverflow($steps * $count);

            // Månadssluts-klampningen kan lägga ett steg utöver det räknade —
            // ett fåtal korrektioner, aldrig en loop över dagar.
            while ($due->lessThan($bound)) {
                $steps++;
                $due = $anchor->copy()->addMonthsNoOverflow($steps * $count);
            }

            return $due;
        }

        $steps = max(1, intdiv($wholeMonths, $count * 12));
        $due = $anchor->copy()->addYearsNoOverflow($steps * $count);

        while ($due->lessThan($bound)) {
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

    private function programmingError(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }
}
