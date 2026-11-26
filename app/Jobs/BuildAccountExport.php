<?php

namespace App\Jobs;

use App\Actions\Notification\NotifyAccountExportReady;
use App\Models\Export;
use App\Support\Export\AccountExportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bygger en beställd kontoexport — issue 1701 · [[ADR-0098 Kontoexporten]].
 * Följer BuildContainerExport: `pending → running → ready/failed`, ZIP:en
 * skrivs till `.part` av byggaren och byter namn sist, och ett byggfel sätter
 * `failed` med `failure_reason` och tar bort `.part`.
 *
 * `expires_at` sätts **24 timmar** efter att exporten blivit klar
 * (`files.account_export_retention_hours`), inte de sju dagar som
 * containerexporten behåller. Gallringen går på `expires_at`.
 *
 * När exporten är klar skapas `account_export.ready`. Notisen ligger utanför
 * byggets `try`: ett fel i den får aldrig göra en färdig export till
 * `failed`.
 *
 * Ingen rutt eller knapp når jobbet än — de kommer i issue 507.
 */
class BuildAccountExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public Export $export) {}

    public function handle(): void
    {
        $export = $this->export;

        $export->status = Export::STATUS_RUNNING;
        $export->save();

        try {
            $storagePath = app(AccountExportBuilder::class)->build($export);

            $export->status = Export::STATUS_READY;
            $export->storage_path = $storagePath;
            $export->byte_size = Storage::disk('files')->size($storagePath);
            $export->expires_at = now()->addHours((int) config('files.account_export_retention_hours'));
            $export->failure_reason = null;
            $export->save();
        } catch (Throwable $e) {
            Log::error('Kontoexporten kunde inte byggas', [
                'export_id' => $export->id,
                'export_ulid' => $export->ulid,
                'exception' => $e->getMessage(),
            ]);

            $export->status = Export::STATUS_FAILED;
            $export->failure_reason = mb_substr($e->getMessage(), 0, 2000);
            $export->save();

            $this->deletePartial($export);

            return;
        }

        try {
            app(NotifyAccountExportReady::class)->handle($export);
        } catch (Throwable $e) {
            Log::warning('Notisen om den färdiga kontoexporten kunde inte skapas', [
                'export_id' => $export->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rensar en halvskriven `.part`-fil efter ett byggfel. Sökvägen byggs av
     * koden: `exports/account-{konto-ulid}/{export-ulid}.zip.part`.
     */
    private function deletePartial(Export $export): void
    {
        try {
            $account = $export->account;

            if ($account === null) {
                return;
            }

            Storage::disk('files')->delete('exports/account-'.$account->ulid.'/'.$export->ulid.'.zip.part');
        } catch (Throwable $e) {
            Log::warning('Den halvskrivna exportfilen kunde inte tas bort', [
                'export_id' => $export->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
