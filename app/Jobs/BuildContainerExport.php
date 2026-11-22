<?php

namespace App\Jobs;

use App\Models\Export;
use App\Support\Export\BinderBuilder;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bygger en beställd export — se [[Backlog]] M6 § 41 och
 * App\Support\Export\ContainerExportBuilder. Jobbet är medvetet tunt
 * (Beslut 12): tre steg runt byggaren.
 *
 *  1. Sätt raden `running`.
 *  2. Anropa byggaren, som skriver ZIP:en till en `.part`-sökväg och byter
 *     namn som sista steg (Beslut 13).
 *  3. Sätt `ready` med `storage_path`, `byte_size` och `expires_at`
 *     (Beslut 7).
 *
 * Kastar byggaren sätter jobbet `failed` med `failure_reason` och tar bort
 * en halvskriven `.part`-fil — en `pending`-rad som aldrig blir något är ett
 * tillstånd användaren inte kan tolka.
 *
 * Kön dras med `queue:work` och jobbet är ett vanligt `ShouldQueue` — inget
 * `->runInBackground()`, ingen Schedule::command, se AGENTS.md §
 * Subprocesser i driftmiljön.
 */
class BuildContainerExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public Export $export) {}

    public function handle(): void
    {
        $export = $this->export;

        $export->status = Export::STATUS_RUNNING;
        $export->save();

        try {
            $storagePath = $this->builder($export)->build($export);

            $export->status = Export::STATUS_READY;
            $export->storage_path = $storagePath;
            $export->byte_size = Storage::disk('files')->size($storagePath);
            $export->expires_at = now()->addDays((int) config('files.export_retention_days'));
            $export->failure_reason = null;
            $export->save();
        } catch (Throwable $e) {
            Log::error('Exporten kunde inte byggas', [
                'export_id' => $export->id,
                'export_ulid' => $export->ulid,
                'exception' => $e->getMessage(),
            ]);

            $export->status = Export::STATUS_FAILED;
            $export->failure_reason = mb_substr($e->getMessage(), 0, 2000);
            $export->save();

            $this->deletePartial($export);
        }
    }

    /**
     * Byggaren väljs efter `kind` (issue 445): pärmen är en exportrad och
     * delar därför statusmaskinen, felhanteringen och `.part`-städningen här i
     * stället för att få ett eget jobb som skulle kopiera alla tre.
     */
    private function builder(Export $export): ContainerExportBuilder|BinderBuilder
    {
        return $export->kind === Export::KIND_BINDER
            ? app(BinderBuilder::class)
            : app(ContainerExportBuilder::class);
    }

    /**
     * Rensar en halvskriven `.part`-fil efter ett byggfel. Sökvägen byggs av
     * koden enligt Beslut 6 — `exports/{container-ulid}/{export-ulid}.zip.part` (`.pdf.part` för en pärm).
     */
    private function deletePartial(Export $export): void
    {
        try {
            $containerUlid = $export->container->ulid;

            $disk = Storage::disk('files');
            $base = 'exports/'.$containerUlid.'/'.$export->ulid;

            $disk->delete($base.'.zip.part');
            // Pärmens `.part` och dess temporärmapp (issue 445).
            $disk->delete($base.'.pdf.part');
            $disk->deleteDirectory($base.'.parts');
        } catch (Throwable $e) {
            Log::warning('Den halvskrivna exportfilen kunde inte tas bort', [
                'export_id' => $export->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
