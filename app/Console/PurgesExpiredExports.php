<?php

namespace App\Console;

use App\Models\Export;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gallrar exportartefakter vars retention passerat — issue 41b § Beslut 6.
 * Retentionen står i config/files.php § export_retention_days. Tre uppgifter:
 *
 *  1. Färdiga exporter (`ready`) vars `expires_at` passerat: bytena tas bort
 *     från disken och raden sätts till `expired` med `storage_path` och
 *     `byte_size` null. Raden raderas inte — beställningen är historik,
 *     precis som en levererad webhook.
 *  2. Misslyckade exporter (`failed`) äldre än retentionen som ändå fått en
 *     artefakt (`storage_path` satt): filen tas bort och `storage_path` och
 *     `byte_size` nollställs. Statusen förblir `failed` — `expired` betyder
 *     att artefakten fanns och gallrades efter sitt leveransfönster, och att
 *     skriva över en `failed`-rad med det raderar just den historik raden
 *     finns till för (beställningen är historik, precis som en levererad
 *     webhook). Idempotensen kommer av urvalet, inte statusen: en rad som
 *     städats har `storage_path` null och väljs aldrig igen.
 *  3. Föräldralösa `.part`-filer äldre än ett dygn tas bort från
 *     `exports/`-katalogen. En krasch mitt i ett bygge lämnar en sådan, och
 *     ingen annan kod städar den.
 *
 * Bytena först, raden sedan (Beslut 7): en fil som redan saknas på disken är
 * ingen felsignal — jobbet fortsätter och uppdaterar raden ändå. Kastar
 * filraderingen ligger raden kvar och nästa körning försöker igen.
 *
 * En rad i taget, och ett fel stoppar inte de andra (Beslut 9): varje rad
 * ligger i sitt eget försök och loggas med `export.purge_failed` med ULID och
 * undantag, precis som de andra gallringsjobben. All filhantering går genom
 * `Storage::disk('files')`, aldrig `unlink()` eller absolut sökväg.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command(...)` eller `->runInBackground()` — se AGENTS.md §
 * Driftmiljön saknar proc_open. Klassen är medvetet fri från Artisan-beroenden
 * och testas direkt.
 */
class PurgesExpiredExports
{
    /**
     * Gallrar allt som passerat retentionen.
     *
     * @return int Antal exportrader vars artefakt gallrades — `ready`-rader
     *             som sattes till `expired` och `failed`-rader vars fil togs
     *             bort. `.part`-filerna räknas inte — de är inga rader.
     */
    public function handle(): int
    {
        $retentionDays = (int) config('files.export_retention_days');

        $gallrade = $this->purgeReady(now())
            + $this->purgeFailed(now()->subDays($retentionDays));
        $partFiles = $this->purgePartFiles(now()->subDay());

        if ($gallrade > 0 || $partFiles > 0) {
            Log::info("Gallrade {$gallrade} exporter och {$partFiles} .part-filer.");
        }

        return $gallrade;
    }

    /**
     * @return int Antal rader som sattes till `expired`.
     */
    private function purgeReady(Carbon $now): int
    {
        $gallrade = 0;

        Export::query()
            ->where('status', Export::STATUS_READY)
            ->where('expires_at', '<=', $now)
            ->chunkById(100, function ($exports) use (&$gallrade): void {
                foreach ($exports as $export) {
                    if ($this->purgeReadyRow($export)) {
                        $gallrade++;
                    }
                }
            });

        return $gallrade;
    }

    private function purgeReadyRow(Export $export): bool
    {
        try {
            return DB::transaction(function () use ($export): bool {
                // Låset och omläsningen följer PurgesExpiredStoredFiles form:
                // chunkens WHERE gäller bara vid SELECT-tillfället, och raden
                // ska inte gallras två gånger om något hunnit ändra den.
                $locked = Export::query()->whereKey($export->getKey())->lockForUpdate()->first();

                if ($locked === null
                    || $locked->status !== Export::STATUS_READY
                    || $locked->expires_at === null
                    || $locked->expires_at->isFuture()
                    || $locked->storage_path === null) {
                    return false;
                }

                Storage::disk('files')->delete($locked->storage_path);

                $locked->status = Export::STATUS_EXPIRED;
                $locked->storage_path = null;
                $locked->byte_size = null;
                $locked->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('export.purge_failed', [
                'export_ulid' => $export->ulid,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Rader som aldrig blev hämtbara men ändå fått byten på disken. Åldern
     * räknas från `created_at` — en `failed`-export fick aldrig något
     * leveransfönster, så `expires_at` kan vara null och är inget 41b ska
     * bero på. `whereNotNull('storage_path')` är den vanliga "redan
     * städad"-vakten: när raden gallrats är `storage_path` null och urvalet
     * hittar den inte igen.
     *
     * @return int Antal rader vars artefakt togs bort (statusen förblir `failed`).
     */
    private function purgeFailed(Carbon $cutoff): int
    {
        $gallrade = 0;

        Export::query()
            ->where('status', Export::STATUS_FAILED)
            ->whereNotNull('storage_path')
            ->where('created_at', '<=', $cutoff)
            ->chunkById(100, function ($exports) use (&$gallrade): void {
                foreach ($exports as $export) {
                    if ($this->purgeFailedRow($export)) {
                        $gallrade++;
                    }
                }
            });

        return $gallrade;
    }

    private function purgeFailedRow(Export $export): bool
    {
        try {
            return DB::transaction(function () use ($export): bool {
                $locked = Export::query()->whereKey($export->getKey())->lockForUpdate()->first();

                if ($locked === null
                    || $locked->status !== Export::STATUS_FAILED
                    || $locked->storage_path === null) {
                    return false;
                }

                Storage::disk('files')->delete($locked->storage_path);

                // Statusen förblir `failed` — `expired` reserveras för en
                // export som blev hämtbar och gallrades efter sitt
                // leveransfönster. En `.part`-fil hör inte hit: den städas av
                // katalogsvepet (uppgift 3) på mtime, oavsett vilken rad den
                // tillhörde — en härledd sökväg här skulle duplicera 41a:s
                // namnkonvention och missa kraschfallen där jobbet dog innan
                // `storage_path` ens skrevs.
                $locked->storage_path = null;
                $locked->byte_size = null;
                $locked->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('export.purge_failed', [
                'export_ulid' => $export->ulid,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tar bort föräldralösa `.part`-filer i exports-katalogen som är äldre än
     * ett dygn. En `.part`-fil har ingen databasrad att hänga på (raden är
     * kvar i `running` om processen dog), så städningen går på katalogen.
     *
     * @return int Antal borttagna filer.
     */
    private function purgePartFiles(Carbon $cutoff): int
    {
        $removed = 0;

        foreach (Storage::disk('files')->allFiles('exports') as $path) {
            if (! str_ends_with($path, '.part')) {
                continue;
            }

            try {
                if (Storage::disk('files')->lastModified($path) > $cutoff->getTimestamp()) {
                    continue;
                }

                Storage::disk('files')->delete($path);
                $removed++;
            } catch (Throwable $e) {
                Log::warning('export.purge_failed', [
                    'export_ulid' => Str::before(basename($path), '.zip.part'),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }
}
