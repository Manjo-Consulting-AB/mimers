<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Avslutsflödet när en uppgift markeras klar eller hoppas över — issue 22b.
 * Dokumentets fem steg ([[Scheman och uppgifter]] § Flödet när en uppgift
 * markeras klar) körs i EN transaktion, i den ordningen:
 *
 * 1. Beroendekontroll — issue 23b § Beslut 4: har förekomsten ÖPPNA
 *    beroenden (motpartens status är `open`) nekas stängningen med
 *    `occurrence.blocked`, och `blocked_by` listar vartenda ett — inte bara
 *    det första, annars bockar användaren av ett, får samma fel igen och lär
 *    sig att systemet ljuger om vad som återstår. Kontrollen gäller BÅDA
 *    rutterna: `skip` är också en stängning.
 * 2. Raden stängs: status, `completed_at`, `completed_by_user_id`,
 *    `completed_by_account_id`, ev. `completion_note`. I samma transaktion
 *    skrivs händelseloggen (issue 110): `schedule_occurrence.completed` eller
 *    `schedule_occurrence.skipped`, med `item_id` satt.
 * 3. Nästa `due_at` räknas — av App\Actions\Schedule\OpenNextOccurrence,
 *    som är den ENDA vägen in i `schedule_occurrence` (22a). Ingen andra
 *    beräkning skrivs här: två uttryck för samma regel driver isär.
 * 4. Den nya förekomsten skapas, `visible_from = due_at - lead_days`.
 * 5. Oskickade notiser för den stängda förekomsten avbryts — M5. Byggs på
 *    exakt den platsen nedan; ingenting här.
 *
 * De två rutterna complete()/skip() i
 * App\Http\Controllers\Api\ScheduleOccurrenceController delegerar hit med
 * `status = 'completed'` respektive `'skipped'`. Skillnaden flödet emellan
 * är bara vilken `$from` OpenNextOccurrence får (Beslut 4):
 *
 * | Typ | complete räknar nästa från | skip räknar nästa från |
 * |---|---|---|
 * | `fixed` | kalendern, `anchor_date` | kalendern, `anchor_date` — identiskt |
 * | `interval` | `completed_at` | den överhoppade förekomstens `due_at` |
 * | `none` | ingen nästa | ingen nästa |
 *
 * Båda rutterna skickar dessutom med den stängda förekomstens `due_at` (132):
 * nästa förekomst ligger alltid strikt efter den som stängdes. För `fixed`
 * blir det ett golv i kalenderräkningen — en förekomst avbockad på sin egen
 * förfallodag får morgondagen, inte samma dag igen. För `interval` blir det
 * en framflyttning när `completed_at` plus intervallet inte räcker förbi.
 *
 * Låset ligger på SCHEMAT, inte på förekomsten (Beslut 9): schemaraden
 * `lockForUpdate()`:as först och förekomsten läses om inuti transaktionen.
 * Det är samma lås som OpenNextOccurrence tar (22a § Beslut 7), och det är
 * därför två samtidiga avslut serialiseras — det andra ser att förekomsten
 * inte längre är `open` och faller på `occurrence.not_open` (Beslut 5).
 * Låser man bara förekomstraden kan två anrop på olika förekomster i samma
 * schema båda skapa en ny, och invarianten "exakt en öppen förekomst per
 * aktivt schema" bryts av kod som var korrekt var för sig.
 */
class CloseOccurrence
{
    public function __construct(
        private OpenNextOccurrence $openNextOccurrence,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @return array{closed: ScheduleOccurrence, next: ScheduleOccurrence|null}
     */
    public function handle(Schedule $schedule, ScheduleOccurrence $occurrence, User $user, Account $account, string $status, ?string $completionNote = null): array
    {
        if (! in_array($status, [ScheduleOccurrence::STATUS_COMPLETED, ScheduleOccurrence::STATUS_SKIPPED], true)) {
            throw new RuntimeException('CloseOccurrence tar bara emot completed eller skipped, fick: '.var_export($status, true));
        }

        return DB::transaction(function () use ($schedule, $occurrence, $user, $account, $status, $completionNote): array {
            // Beslut 9: schemaraden låses FÖRST. Förekomsten läses sedan om
            // under det låset — en aktuell läsning som ser en samtidig
            // avslutning när den väl fått vänta på låset.
            $lockedSchedule = $schedule->newQuery()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();

            $lockedOccurrence = $lockedSchedule->occurrences()->whereKey($occurrence->getKey())->lockForUpdate()->firstOrFail();

            // Beslut 5: bara en ÖPPEN förekomst kan stängas. Utan regeln
            // blir ett dubbelklick två stängningar och två nya förekomster,
            // och serien har hoppat ett steg utan att någon gjorde något.
            if ($lockedOccurrence->status !== ScheduleOccurrence::STATUS_OPEN) {
                throw ApiException::make('occurrence.not_open', ['status' => $lockedOccurrence->status], 422);
            }

            // Beslut 6: ett pausat schema kan inte stängas — ett sådant
            // betyder "den här uppgiften gäller inte just nu", och att bocka
            // av den skulle skapa nästa förekomst på ett schema ingen vill ha
            // förekomster på. (Ett mjukraderat schema är osynligt genom
            // relationen och ger redan 404 i rutten.)
            if (! $lockedSchedule->is_active) {
                throw ApiException::make('schedule.inactive', [], 422);
            }

            // Steg 1 — Beroendekontroll (issue 23b). En förekomst med ÖPPNA
            // beroenden kan inte stängas (Beslut 4), och `skip` går genom
            // samma spärr som `complete` — en uppgift som inte får göras än
            // får inte heller hoppas över, annars är spärren en formalitet man
            // klickar sig förbi. Kontrollen ligger först i flödet, innan
            // någonting skrivs, och räknas i EN fråga oavsett antalet
            // beroenden (§ Att se upp med). Ett beroende är uppfyllt så snart
            // motparten inte längre är `open` (Beslut 5), så listan är
            // blockerarna med motpartens status `open`.
            //
            // Ett beroende vars motpart ligger under ett MJUKRADERAT schema
            // eller item existerar inte — varken här, i GET eller i
            // cykelkontrollen (granskningen av 23b, samma regel som 23a §
            // Beslut 7). Utan villkoret blir B blockerad av en förekomst som
            // aldrig stängs — schemaradering stänger inga förekomster (22a) —
            // och som användaren varken kan se eller göra något åt: osynligt
            // trasigt. Raden ligger kvar i tabellen som historik (Beslut 5);
            // den räknas bara inte längre. PAUSADE scheman är motsatsen: de
            // ska blockera, för paus är reversibelt och synligt.
            $blockedBy = DB::table('occurrence_dependency')
                ->join('schedule_occurrence as blocker', 'blocker.id', '=', 'occurrence_dependency.depends_on_occurrence_id')
                ->join('schedule', 'schedule.id', '=', 'blocker.schedule_id')
                ->join('item', 'item.id', '=', 'schedule.item_id')
                ->where('occurrence_dependency.occurrence_id', $lockedOccurrence->id)
                ->where('blocker.status', ScheduleOccurrence::STATUS_OPEN)
                ->whereNull('schedule.deleted_at')
                ->whereNull('item.deleted_at')
                ->orderBy('blocker.due_at')
                // ULID:en som andra nyckel: två blockerare med samma due_at
                // ska inte byta plats mellan körningar.
                ->orderBy('blocker.ulid')
                ->get(['blocker.ulid', 'blocker.due_at', 'schedule.title'])
                ->map(fn ($row): array => [
                    'ulid' => $row->ulid,
                    'title' => $row->title,
                    // Query builder-formaterar inte DATE-kolumnen som Eloquent
                    // gör — rakt ur sqlite är värdet "2027-05-05 00:00:00".
                    // En Eloquent-relation med date-cast vore renare, men
                    // spärren måste läsas på EN fråga och en relation med
                    // eager loads är fler; utdata är identisk (granskningen).
                    'due_at' => Carbon::parse($row->due_at)->toDateString(),
                ])
                ->all();

            if ($blockedBy !== []) {
                throw ApiException::make('occurrence.blocked', ['blocked_by' => $blockedBy], 422);
            }

            // Steg 2 — stäng raden.
            $lockedOccurrence->status = $status;
            $lockedOccurrence->completed_at = now();
            $lockedOccurrence->completed_by_user_id = $user->id;
            $lockedOccurrence->completed_by_account_id = $account->id;
            $lockedOccurrence->completion_note = $completionNote;
            $lockedOccurrence->save();

            // Raden i händelseloggen (issue 110). `completed` och `skipped` är
            // två handlingar och inte en med en flagga: skillnaden syns i
            // historiken, och flashkoden skiljer dem redan åt. Förekomsten som
            // öppnas i steg 3 och 4 loggas INTE — den är en följd av
            // avbockningen, inte en handling.
            //
            // `account_id` är containerns ägarkonto och inte `$account`: den
            // senare är kontot användaren HANDLAR i namn av (varvet), medan
            // loggens konto är det som äger raden — samma val som i
            // App\Actions\Item\CreateItem. Anteckningen (`completion_note`) är
            // fritext och följer aldrig med; `due_at` är ett datum och gör
            // raden läsbar utan att slå upp förekomsten.
            $this->recordAuditEvent->handle(
                action: $status === ScheduleOccurrence::STATUS_SKIPPED
                    ? AuditLog::ACTION_SCHEDULE_OCCURRENCE_SKIPPED
                    : AuditLog::ACTION_SCHEDULE_OCCURRENCE_COMPLETED,
                account: $lockedSchedule->item->container->account,
                user: $user,
                container: $lockedSchedule->item->container,
                item: $lockedSchedule->item,
                subjectType: 'schedule_occurrence',
                subjectUlid: $lockedOccurrence->ulid,
                meta: ['due_at' => $lockedOccurrence->due_at->toDateString()],
            );

            // Steg 3 och 4 — nästa förfall räknas och nästa förekomst skapas
            // av OpenNextOccurrence, i samma transaktion. `$from` är det enda
            // som skiljer complete från skip (Beslut 4): complete räknar
            // interval från `completed_at` (senast utfört), skip från den
            // överhoppade förekomstens `due_at` — en knapptryckning får aldrig
            // flytta hela den framtida serien. `fixed` ignorerar `$from` och
            // räknar alltid från kalendern.
            //
            // Den stängda förekomstens `due_at` följer alltid med (132), för
            // båda rutterna: nästa förfall ligger strikt efter det stängda.
            // Det är det som gör att en daglig uppgift avbockad i förtid
            // flyttar sig i stället för att komma tillbaka med samma dag.
            $from = $status === ScheduleOccurrence::STATUS_SKIPPED
                ? $lockedOccurrence->due_at
                : $lockedOccurrence->completed_at;

            $next = $this->openNextOccurrence->handle($lockedSchedule, $from, $lockedOccurrence->due_at);

            // Steg 5 — Avbryt oskickade notiser för den stängda förekomsten
            // (M5). Byggs på exakt den här platsen, sist i flödet.

            return ['closed' => $lockedOccurrence, 'next' => $next];
        });
    }
}
