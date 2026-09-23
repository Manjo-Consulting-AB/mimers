<?php

namespace App\Actions\Audit;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;

/**
 * Den enda vägen IN i revisionsloggen (issue 40 § Beslut 6) — se [[Konton
 * och åkomst]] § audit_log. Varje känslig händelse — `container.transferred`
 * i accepten, `access.revoked` i återkallningen, och fler som senare issues
 * kopplar på — anropar den här actionen med samma `action`-namnrum och
 * skriver raden i anroparens transaktion.
 *
 * Actionen öppnar ingen egen transaktion, av samma skäl som
 * App\Actions\Usage\AdjustUsage (issue 26a § Beslut 3): den anropas alltid
 * inifrån anroparens transaktion, och en egen `DB::transaction` där vore en
 * nästlad transaktion som ser ut att skydda något den inte skyddar. En rad
 * som skrivs utanför transaktionen kunde överleva ett rollback och beskriva
 * en händelse som aldrig hände — för ägarbytet är det uttryckligen förbjudet
 * (issue 40 § Beslut 9).
 *
 * Allt sätts explicit, aldrig via massilldelning — `#[Fillable([])]` på
 * App\Models\AuditLog. `account_id`/`user_id`/`container_id` är NULL när
 * motsvarande modell inte skickas in; `user_id` är null för händelser ett
 * jobb orsakat, och resursen hittar då inte på en systemanvändare (Beslut
 * 11).
 *
 * `item_id` (issue 107) sätts när händelsen hör till ett item, oavsett
 * subjekt — itemet självt eller något som hänger på det. Kolumnen är en
 * identifierare utan främmande nyckel, som `subject_id`: raden ska överleva
 * itemet den beskriver.
 */
class RecordAuditEvent
{
    /**
     * Skriv en rad. `$action` är ett öppet namnrum — konstanterna bor på
     * App\Models\AuditLog, så anroparna aldrig stavar en sträng. `$meta`
     * serialiseras som `{}` av resursen när den är tom, aldrig `[]`.
     *
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        string $action,
        ?Account $account = null,
        ?User $user = null,
        ?Container $container = null,
        ?Item $item = null,
        ?string $subjectType = null,
        ?string $subjectUlid = null,
        array $meta = [],
    ): AuditLog {
        $log = new AuditLog;
        $log->action = $action;
        $log->account_id = $account?->id;
        $log->user_id = $user?->id;
        $log->container_id = $container?->id;
        $log->item_id = $item?->id;
        $log->subject_type = $subjectType;
        $log->subject_id = $subjectUlid;
        $log->meta = $meta;
        $log->save();

        return $log;
    }
}
