<?php

namespace App\Actions\Item;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver itemets anteckning och loggar sparningen — [[ADR-0066 Itemets
 * anteckning]] § 10.
 *
 * **En sparning som inte ändrar något skriver ingen rad.** Anteckningen är
 * densamma som förut, `isDirty()` är falskt, och varken kolumnen eller
 * historiken rörs.
 *
 * **Autosparningen loggas per arbetspass, inte per sparning.** Ändringen
 * skriver `item.updated` med `meta.changed: ['notes']`, utom när itemets
 * senaste händelse redan är just en sådan rad, av samma användare och yngre
 * än tio minuter. Utan undantaget hade historiken fått en rad per paus i
 * skrivandet (ADR-0066 § 10).
 *
 * **Texten följer aldrig med i `meta`.** Bara namnet på fältet, samma regel
 * som i App\Actions\Item\UpdateItem: loggen får inte bli ett andra register
 * över vad användaren skrivit.
 *
 * Skrivningen och loggraden ligger i EN transaktion, samma skäl som i
 * App\Actions\Item\UpdateItem.
 */
class UpdateItemNotes
{
    /**
     * Hur länge en loggrad räknas som samma arbetspass.
     */
    private const SESSION_MINUTES = 10;

    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  User  $actor  Den som sparar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Item $item, User $actor, ?string $notes): void
    {
        $item->notes = $notes;

        DB::transaction(function () use ($item, $actor): void {
            if (! $item->isDirty('notes')) {
                return;
            }

            $item->save();

            if ($this->continuesSession($item, $actor)) {
                return;
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ITEM_UPDATED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                meta: ['changed' => ['notes']],
            );
        });
    }

    /**
     * Är itemets senaste händelse en anteckningssparning av samma användare,
     * yngre än tio minuter?
     *
     * Läses FÖRE den nya raden skrivs, och det är därför den här kontrollen
     * ligger efter `save()` men före `handle()`: raden som ska jämföras är
     * den som fanns när sparningen kom.
     */
    private function continuesSession(Item $item, User $actor): bool
    {
        $latest = AuditLog::query()
            ->where('item_id', $item->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null || $latest->user_id !== $actor->id) {
            return false;
        }

        if ($latest->action !== AuditLog::ACTION_ITEM_UPDATED) {
            return false;
        }

        if (($latest->meta['changed'] ?? null) !== ['notes']) {
            return false;
        }

        return $latest->created_at->greaterThan(now()->subMinutes(self::SESSION_MINUTES));
    }
}
