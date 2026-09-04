<?php

namespace App\Actions\Attachment;

use App\Actions\Usage\AdjustUsage;
use App\Models\Attachment;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en bilaga och minskar kontots förbrukning — paret på ett
 * ställe (issue 28 § Beslut 5). Se [[Filer och lagring]] § attachment,
 * [[ADR-0008 Soft delete och papperskorg]] och [[Planer och kvoter]] §
 * usage_counter.
 *
 * Mjukraderingen i den vanliga containerrutten (
 * App\Http\Controllers\Api\AttachmentController::destroy) och rensningen i
 * den nya storage-ytan (App\Http\Controllers\Api\AccountStorageController)
 * gör EXAKT samma sak: `deleted_at` sätts, bytena lämnar kontots räknare
 * omedelbart (issue 26a — en bilaga som slutar vara levande slutar också
 * räknas), och `stored_file.reference_count` rörs INTE — den minskas först
 * när bilagan gallras av PurgeAttachment (issue 17a). Hade paret legat i två
 * filer skulle de glida isär första gången någon ändrar den ena.
 *
 * Stegen i en transaktion, samma mönster som PurgeAttachment: raden läses
 * om under radlåset — en current read, så två samtidiga raderingar av samma
 * bilaga ser varandras ändringar och bytena dras av en gång — och är raden
 * redan mjukraderad (eller borta) finns ingenting att göra. Bytena och
 * `billed_account_id` läses ur den LÅSTA raden och stored_file-raden, aldrig
 * ur den instans som anroparen räckte in — en bilaga kan ha förändrats sedan
 * dess.
 *
 * Actionen är verktyget, inte grinden: den tar en levande bilaga och prövar
 * inte vem som får radera den. Den som anropar har redan låst upp vägen
 * genom en policy (ContainerPolicy::update respektive AccountPolicy).
 *
 * Returvärdet är sant om raden faktiskt mjukraderades här, falskt om den
 * redan var borta (mjukraderad eller saknad) — samma teknik som
 * PurgeAttachment::handle, där antalet raderade rader är den enda
 * tillförlitliga signalen. En samtidig radering av samma bilaga mellan en
 * tidigare SELECT och radlåset här ska inte räknas två gånger av anroparen.
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * se [[ADR-0024 Tunna controllers och actions]] och PurgeAttachments
 * docblock.
 */
class TrashAttachment
{
    public function handle(Attachment $attachment): bool
    {
        return DB::transaction(function () use ($attachment): bool {
            $rad = Attachment::query()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->trashed()) {
                return false;
            }

            $billedAccountId = $rad->billed_account_id;
            $byteSize = (int) StoredFile::query()->whereKey($rad->stored_file_id)->value('byte_size');

            $rad->delete();

            (new AdjustUsage)->handle($billedAccountId, bytesDelta: -$byteSize);

            return true;
        });
    }
}
