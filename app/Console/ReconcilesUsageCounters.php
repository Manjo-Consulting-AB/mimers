<?php

namespace App\Console;

use App\Models\Account;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nattlig avstämning av usage_counter mot de rader den cachar — se
 * [[Planer och kvoter]] § usage_counter och issue 26. Räknare driver alltid
 * isär till slut: 26a håller räknaren i takt transaktionellt, det här jobbet
 * räknar om summorna, rättar det som glidit och larmar — så att en drift blir
 * något någon får veta om, inte något som upptäcks av en kund som nekas en
 * uppladdning hon har utrymme för.
 *
 * Raderna är sanningen, räknaren är cachen (Beslut 2). Frågorna nedan är
 * aggregate-formen av den per-konto-formulering som står i
 * App\Models\UsageCounter::calculateStorageBytes() och
 * calculateContainerCount() — samma predikat, grupperade över alla konton i
 * stället för parametriserade på ett. Predikaten får inte formuleras om:
 * glider avstämningens definition från räknarens är driften jobbet rapporterar
 * sin egen, och då rättar den sönder en räknare som var rätt.
 *
 * Jobbet rättar OCH larmar, i den ordningen, och bara vid avvikelse (Beslut
 * 3). Ett konto vars räknare stämmer rörs inte alls — ingen skrivning, ingen
 * loggrad. En saknad rad är också en avvikelse (Beslut 4): ett konto med
 * bilagor eller containers men utan rad får en med de räknade värdena — det
 * är bakåtfyllningen som 26a § Beslut 7 lämnade hit. Ett konto utan innehåll
 * och utan rad är däremot ingen avvikelse; inga tomma rader skapas för konton
 * som bara finns.
 *
 * Antalet frågor växer med antalet omgångar, inte med antalet konton (Beslut
 * 5): två GROUP BY-frågor över hela systemet plus en chunkad genomgång av
 * räknarraderna räcker. Jobbet öppnar ingen lång transaktion (Beslut 6):
 * varje rättning är en egen liten skrivning.
 *
 * Kapplöpningen mot en pågående uppladdning är verklig och ofarlig: läser
 * jobbet summan innan en uppladdning committar och skriver räknaren efter,
 * blir kontot en bilaga för lågt räknat — nästa natt rättas det. Det som inte
 * är ofarligt är att låsa, och det gör jobbet aldrig.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` — se AGENTS.md § Driftmiljön saknar proc_open — och
 * klassen är medvetet fri från Artisan-beroenden, av samma skäl som
 * App\Console\PurgesExpiredStoredFiles.
 */
class ReconcilesUsageCounters
{
    /**
     * Räknar om kontons förbrukning och rättar det som glidit.
     *
     * @return int Antal konton vars räknare rättades (drift eller saknad rad).
     */
    public function handle(): int
    {
        $storageActual = $this->storageBytesPerAccount();
        $containerActual = $this->containerCountPerAccount();

        $drift = 0;
        $konton = 0;
        $allaKontoId = [];

        // Genomgången av räknarraderna, en omgång per 100 rader. Join mot
        // account ger ULID:en för loggraden; FK RESTRICT garanterar att raden
        // finns, så join:en ändrar inte vilka rader som kommer med.
        DB::table('usage_counter')
            ->join('account', 'account.id', '=', 'usage_counter.account_id')
            ->select([
                'usage_counter.id',
                'usage_counter.account_id',
                'usage_counter.storage_bytes',
                'usage_counter.container_count',
                'account.ulid as account_ulid',
            ])
            ->chunkById(100, function ($rader) use (&$allaKontoId, &$drift, &$konton, $storageActual, $containerActual): void {
                foreach ($rader as $rad) {
                    $allaKontoId[] = (int) $rad->account_id;
                    $konton++;

                    $storage = $storageActual[(int) $rad->account_id] ?? 0;
                    $containers = $containerActual[(int) $rad->account_id] ?? 0;

                    $andringar = [];

                    if ((int) $rad->storage_bytes !== $storage) {
                        $this->loggaDrift($rad->account_ulid, 'storage_bytes', (int) $rad->storage_bytes, $storage);
                        $andringar['storage_bytes'] = $storage;
                    }

                    if ((int) $rad->container_count !== $containers) {
                        $this->loggaDrift($rad->account_ulid, 'container_count', (int) $rad->container_count, $containers);
                        $andringar['container_count'] = $containers;
                    }

                    if ($andringar !== []) {
                        UsageCounter::query()->where('account_id', $rad->account_id)->update($andringar);
                        $drift++;
                    }
                }
            }, 'usage_counter.id', 'id');

        // Konton som har innehåll men ingen räknarrad — bakåtfyllningen från
        // 26a § Beslut 7. Bara konton med levande innehåll dyker upp i
        // aggregaten, så inga tomma rader skapas för konton som bara finns.
        $saknade = array_diff(
            array_keys($storageActual + $containerActual),
            $allaKontoId,
        );

        if ($saknade !== []) {
            $ulider = Account::query()->whereIn('id', $saknade)->pluck('ulid', 'id');

            foreach ($saknade as $accountId) {
                $storage = $storageActual[$accountId] ?? 0;
                $containers = $containerActual[$accountId] ?? 0;

                if ($storage > 0) {
                    $this->loggaDrift($ulider[$accountId], 'storage_bytes', 0, $storage);
                }

                if ($containers > 0) {
                    $this->loggaDrift($ulider[$accountId], 'container_count', 0, $containers);
                }

                UsageCounter::create([
                    'account_id' => $accountId,
                    'storage_bytes' => $storage,
                    'container_count' => $containers,
                ]);

                $drift++;
                $konton++;
            }
        }

        // En nolla varje natt är den signal som gör en etta värd något.
        Log::info('usage_counter.reconciled', [
            'accounts' => $konton,
            'drifted' => $drift,
        ]);

        return $drift;
    }

    /**
     * Sanningen bakom `usage_counter.storage_bytes`: summan av bytena för
     * kontots levande bilagor, grupperad per belastat konto. Aggregate-formen
     * av UsageCounter::calculateStorageBytes() — samma join, samma predikat.
     *
     * @return array<int, int>
     */
    private function storageBytesPerAccount(): array
    {
        return DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->whereNull('attachment.deleted_at')
            ->groupBy('attachment.billed_account_id')
            ->selectRaw('attachment.billed_account_id as account_id, COALESCE(SUM(stored_file.byte_size), 0) as total')
            ->pluck('total', 'account_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Sanningen bakom `usage_counter.container_count`: antalet levande
     * containers per ägande konto. Aggregate-formen av
     * UsageCounter::calculateContainerCount().
     *
     * @return array<int, int>
     */
    private function containerCountPerAccount(): array
    {
        return DB::table('container')
            ->whereNull('deleted_at')
            ->groupBy('account_id')
            ->selectRaw('account_id, COUNT(*) as total')
            ->pluck('total', 'account_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Larmet — en loggrad, inte en notis. Kanalerna byggs i M5 och
     * kvotvarningarna schemaläggs där; den här raden är kontraktet den senare
     * issuen hakar i.
     */
    private function loggaDrift(string $accountUlid, string $falt, int $counter, int $actual): void
    {
        Log::warning('usage_counter.drift', [
            'account_ulid' => $accountUlid,
            'field' => $falt,
            'counter' => $counter,
            'actual' => $actual,
            'delta' => $actual - $counter,
        ]);
    }
}
