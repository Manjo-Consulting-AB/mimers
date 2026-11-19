<?php

namespace App\Actions\Field;

use App\Models\ItemField;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en fältdefinition — den går till papperskorgen och kan
 * återställas, se [[ADR-0089 Egna fält på items]] § Riktning 3 och
 * [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26 samt
 * issue 417 § 5.
 *
 * **Värdena rörs inte.** `item_field_value` har ingen `deleted_at`: ett fälts
 * värden syns inte medan definitionen ligger i papperskorgen, kommer tillbaka
 * med den och gallras med den (App\Actions\Trash\PurgeContent::field). Ett
 * fält som tas bort och återställs har alltså kvar sina värden.
 *
 * Kvoten rörs inte: en mjukraderad rad räknas tills den gallras
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3), och
 * MetadataLedger::save hade bokfört noll byte ändå.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen.
 */
class DeleteField
{
    public function handle(ItemField $field): void
    {
        DB::transaction(function () use ($field): void {
            $field->delete();
        });
    }
}
