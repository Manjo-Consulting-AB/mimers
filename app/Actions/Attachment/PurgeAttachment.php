<?php

namespace App\Actions\Attachment;

use App\Actions\Usage\AdjustUsage;
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
 * Stegen i en transaktion (Beslut 2): först läses radens tillstånd (innan
 * forceDelete gör det oåtkomligt), sedan forceDelete på attachment-raden,
 * sedan minskas stored_file.reference_count som SQL — aldrig som
 * läs-ändra-skriv i PHP, och aldrig under noll (Beslut 3) — sedan lämnar
 * bytena kontots förbrukningsräkning om bilagan var levande (issue 26a §
 * Beslut 6), och slutligen läses räknaren om INOM transaktionen; är den noll
 * sätts purge_after = now() + 30 dagar.
 *
 * Bytena på disken rörs inte här (Beslut 4): efter actionen ligger filen
 * kvar med purge_after satt, och 17b tar hand om den. Det är de 30 dagarna
 * som skyddar mot buggen som råkar radera fel rader.
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * se [[ADR-0024 Tunna controllers och actions]]. Räknaren är en beroendefri,
 * tillståndslös lövaction utan egna beroenden att injicera eller mocka, och
 * den här actionen är befintlig kod som 26a bara lägger ett anrop i; att trä
 * räknaren genom konstruktorn vore omarbetning utan mottagare.
 */
class PurgeAttachment
{
    public function handle(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            // Steg 1 och issue 26a § Beslut 6 — läs tillståndet INNAN raden
            // försvinner: `billed_account_id`, `byte_size` och `deleted_at`
            // sitter på rader som den här actionen inte kan läsa efter
            // forceDelete. Den vanliga vägen — mjukradering, 30 dagar i
            // papperskorgen, gallring — minskade redan kontots räknare vid
            // mjukraderingen; ett andra avdrag här vore dubbelräkning. Men
            // PurgeContainer och PurgeContent::item() gallrar också bilagor
            // som ALDRIG mjukraderades, och för de raderna är det HÄR bytena
            // lämnar räkningen. withTrashed() eftersom bilagan ofta kommer
            // från papperskorgen.
            //
            // lockForUpdate — en current read. När actionen körs inifrån
            // PurgeContent::item()/PurgeContainer ligger den i en redan öppen
            // transaktion, och under REPEATABLE READ skulle en vanlig
            // consistent read kunna läsa ur en snapshot som togs innan en
            // användares mjukradering committades — då vore bilagan levande
            // också för den här gallringen, och avdraget gjort en andra gång
            // (granskningsfynd 1).
            $rad = Attachment::withTrashed()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $storedFileId = $rad->stored_file_id;
            $billedAccountId = $rad->billed_account_id;
            $byteSize = (int) StoredFile::query()->whereKey($storedFileId)->value('byte_size');
            $varLevande = ! $rad->trashed();

            // Steg 2 och Beslut 7 — idempotent per anrop, inte per rad:
            // forceDelete() på en rad som inte finns är en no-op i Eloquent
            // och går inte att skilja från en lyckad radering, så antalet
            // raderade rader är den enda tillförlitliga signalen. Ett andra
            // anrop med samma instans raderar 0 rader och får inte minska
            // räknaren igen.
            $raderade = Attachment::withTrashed()
                ->whereKey($attachment->getKey())
                ->forceDelete();

            if ($raderade === 0) {
                return;
            }

            // Steg 3 och Beslut 3: minskningen är en SQL-operation, aldrig
            // läs-ändra-skriv i PHP. Villkoret på > 0 skyddar INT
            // UNSIGNED-kolumnen — ett decrement på en nollräknare ger -1,
            // som i MySQL antingen kastar eller wrappar till ett gigantiskt
            // tal som betyder "radera aldrig". En rad som redan står på noll
            // är ett förväntat läge, inte ett fel.
            StoredFile::query()
                ->whereKey($storedFileId)
                ->where('reference_count', '>', 0)
                ->decrement('reference_count');

            // issue 26a — en bilaga som var LEVANDE precis innan gallringen
            // slutar vara levande nu, och bytena lämnar kontots räknare.
            // Klampningen i AdjustUsage skyddar mot att en redan drivande
            // räknare (26b:s avstämning har inte hunnit larma) går under noll.
            if ($varLevande) {
                (new AdjustUsage)->handle($billedAccountId, bytesDelta: -$byteSize);
            }

            // Sist: räknaren läses om INOM transaktionen — två samtidiga
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
