<?php

namespace App\Console;

use App\Models\StoredFile;
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
                        // Bytena först (Beslut 2). Disken 'files' har
                        // `throw => true`, så en fil som inte kan raderas
                        // kastar; en fil som redan är borta är en no-op och
                        // ingen felsignal.
                        Storage::disk('files')->delete($fil->storage_path);

                        if ($fil->delete()) {
                            $borttagna++;
                        }
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
