<?php

namespace App\Actions\Inbox;

use App\Actions\Attachment\MoveAttachment;
use App\Actions\Schedule\MoveSchedule;
use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * *Back to Inbox* — skickar tillbaka en uppgift eller en bilaga till
 * användarens EGEN inbox, så att den kan bearbetas igen. M27 · issue 244, se
 * [[ADR-0054 Inboxen]] § 6.
 *
 * **Inboxen är aldrig ett val i målväljaren** (ADR-0054 § 6): handlingen går
 * alltid till den egna inkorgen, och den skapas om den saknas
 * (App\Actions\Inbox\ResolveInbox). Därför två egna metoder och inte en gren
 * i en flytt — målet är inte användarens att välja.
 *
 * **Uppgiften** flyttas enligt [[ADR-0053 Flytt och kopiering]], och därefter
 * nollställs listan: `gtd_list = null` på den öppna förekomsten och
 * `default_gtd_list = null` på schemat. En återkommande uppgift får då nya
 * förekomster utan lista tills den bearbetas igen (ADR-0054 § 6), eftersom
 * både OpenNextOccurrence och en avbockning respekterar det nullställda
 * förvalet. Beroendespärren är flyttens: inboxen är en annan container, och
 * en uppgift med beroenden nekas med `schedule.has_dependencies`.
 *
 * **Bilagan** flyttas enligt ADR-0053. Ägaren blir personkontot — inboxens
 * container ägs av det, och App\Support\Account\ActingAccount väljer
 * målcontainerns ägarkonto när användaren är medlem i det — och dess kvot
 * prövas av flytten, som vid varje ägarbyte (ADR-0053 § 3 och 4).
 *
 * **Redan i inboxen är ett fel**, inte en no-op: 422
 * `schedule.already_in_inbox` respektive `attachment.already_in_inbox`.
 * Flytten hade svarat `same_item`, men orden säger vad användaren försökte
 * göra, och kön av anropare (webben och `/api`, issue 247) ska få samma svar.
 *
 * **Grinden är densamma som för en flytt** — `delete` på källans item
 * (ADR-0053 § 2) — och prövas av anroparen. Här ligger bara skrivningen, i EN
 * transaktion: antingen flyttas raden och listan nollställs, eller händer
 * ingendera.
 */
class SendToInbox
{
    public function __construct(
        private readonly ResolveInbox $resolveInbox,
        private readonly MoveSchedule $moveSchedule,
        private readonly MoveAttachment $moveAttachment,
    ) {}

    /**
     * Skickar tillbaka en uppgift till den egna inboxen och nollställer dess
     * lista och förval.
     */
    public function schedule(Schedule $schedule, User $actor): Schedule
    {
        return DB::transaction(function () use ($schedule, $actor): Schedule {
            $inbox = $this->resolveInbox->handle($actor);

            if ((int) $schedule->item_id === (int) $inbox->id) {
                throw ApiException::make('schedule.already_in_inbox', [], 422);
            }

            $moved = $this->moveSchedule->handle($schedule, $inbox, $actor);

            $open = $moved->openOccurrence()->first();

            if ($open !== null) {
                $open->gtd_list = null;
                $open->save();
            }

            $moved->default_gtd_list = null;
            $moved->save();

            return $moved;
        });
    }

    /**
     * Skickar tillbaka en bilaga till den egna inboxen. Ägarbytet och
     * kvotprövningen sker i flytten (ADR-0053 § 3 och 4).
     */
    public function attachment(Attachment $attachment, User $actor): Attachment
    {
        return DB::transaction(function () use ($attachment, $actor): Attachment {
            $inbox = $this->resolveInbox->handle($actor);

            if ((int) $attachment->item_id === (int) $inbox->id) {
                throw ApiException::make('attachment.already_in_inbox', [], 422);
            }

            return $this->moveAttachment->handle($attachment, $inbox, $actor);
        });
    }
}
