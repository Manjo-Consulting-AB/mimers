<?php

namespace App\Actions\Attachment;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sparar en uppladdad fil och kopplar den till ett item, med dedupen från
 * issue 16a § Beslut 10 — en instansklass med handle(), injicerad i
 * kontrollermetoden, se [[ADR-0024 Tunna controllers och actions]].
 *
 * Flödet, i ordning: hash och sniffning UTANFÖR transaktionen (dyrt, rör
 * inte databasen), sedan i en transaktion: lås befintlig rad på hashen,
 * öka dess `reference_count` om den finns (skriv INGA bytes), eller skriv
 * bytena och skapa raden med `reference_count = 1`, och skapa slutligen
 * attachment-raden. Unikhetsindexet på `content_hash` är sanningen — två
 * samtidiga uppladdningar av samma byten kan kollidera på det innan låset
 * tas, och QueryException-fångsten nedan gör brottet till
 * incrementsvägen i stället för en andra rad (§ Beslut 10). Bytena på
 * disken är per definition identiska när hashen är densamma, så en
 * avbruten körning lämnar bara ofarliga överblivna byten.
 *
 * `uploaded_by_user_id` kommer från token (§ Beslut 14), `billed_account_id`
 * från kroppens `account` efter medlemskapskontrollen (§ Beslut 2). Båda,
 * liksom `item_id` och `stored_file_id`, sätts explicit — aldrig via
 * massildelning.
 */
class StoreAttachment
{
    public function handle(Item $item, UploadedFile $file, User $user, Account $account): Attachment
    {
        // Hashen beräknas alltid på servern, ur den mottagna temporära
        // filen (Beslut 3); MIME-typen sniffas ur innehållet, aldrig ur
        // klientens Content-Type (Beslut 4).
        $hash = hash_file('sha256', $file->getRealPath());
        $mimeType = $file->getMimeType();
        $byteSize = $file->getSize();
        $storagePath = $this->storagePathFromHash($hash);
        $filename = $this->cleanFilename($file->getClientOriginalName());

        return DB::transaction(function () use ($item, $file, $user, $account, $hash, $mimeType, $byteSize, $storagePath, $filename): Attachment {
            $storedFile = StoredFile::where('content_hash', $hash)->lockForUpdate()->first();

            if ($storedFile === null) {
                // Bytena först, raden sedan — en avbruten körning kan lämna
                // överblivna byten, vilket är ofarligt: filen är
                // innehållsadresserad, så att skriva om samma sökväg är per
                // definition identiskt innehåll (§ Beslut 10).
                Storage::disk('files')->putFileAs(dirname($storagePath), $file, basename($storagePath));

                try {
                    $storedFile = StoredFile::create([
                        'content_hash' => $hash,
                        'byte_size' => $byteSize,
                        'mime_type' => $mimeType,
                        'storage_path' => $storagePath,
                        'reference_count' => 1,
                        'scan_status' => 'skipped',
                    ]);
                } catch (QueryException $e) {
                    // Unikhetsbrott på content_hash: en samtidig uppladdning
                    // skapade raden mellan lockForUpdate-uppslaget och den
                    // här inserten. Bytena vi just skrev är identiska per
                    // definition (samma hash → samma innehåll), så dedupen
                    // faller tillbaka på incrementsvägen. Skapa aldrig en
                    // andra rad, låt aldrig felet nå användaren.
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }

                    $storedFile = StoredFile::where('content_hash', $hash)->firstOrFail();
                    $storedFile->increment('reference_count');
                }
            } else {
                // Increment är en SQL-operation, inte läs-ändra-skriv i PHP —
                // samtidiga ökningar går förlorade om raden läses in och +1
                // görs i minnet (§ Att se upp med).
                $storedFile->increment('reference_count');
            }

            $attachment = new Attachment;
            $attachment->item_id = $item->id;
            $attachment->stored_file_id = $storedFile->id;
            $attachment->filename = $filename;
            $attachment->kind = $this->kindFromMime($mimeType);
            $attachment->uploaded_by_user_id = $user->id;
            $attachment->billed_account_id = $account->id;
            $attachment->save();

            // Resursen läser storedFile/billedAccount genom relationerna —
            // sätt dem direkt så inget oplanerat lazy-load sker.
            $attachment->setRelation('storedFile', $storedFile);
            $attachment->setRelation('billedAccount', $account);

            return $attachment;
        });
    }

    /**
     * Sökvägen relativt diskens `files`-rot, byggd ur hashen (Beslut 6):
     * de två första och de två därpå följande hex-tecknen som
     * katalognivåer, sedan hela hashen som filnamn. Ingen filändelse —
     * typen står i `mime_type`, och en ändelse på disken vore en andra
     * sanning om vad filen är.
     */
    private function storagePathFromHash(string $hash): string
    {
        return substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    }

    /**
     * `kind` härleds ur den SNIFFADE typen, aldrig ur filändelsen (Beslut 5).
     */
    private function kindFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (
            $mime === 'application/pdf'
            || str_starts_with($mime, 'text/')
            || $mime === 'application/msword'
            || str_starts_with($mime, 'application/vnd.openxmlformats-officedocument.')
            || str_starts_with($mime, 'application/vnd.oasis.opendocument.')
            || $mime === 'application/vnd.ms-excel'
        ) {
            return 'document';
        }

        return 'other';
    }

    /**
     * Användarens namn på filen, städat men inte omdöpt (Beslut 11):
     * sökvägskomponenter bort, kontrolltecken bort, klippt till 255 tecken.
     * Namnet används aldrig för att bygga en sökväg på disken — den
     * kommer ur hashen och bara ur hashen.
     */
    private function cleanFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        return mb_substr($name, 0, 255);
    }
}
