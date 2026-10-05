<?php

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Notification\ReleaseTaskReminders;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Flyttar en uppgift — schemat med alla sina förekomster — till ett annat
 * item, i samma container eller i en annan. Se [[ADR-0053 Flytt och
 * kopiering]] § 6 och § 7, och M27 · issue 240.
 *
 * **En uppgift kopieras inte** (ADR-0053 § 1): en kopia med historik vore två
 * sanningar om samma utförda arbete. Den här actionen flyttar därför raden —
 * `schedule.item_id` byts och förekomsterna följer med, både den öppna och
 * historiken, eftersom de hänger på SCHEMAT och inte på platsen. Ingen
 * förekomst skrivs om, och ingen ULID byts.
 *
 * **Beroenden skrivs bara inom en container** (ADR-0053 § 6). Har schemat
 * eller någon av dess förekomster ett beroende — på schema- eller
 * förekomstnivå och i NÅGON riktning — nekas flytten till en annan container
 * med `schedule.has_dependencies` (422). Inom samma container är beroenden
 * tillåtna: de pekar på `id` och `id` står kvar. Läsningen är fyra rader och
 * inte en fråga per nivå och riktning, men den täcker alla fyra fallen.
 *
 * **Kostnadsraderna stannar på sitt item** (ADR-0053 § 6): de bokfördes där,
 * och `cost_entry` hänger på itemet, inte på schemat — flytten rör dem inte.
 * `completed_by_account_id` på avklarade förekomster rörs inte heller: det
 * var det kontot som utförde arbetet.
 *
 * **Den öppna förekomstens oskickade notiser frigörs** genom
 * App\Actions\Notification\ReleaseTaskReminders (ADR-0053 § 6, issue 222):
 * nästa körning skapar notiserna på nytt för dem som når uppgiftens nya
 * plats. En stängd förekomst har inga egna notiser kvar att frigöra.
 *
 * **Händelseloggen** (ADR-0053 § 7) följer formen från
 * App\Actions\Attachment\MoveAttachment: en rad inom samma container, med
 * `from_item`/`to_item` som ULID:er, och två rader mellan containrar — en i
 * vardera loggen — där `direction` är allt de skiljer sig åt och **ingen av
 * dem nämner motparten** (ingen ULID, inget namn). Den som läser källans logg
 * ska inte få veta vart uppgiften tog vägen om hon inte når dit.
 *
 * Låset ligger på schemaraden, som i App\Actions\Schedule\ChangeOccurrence:
 * raden läses om under `lockForUpdate()` och itemet läses ur den LÅSTA raden,
 * aldrig ur instansen anroparen räckte in. Actionen är verktyget, inte
 * grinden: behörigheten (`delete` på källan, `create` på målet) prövas av
 * anroparen.
 */
class MoveSchedule
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly ReleaseTaskReminders $releaseTaskReminders,
    ) {}

    public function handle(Schedule $schedule, Item $target, User $actor): Schedule
    {
        return DB::transaction(function () use ($schedule, $target, $actor): Schedule {
            $rad = Schedule::query()
                ->whereKey($schedule->getKey())
                ->lockForUpdate()
                ->first();

            // Ett mjukraderat schema kan inte flyttas (ADR-0053 § 1). Rutten
            // ger redan 404 genom scopeBindings, så koden nås av `/api` och av
            // en handgjord begäran — samma försvar som MoveAttachment:s
            // `attachment.not_movable`.
            if ($rad === null) {
                throw ApiException::make('schedule.not_movable', [], 422);
            }

            // `withTrashed()`: källitemet får inte vara mjukraderat (ADR-0053
            // § 1), men uppslaget måste se raden för att kunna säga det — ett
            // scopat findOrFail vore 404 i stället för 422.
            $source = Item::withTrashed()->findOrFail($rad->item_id);

            if ($source->trashed() || $target->trashed()) {
                throw ApiException::make('schedule.not_movable', [], 422);
            }

            if ((int) $target->id === (int) $rad->item_id) {
                throw ApiException::make('schedule.same_item', [], 422);
            }

            $sameContainer = (int) $source->container_id === (int) $target->container_id;

            // Beroendespärren gäller BARA mellan containrar (ADR-0053 § 6).
            if (! $sameContainer && $this->hasDependencies($rad)) {
                throw ApiException::make('schedule.has_dependencies', [], 422);
            }

            $rad->item_id = $target->id;
            $rad->save();

            // Den öppna förekomstens dedupe-nycklar frigörs (ADR-0053 § 6).
            // Förekomsterna följer med schemat; bara notiserna måste nollas,
            // så att nästa körning påminner på den nya platsen.
            $open = $rad->openOccurrence()->first();

            if ($open !== null) {
                $this->releaseTaskReminders->handle($open);
            }

            if ($sameContainer) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_SCHEDULE_MOVED,
                    account: $target->container->account,
                    user: $actor,
                    container: $target->container,
                    item: $target,
                    subjectType: 'schedule',
                    subjectUlid: $rad->ulid,
                    meta: ['from_item' => $source->ulid, 'to_item' => $target->ulid],
                );
            } else {
                // Två rader och inte en delad: den ena hör till källans logg
                // och den andra till målets, och läsregeln i [[ADR-0043 Tre
                // loggar]] gäller per container (ADR-0053 § 7).
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_SCHEDULE_MOVED,
                    account: $source->container->account,
                    user: $actor,
                    container: $source->container,
                    item: $source,
                    subjectType: 'schedule',
                    subjectUlid: $rad->ulid,
                    meta: ['direction' => 'out'],
                );

                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_SCHEDULE_MOVED,
                    account: $target->container->account,
                    user: $actor,
                    container: $target->container,
                    item: $target,
                    subjectType: 'schedule',
                    subjectUlid: $rad->ulid,
                    meta: ['direction' => 'in'],
                );
            }

            return $rad;
        });
    }

    /**
     * Har schemat, eller någon av dess förekomster, ett beroende — i någon
     * riktning och på någon av de två nivåerna (ADR-0053 § 6)?
     *
     * Fyra läsningar: schemat som väntande, schemat som motpart, förekomsterna
     * som väntande och förekomsterna som motpart. Ett beroende i vilken som
     * helst av dem räcker för att neka flytten mellan containrar.
     */
    private function hasDependencies(Schedule $schedule): bool
    {
        $onSchedule = ScheduleDependency::query()
            ->where('schedule_id', $schedule->id)
            ->orWhere('depends_on_schedule_id', $schedule->id)
            ->exists();

        if ($onSchedule) {
            return true;
        }

        $occurrenceIds = $schedule->occurrences()->pluck('id')->all();

        if ($occurrenceIds === []) {
            return false;
        }

        return OccurrenceDependency::query()
            ->whereIn('occurrence_id', $occurrenceIds)
            ->orWhereIn('depends_on_occurrence_id', $occurrenceIds)
            ->exists();
    }
}
