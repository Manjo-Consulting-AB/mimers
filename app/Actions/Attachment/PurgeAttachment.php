<?php

namespace App\Actions\Attachment;

use App\Models\Attachment;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar en bilaga — den enda vägen UT ur papperskorgen: attachment-raden
 * försvinner på riktigt och stored_file.reference_count minskas, med
 * markeringen för fysisk radering när räknaren når noll (issue 17a). Se
 * [[Filer och lagring]] § Radering och [[ADR-0008 Soft delete och
 * papperskorg]]. Den fysiska raderingen av bytena är issue 17b; de som
 * anropar den här actionen är 20b, 20c och issue 28 (M4).
 *
 * Actionen är verktyget, inte grinden: den tar en attachment som redan är
 * mjukraderad ELLER en som inte är det, och prövar inte vilket. Den som
 * anropar avgör om raden får försvinna.
 *
 * Fyra steg i en transaktion (Beslut 2):
 *
 * 1. forceDelete på attachment-raden.
 * 2. reference_count minskas som SQL — aldrig som läs-ändra-skriv i PHP,
 *    och aldrig under noll (Beslut 3).
 * 3. räknaren läses om INOM transaktionen; är den noll sätts
 *    purge_after = now() + 30 dagar.
 * 4. commit.
 *
 * Bytena på disken rörs inte här (Beslut 4): efter actionen ligger filen
 * kvar med purge_after satt, och 17b tar hand om den. Det är de 30 dagarna
 * som skyddar mot buggen som råkar radera fel rader.
 */
class PurgeAttachment
{
    public function handle(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $storedFileId = $attachment->stored_file_id;

            // Steg 1 och Beslut 7 — idempotent per anrop, inte per rad:
            // forceDelete() på en rad som inte finns är en no-op i Eloquent
            // och går inte att skilja från en lyckad radering, så antalet
            // raderade rader är den enda tillförlitliga signalen. Ett andra
            // anrop med samma instans raderar 0 rader och får inte minska
            // räknaren igen. withTrashed() eftersom bilagan ofta kommer från
            // papperskorgen.
            $raderade = Attachment::withTrashed()
                ->whereKey($attachment->getKey())
                ->forceDelete();

            if ($raderade === 0) {
                return;
            }

            // Steg 2 och Beslut 3: minskningen är en SQL-operation, aldrig
            // läs-ändra-skriv i PHP. Villkoret på > 0 skyddar INT
            // UNSIGNED-kolumnen — ett decrement på en nollräknare ger -1,
            // som i MySQL antingen kastar eller wrappar till ett gigantiskt
            // tal som betyder "radera aldrig". En rad som redan står på noll
            // är ett förväntat läge, inte ett fel.
            StoredFile::query()
                ->whereKey($storedFileId)
                ->where('reference_count', '>', 0)
                ->decrement('reference_count');

            // Steg 3: räknaren läses om INOM transaktionen — två samtidiga
            // gallringar får inte tappa en minskning. lockForUpdate är en
            // "current read" som ser det senast committade värdet, så
            // purge_after sätts bara när räknaren verkligen är noll och
            // aldrig medan någon annan fortfarande refererar bytena.
            $storedFile = StoredFile::query()
                ->whereKey($storedFileId)
                ->lockForUpdate()
                ->first();

            if ($storedFile !== null && $storedFile->reference_count === 0) {
                $storedFile->purge_after = now()->addDays(30);
                $storedFile->save();
            }
        });
    }
}
