<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Byter listan och statusen på en AKTIV förekomst — M26 · issue 235,
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 2.
 *
 * Listan och statusen är två egenskaper (ADR-0052 § 1): den ena säger hur
 * användaren tänker hantera uppgiften, den andra hur långt den har kommit.
 * Den här actionen rör båda, i EN transaktion och med bara de fält som
 * faktiskt ändrades i loggen.
 *
 * **Bara `open` och `in_progress` får sättas.** Att stänga en förekomst går
 * fortfarande genom `complete` och `skip` (App\Actions\Schedule\
 * CloseOccurrence): en `{"status": "completed"}`-kropp vore den väg runt
 * avslutsflödet — beroendespärren, nästa förfall och notisavbrottet — som
 * issue 22b § Beslut 1 med flit stängde. `UpdateOccurrenceRequest` avvisar
 * värdet med 422; vakten här är den andra raden för samma regel, för
 * actionen anropas från fler än en yta (issue 238 kopplar på `/api`).
 *
 * **Förvalet rörs inte här.** Regeln som lärde in det när förekomsten lämnade
 * Inbox är borta (M27 · issue 244, [[ADR-0054 Inboxen]] § 5): förvalet sätts
 * när uppgiften SKAPAS på ett item eller BEARBETAS ur inboxen, och en senare
 * lista är ett enskilt val som inte säger något om regeln.
 *
 * **En förekomst i inboxen kan inte få en lista.** `gtd_list` sätts när
 * uppgiften bearbetas (App\Actions\Inbox\ProcessInboxTask), och en PATCH med
 * en lista på en obearbetad rad nekas med 422 `occurrence.in_inbox` — samma
 * spärr och samma form som `occurrence.not_open` nedan. Utan den hade
 * användaren kunnat sätta en lista på en uppgift som fortfarande ligger i
 * inboxen, och de två vyerna hade sagt olika saker om samma rad.
 *
 * **Låset ligger på SCHEMAT, inte på förekomsten.** Samma ordning som
 * CloseOccurrence (issue 22b § Beslut 9): schemaraden `lockForUpdate()`:as
 * först och förekomsten läses om under det låset. Ordningen är inte valfri —
 * två anropare som tar samma två lås i olika ordning kan låsa varandra, och
 * eftersom schemat äger sin enda aktiva förekomst är schemaraden den som
 * serialiserar ändringen mot en samtidig avbockning.
 * Den som förlorar kapplöpningen ser då att förekomsten inte längre är aktiv
 * och faller på `occurrence.not_open` — samma svar som CloseOccurrence ger.
 *
 * **En ändring som inte ändrar något skriver ingen rad.** En PATCH med
 * förekomstens nuvarande lista och status sparar ingenting och loggar
 * ingenting — samma regel som `schedule.updated` följer i UpdateSchedule.
 */
class ChangeOccurrence
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som ändrar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad av anroparen.
     * @param  string|null  $gtdList  Den nya listan, eller null för att lämna
     *                                den orörd.
     * @param  string|null  $status  `open` eller `in_progress`, eller null för
     *                               att lämna statusen orörd.
     * @return ScheduleOccurrence Den lästa raden under låset, med de nya
     *                            värdena.
     */
    public function handle(ScheduleOccurrence $occurrence, User $actor, ?string $gtdList, ?string $status): ScheduleOccurrence
    {
        if ($status !== null && ! in_array($status, ScheduleOccurrence::ACTIVE_STATUSES, true)) {
            throw new RuntimeException('ChangeOccurrence tar bara emot open eller in_progress, fick: '.var_export($status, true));
        }

        return DB::transaction(function () use ($occurrence, $actor, $gtdList, $status): ScheduleOccurrence {
            $lockedSchedule = Schedule::query()
                ->whereKey($occurrence->schedule_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOccurrence = $lockedSchedule->occurrences()
                ->whereKey($occurrence->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Samma spärr och samma kod som CloseOccurrence (issue 22b
            // § Beslut 5): en stängd förekomst är historik och kan inte ändras.
            if (! $lockedOccurrence->isActive()) {
                throw ApiException::make('occurrence.not_open', ['status' => $lockedOccurrence->status], 422);
            }

            // En förekomst i inboxen har ingen lista och får ingen här
            // (ADR-0054 § 5): listan sätts när uppgiften bearbetas, och den
            // vägen är ProcessInboxTask. Spärren gäller bara ett faktiskt
            // listbyte — en PATCH som bara rör statusen ska gå igenom.
            if ($gtdList !== null && $gtdList !== $lockedOccurrence->gtd_list && $this->inInbox($lockedSchedule)) {
                throw ApiException::make('occurrence.in_inbox', [], 422);
            }

            $meta = [];

            if ($gtdList !== null && $gtdList !== $lockedOccurrence->gtd_list) {
                $previous = $lockedOccurrence->gtd_list;
                $lockedOccurrence->gtd_list = $gtdList;
                $meta['gtd_list'] = ['from' => $previous, 'to' => $gtdList];
            }

            if ($status !== null && $status !== $lockedOccurrence->status) {
                $meta['status'] = ['from' => $lockedOccurrence->status, 'to' => $status];
                $lockedOccurrence->status = $status;
            }

            if ($meta === []) {
                return $lockedOccurrence;
            }

            // `changed` är namnen på de fält som ändrades, samma nyckel som
            // varje annan uppdateringshändelse bär (issue 109/110): historiken
            // ritar meningen ur `audit.action.*` och fältorden ur `meta.changed`
            // + `audit.field.*` (App\Actions\Audit\PresentAuditEvents,
            // resources/js/components/HistoryRow.vue). Utan den vore raden
            // oläslig — meningen har ett `:fields` som ingen fyller.
            $meta = ['changed' => array_keys($meta)] + $meta;

            $lockedOccurrence->save();

            // `account_id` är containerns ägarkonto, som i CloseOccurrence:
            // loggens konto är det som äger raden, inte ett konto användaren
            // handlar i namn av.
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_SCHEDULE_OCCURRENCE_CHANGED,
                account: $lockedSchedule->item->container->account,
                user: $actor,
                container: $lockedSchedule->item->container,
                item: $lockedSchedule->item,
                subjectType: 'schedule_occurrence',
                subjectUlid: $lockedOccurrence->ulid,
                meta: $meta,
            );

            return $lockedOccurrence;
        });
    }

    /**
     * Ligger förekomstens schema på ett inbox-item ([[ADR-0054 Inboxen]]
     * § 5)? `$schedule` är den LÅSTA raden, så itemet och dess container läses
     * med ett eget uppslag — samma form som OpenNextOccurrence::inInbox().
     */
    private function inInbox(Schedule $schedule): bool
    {
        return (bool) Item::query()->find($schedule->item_id)?->container?->isInbox();
    }
}
