<?php

namespace App\Actions\Inbox;

use App\Actions\Schedule\MoveSchedule;
use App\Actions\Schedule\OpenNextOccurrence;
use App\Exceptions\Api\ApiException;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bearbetar en uppgift som ligger i användarens inbox — M27 · issue 244, se
 * [[ADR-0054 Inboxen]] § 6.
 *
 * **Att bearbeta är att ge uppgiften en plats, en lista och kanske ett
 * datum.** Användaren väljer ett item, en lista (förvalt *Next*) och, om hon
 * vill, ett förfall. I EN transaktion:
 *
 * 1. schemat flyttas till itemet genom App\Actions\Schedule\MoveSchedule
 *    ([[ADR-0053 Flytt och kopiering]] § 6) — historiken följer med, och en
 *    uppgift med beroenden nekas av flytten, eftersom den lämnar containern;
 * 2. den öppna förekomsten får `gtd_list`;
 * 3. schemat får `default_gtd_list`, eller `next` när listan är `waiting` —
 *    `waiting` blir aldrig ett förval (ADR-0054 § 5);
 * 4. är ett datum satt får ett `none`-schema `anchor_date`, och förfallet
 *    flyttas med `OpenNextOccurrence::moveOpen()`.
 *
 * **Steg 4:s två delar hör ihop.** Ett `none`-schema har inget förfall att
 * räkna ur — dess `due_at` ÄR `anchor_date` (ADR-0052 § 3) — så datumet måste
 * in i kolumnen innan `moveOpen()` kan flytta den öppna förekomsten dit. För
 * `fixed` och `interval` räknar `moveOpen()` förfallet ur sin egen regel, och
 * det valda datumet rör dem inte.
 *
 * **Bara användarens EGEN inbox.** Ligger schemat någon annanstans — på ett
 * riktigt item, eller i någon annans inbox — nekas actionen med 422
 * `schedule.not_in_inbox`. Grinden (`delete` på källan) prövas av anroparen;
 * den här spärren svarar på var uppgiften LIGGER, och den är det som gör att
 * bearbetningen inte kan användas som en flytt bakvägen.
 *
 * **Inboxen är inget mål.** Ett inbox-item som `target` nekas med 422
 * `schedule.not_a_valid_target`: bearbetningen flyttar uppgiften TILL en
 * plats, och väljaren (issue 242) visar aldrig en inbox. Utan spärren hade en
 * handgjord begäran kunnat flytta en uppgift in i en inbox med en lista satt
 * — ett tillstånd modellen inte har.
 *
 * **Raden läses om under lås innan spärren ställs.** Annars kunde två
 * samtidiga bearbetningar båda se uppgiften i inboxen och flytta den, den ena
 * efter den andra; `MoveSchedule` tar samma lås, så den här läsningen är det
 * som gör kontrollen och flytten till EN skrivning.
 *
 * Skrivningarna ligger i samma transaktion som flytten: antingen flyttas
 * schemat och får sin lista och sitt datum, eller händer ingendera.
 * `MoveSchedule` öppnar sin egen transaktion — den blir en savepoint i den
 * här.
 */
class ProcessInboxTask
{
    public function __construct(
        private readonly MoveSchedule $moveSchedule,
        private readonly OpenNextOccurrence $openNextOccurrence,
    ) {}

    /**
     * @param  string  $gtdList  Listan uppgiften bearbetas till; en av
     *                           `ScheduleOccurrence::GTD_LISTS`. Validerad av
     *                           anroparens FormRequest.
     * @param  string|null  $dueAt  Det valda förfallet, eller null för att
     *                              lämna datumet orört.
     */
    public function handle(Schedule $schedule, Item $target, User $actor, string $gtdList, ?string $dueAt): Schedule
    {
        return DB::transaction(function () use ($schedule, $target, $actor, $gtdList, $dueAt): Schedule {
            $rad = Schedule::query()
                ->whereKey($schedule->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || ! $this->isInActorsInbox($rad, $actor)) {
                throw ApiException::make('schedule.not_in_inbox', [], 422);
            }

            if ($target->container->isInbox()) {
                throw ApiException::make('schedule.not_a_valid_target', [], 422);
            }

            $moved = $this->moveSchedule->handle($rad, $target, $actor);

            $open = $moved->openOccurrence()->first();

            if ($open !== null) {
                $open->gtd_list = $gtdList;
                $open->save();
            }

            $moved->default_gtd_list = in_array($gtdList, Schedule::DEFAULT_GTD_LISTS, true)
                ? $gtdList
                : ScheduleOccurrence::GTD_NEXT;
            $moved->save();

            if ($dueAt !== null) {
                if ($moved->recurrence_type === 'none') {
                    $moved->anchor_date = Carbon::parse($dueAt);
                    $moved->save();
                }

                $this->openNextOccurrence->moveOpen($moved, $actor, recalculateDue: true);
            }

            return $moved;
        });
    }

    /**
     * Ligger schemat på användarens EGEN inbox — samma fråga som
     * OpenNextOccurrence::inInbox() ställer, men med ägarskapet prövat:
     * en medlem i personkontot når inte en annans inbox (ADR-0054 § 2), och
     * `inbox_user_id` är det enda som skiljer de två.
     */
    private function isInActorsInbox(Schedule $schedule, User $actor): bool
    {
        $container = Item::query()->find($schedule->item_id)?->container;

        return $container !== null
            && $container->isInbox()
            && (int) $container->inbox_user_id === (int) $actor->id;
    }
}
