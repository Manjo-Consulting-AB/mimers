<?php

namespace App\Actions\Attachment;

use App\Actions\Usage\AdjustUsage;
use App\Jobs\GenerateImageDerivatives;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sparar en uppladdad fil och kopplar den till ett item, med dedupen från
 * issue 16a § Beslut 10 — en instansklass med handle(), injicerad i
 * kontrollermetoden, se [[ADR-0024 Tunna controllers och actions]].
 *
 * Flödet, i ordning: hash och sniffning UTANFÖR transaktionen (dyrt, rör
 * inte databasen), sedan bytena till disken UTANFÖR transaktionen (disk-I/O
 * får aldrig hålla radlåset), och slutligen i en transaktion: lås befintlig
 * rad på hashen, öka dess `reference_count` om den finns, eller skapa raden
 * med `reference_count = 1`, och skapa attachment-raden. En befintlig rad
 * som ökas får också `purge_after` nollställt i samma skrivning (issue 17a
 * § Beslut 5) — annars skulle 17b radera bytena under fötterna på den nya
 * bilagan 30 dagar efter att någon ANNAN användare gallrade sin.
 *
 * Disk-skrivningen utanför transaktionen hoppar över rader som är markerade
 * för gallring (`purge_after` satt), så en ny uppladdning av samma innehåll
 * lägger bytena tillbaka. Det stänger inte fönstret helt — 17b kan unlinka
 * mellan skrivningen och låset. 17b måste hålla radlåset över
 * byteraderingen och läsa om `reference_count`/`purge_after` under låset
 * innan den rör disken.
 *
 * Unikhetsindexet på `content_hash` är sanningen — två samtidiga
 * uppladdningar av samma byten kan kollidera på det innan låset tas, och
 * QueryException-fångsten nedan gör just uniknyckelbrottet (MySQL 1062,
 * SQLite 2067) till incrementsvägen i stället för en andra rad (§ Beslut
 * 10). Ett annat integritetsbrott är aldrig en dubblett och kastas vidare.
 * En dödlåsning på gap-låset (SQLSTATE 40001) kastas också vidare och
 * retryas av `DB::transaction` (andra argumentet = antal försök), se
 * kodgranskningsfynd 1 och 2.
 *
 * Bytena på disken är per definition identiska när hashen är densamma, så
 * en avbruten körning lämnar bara ofarliga överblivna byten (§ Beslut 10).
 *
 * `uploaded_by_user_id` kommer från token (§ Beslut 14), `billed_account_id`
 * från kroppens `account` efter medlemskapskontrollen (§ Beslut 2). Båda,
 * liksom `item_id` och `stored_file_id`, sätts explicit — aldrig via
 * massildelning.
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * se [[ADR-0024 Tunna controllers och actions]]. Räknaren är en beroendefri,
 * tillståndslös lövaction utan egna beroenden att injicera eller mocka, och
 * den här actionen är befintlig kod som 26a bara lägger ett anrop i; att trä
 * räknaren genom konstruktorn vore omarbetning utan mottagare.
 */
class StoreAttachment
{
    public function handle(Item $item, UploadedFile $file, User $user, Account $account): Attachment
    {
        // Hashen beräknas alltid på servern, ur den mottagna temporära
        // filen (Beslut 3); MIME-typen sniffas ur innehållet, aldrig ur
        // klientens Content-Type (Beslut 4). Både getRealPath()
        // (string|false) och getMimeType() (?string) kan slå fel — explicit
        // guard och fallback, se kodgranskningsfynd 3.
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new RuntimeException('Den mottagna filen kunde inte läsas.');
        }

        $hash = hash_file('sha256', $realPath);
        if ($hash === false) {
            throw new RuntimeException('Den mottagna filen kunde inte hashas.');
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $byteSize = $file->getSize();
        $storagePath = $this->storagePathFromHash($hash);
        $filename = $this->cleanFilename($file->getClientOriginalName());

        // Bytena skrivs UTANFÖR transaktionen — en skrivning på upp till
        // taket (64 MiB) får inte hålla radlåset och serialisera samtidiga
        // uppladdare på disk-I/O (kodgranskningsfynd 4). Kontrollen nedan är
        // bara en optimering för att slippa skriva när raden redan finns;
        // den är inte auktoritativ, och en redundant skrivning till samma
        // innehållsadresserade sökväg är per definition ofarlig.
        //
        // `whereNull('purge_after')` (issue 17a § Beslut 5): raden kan finnas
        // men vara MARKERAD för gallring — 17b är på väg att ta bort bytena.
        // Då ska vi inte hoppa över skrivningen: en ny uppladdning av samma
        // innehåll måste lägga bytena på disken igen innan den nollställer
        // markeringen i transaktionen. Obs — detta stänger inte fönstret
        // helt: 17b hinner fortfarande unlinka mellan den här skrivningen och
        // `lockForUpdate` nedan. 17b MÅSTE hålla radlåset över byteraderingen
        // och läsa om `reference_count`/`purge_after` under låset innan den
        // rör disken, annars ärver den en tyst dataförlust.
        if (StoredFile::where('content_hash', $hash)->whereNull('purge_after')->doesntExist()) {
            Storage::disk('files')->putFileAs(dirname($storagePath), $file, basename($storagePath));
        }

        // Fångas av stängningen via referens. Sätts bara i grenen där en NY
        // stored_file skapades; se dispatchen efter transaktionen (Beslut 3).
        $nyStoredFile = null;

        $attachment = DB::transaction(function () use ($item, $user, $account, $hash, $mimeType, $byteSize, $storagePath, $filename, &$nyStoredFile): Attachment {
            // Nollställs per försök: retryar DB::transaction hela stängningen
            // (dödläge 40001) får inte en skapad stored_file från ett
            // rullat tillbaka försök dispatchen — den raden finns inte.
            $nyStoredFile = null;

            $storedFile = StoredFile::where('content_hash', $hash)->lockForUpdate()->first();

            if ($storedFile === null) {
                try {
                    $storedFile = StoredFile::create([
                        'content_hash' => $hash,
                        'byte_size' => $byteSize,
                        'mime_type' => $mimeType,
                        'storage_path' => $storagePath,
                        'reference_count' => 1,
                        'scan_status' => 'skipped',
                    ]);

                    $nyStoredFile = $storedFile;
                } catch (QueryException $e) {
                    // Uniknyckelbrott på content_hash: en samtidig
                    // uppladdning skapade raden mellan lockForUpdate-uppslaget
                    // och den här inserten. Bytena på disken (skrivna före
                    // transaktionen) är identiska per definition — samma hash
                    // → samma innehåll — så dedupen faller tillbaka på
                    // incrementsvägen. Skapa aldrig en andra rad, låt aldrig
                    // felet nå användaren.
                    if (! $this->isDuplicateEntry($e)) {
                        throw $e;
                    }

                    // lockForUpdate är en "current read" som ser det senast
                    // committade — en vanlig consistent read kan under
                    // REPEATABLE READ läsa ur en förlegad snapshot och missa
                    // raden som uniknyckelbrottet just bevisade finns.
                    // `purge_after` nollställs i samma skrivning, se Beslut 5.
                    $storedFile = StoredFile::where('content_hash', $hash)->lockForUpdate()->firstOrFail();
                    $storedFile->increment('reference_count', 1, ['purge_after' => null]);
                }
            } else {
                // Increment är en SQL-operation, inte läs-ändra-skriv i PHP —
                // samtidiga ökningar går förlorade om raden läses in och +1
                // görs i minnet (§ Att se upp med). `purge_after` nollställs i
                // SAMMA skrivning: en gammal gallringsmarkering från en annan
                // användares borttagna bilaga får inte överleva in i den nya
                // bilagans livslängd (issue 17a § Beslut 5).
                $storedFile->increment('reference_count', 1, ['purge_after' => null]);
            }

            // Den AUKTORITATIVA kvotkontrollen, under radlåset på kontots
            // usage_counter-rad och före attachment-raden skapas (issue 27b §
            // Beslut 4). Kontrollern har redan gjort en billig avvisning, men
            // den ser en siffra som kan vara inaktuell: två samtidiga
            // uppladdningar kan båda passera den tidiga kontrollen, och
            // lockForUpdate här serialiserar dem — den andra ser den förstas
            // ökning och nekas. Kastet rullar tillbaka hela transaktionen,
            // inklusive stored_file-raden som just skapades eller ökades.
            // Räknat på $storedFile->byte_size — samma tal AdjustUsage lägger
            // till i räknaren strax nedanför.
            (new Entitlements)->assertStorageWithinLimit($account, $storedFile->byte_size);

            $attachment = new Attachment;
            $attachment->item_id = $item->id;
            $attachment->stored_file_id = $storedFile->id;
            $attachment->filename = $filename;
            $attachment->kind = $this->kindFromMime($mimeType);
            $attachment->uploaded_by_user_id = $user->id;
            $attachment->billed_account_id = $account->id;
            $attachment->save();

            // Förbrukningen räknas transaktionellt (issue 26a): bilagan är
            // levande, så kontots räknare ökar med bytena i SAMMA transaktion
            // som raden — även i dedup-grenen, där stored_file-redan fanns
            // (logisk storlek, inte diskförbrukning). Ökningen måste ligga
            // inuti stängningen: DB::transaction retryar hela stängningen vid
            // dödläge, och en ökning utanför skulle räknas en gång per försök.
            // Bytena läses ur $storedFile->byte_size — samma kolumn som
            // sanningsfrågan i UsageCounter::calculateStorageBytes() summerar.
            // UploadedFile::getSize() (int|false) är en annan källa och är
            // dessutom inte det auktoritativa värdet i dedup-grenen
            // (granskningsfynd 3).
            (new AdjustUsage)->handle($account->id, bytesDelta: $storedFile->byte_size);

            // Resursen läser storedFile/billedAccount genom relationerna —
            // sätt dem direkt så inget oplanerat lazy-load sker.
            $attachment->setRelation('storedFile', $storedFile);
            $attachment->setRelation('billedAccount', $account);

            return $attachment;
        }, 3);

        // Beslut 3: jobbet köas först efter att transaktionen committat — en
        // arbetare som plockar jobbet innan raden finns skulle misslyckas —
        // och bara i grenen där en NY stored_file skapades. En dedup-träff
        // har redan sina derivat; att köa om dem är arbete som skriver samma
        // filer igen. Andra villkoret: bara de tre MIME-typerna i Beslut 4
        // kan få derivat — annars köas ingenting.
        if ($nyStoredFile !== null && GenerateImageDerivatives::supportsMime($nyStoredFile->mime_type)) {
            GenerateImageDerivatives::dispatch($nyStoredFile);
        }

        return $attachment;
    }

    /**
     * Är QueryException ett uniknyckelbrott på content_hash? 23000 (hela
     * klassen "integrity constraint violation") räcker inte som test — den
     * täcker även NOT NULL- och främmandenyckelbrott, som aldrig får tolkas
     * som en dubblett. Drivrutinskoden skiljer: MySQL 1062, SQLite 2067.
     */
    private function isDuplicateEntry(QueryException $e): bool
    {
        $driverCode = is_array($e->errorInfo) ? ($e->errorInfo[1] ?? null) : null;

        return $driverCode === 1062 // MySQL
            || $driverCode === 2067; // SQLite
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
        // Windows-separatorn är en sökvägskomponent också: basename() på
        // POSIX delar bara på '/', men en klient kan skicka
        // 'C:\fakepath\manual.pdf' (kodgranskningsfynd 7). `"` städas också
        // bort — Content-Disposition i 19a tar emot det här namnet.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/', '', $name) ?? '';

        return mb_substr($name, 0, 255);
    }
}
