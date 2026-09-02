<?php

namespace App\Console;

use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fysisk radering av stored_file — se [[Filer och lagring]] § Radering,
 * [[ADR-0008 Soft delete och papperskorg]] och issue 17. Det enda stället i
 * systemet som tar bort en användares fil från disken utan väg tillbaka.
 *
 * Två villkor, båda måste gälla (Beslut 1): `reference_count = 0` OCH
 * `purge_after <= now()`. Räknaren ensam räcker inte — fördröjningen på 30
 * dagar är hela skyddet mot buggen som råkar radera fel rader. `purge_after`
 * ensam räcker inte — en rad kan ha fått en ny referens efter markeringen,
 * och även om PurgeAttachment nollställer kolumnen då ska jobbet inte lita
 * på att det alltid har skett.
 *
 * Bytena först, raden sedan (Beslut 2). Misslyckas filraderingen ligger
 * raden kvar så att nästa körning försöker igen; tas raden bort först och
 * filraderingen faller blir bytena föräldralösa och omöjliga att hitta. En
 * fil som redan saknas på disken är inte ett fel: raden tas bort ändå — ett
 * tidigare försök hann halvvägs.
 *
 * En rad i taget, och ett fel stoppar inte de andra (Beslut 3). Felet loggas
 * med `content_hash` och sökväg — loggen är driftens och sökvägen är det
 * enda som gör felet felsökbart — och körningen går vidare.
 *
 * Varje rad låses och läses om under låset innan disken rörs (kravet står i
 * StoreAttachment): chunkens WHERE gäller bara vid SELECT-tillfället, och en
 * uppladdning som hinner öka `reference_count` mellan SELECT och unlink ska
 * lämna raden orörd — annars ärver jobbet en tyst dataförlust där bilagan
 * pekar på byten som inte längre finns.
 *
 * All filhantering går genom `Storage::disk('files')` (Beslut 6), aldrig
 * `unlink()` eller absolut sökväg. Tomma prefixkataloger städas inte
 * (Beslut 7). Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Driftmiljön saknar proc_open — och
 * klassen är medvetet fri från Artisan-beroenden, av samma skäl som
 * App\Console\PrunesExpiredMagicLinkTokens.
 */
class PurgesExpiredStoredFiles
{
    /**
     * Gallrar alla stored_file vars markering har passerats.
     *
     * @return int Antal raderade rader.
     */
    public function handle(): int
    {
        $borttagna = 0;

        StoredFile::query()
            ->where('reference_count', 0)
            ->where('purge_after', '<=', now())
            ->chunkById(100, function ($filer) use (&$borttagna): void {
                foreach ($filer as $fil) {
                    try {
                        DB::transaction(function () use ($fil, &$borttagna): void {
                            // Radlåset hålls ÖVER byteraderingen, och villkoren
                            // läses om under låset — se StoreAttachment, som
                            // ställer kravet i klartext. En rad som fått en ny
                            // referens mellan chunkens SELECT och nu ska lämnas
                            // orörd; $fil i chunkens ställe är en inaktuell
                            // modellinstans och får aldrig avgöra.
                            $låst = StoredFile::query()->whereKey($fil->getKey())->lockForUpdate()->first();

                            if ($låst === null
                                || $låst->reference_count !== 0
                                || $låst->purge_after === null
                                || $låst->purge_after->isFuture()) {
                                return;
                            }

                            // Bytena först (Beslut 2). Disken 'files' har
                            // `throw => true`, så en fil som inte kan raderas
                            // kastar; en fil som redan är borta är en no-op och
                            // ingen felsignal. Kastar filraderingen rullas
                            // transaktionen tillbaka och raden ligger kvar för
                            // nästa körning. Låset över disk-I/O är kortvarigt —
                            // ett unlink, inte en skrivning på upp till 64 MiB.
                            Storage::disk('files')->delete($låst->storage_path);

                            if ($låst->delete()) {
                                $borttagna++;
                            }
                        });
                    } catch (Throwable $e) {
                        Log::error('Kunde inte fysiskt radera stored_file', [
                            'content_hash' => $fil->content_hash,
                            'storage_path' => $fil->storage_path,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($borttagna > 0) {
            Log::info("Fysiskt raderade {$borttagna} stored_file.");
        }

        return $borttagna;
    }
}
