<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\ChangePrivacyRequestStatus;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:status` — flytta ett ärende mellan statusarna. Se [[ADR-0095
 * Integritetsbegäranden]] § 1 och § 4.
 *
 * Kommandot körs för hand över SSH av den som sköter ärendena, och
 * schemaläggs aldrig: åtkomsten till ärendena är åtkomsten till servern
 * (ADR-0095 § 1). Handläggarens namn ur `--by` sparas på händelsen; ett
 * saknat `--by` är ett fel, för en händelse utan avsändare är inget bevis.
 *
 * Reglerna för bytet — öppet till öppet fritt, skäl till avslut utom
 * `completed`, skäl för att öppna igen, inget byte mellan två avslutade
 * lägen — bor i App\Actions\Privacy\ChangePrivacyRequestStatus, som prövar
 * dem innan något skrivs.
 */
class PrivacyStatus extends Command
{
    protected $signature = 'privacy:status
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {status : En av statusarna i config/privacy.php}
                            {--reason= : Skälet; krävs för att avsluta utom som completed och för att öppna igen}
                            {--by= : Handläggarens namn}';

    protected $description = 'Flytta ett integritetsärende mellan statusarna.';

    public function handle(ChangePrivacyRequestStatus $change): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            // Ingen händelse skrivs: ett okänt ärende finns inte att flytta,
            // och en `status_changed`-rad hade påstått motsatsen.
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $change->handle(
                request: $request,
                status: (string) $this->argument('status'),
                reason: $this->text('reason'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line("Status: {$request->status}");

        if ($request->outcome_reason !== null) {
            $this->line("Skäl: {$request->outcome_reason}");
        }

        if ($request->closed_at !== null) {
            $this->line('Avslutat: '.$request->closed_at->format('Y-m-d'));
        }

        return self::SUCCESS;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts. Samma
     * hjälpare som PrivacyOpen och PrivacyShow använder.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
