<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\RecordPrivacyCommunication;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:communicate` — anteckna kommunikationen med den registrerade. Se
 * [[ADR-0095 Integritetsbegäranden]] § 7.
 *
 * Slaget är argumentet `{kind}`, riktningen `in` eller `out` och kanalen en av
 * kanalerna i config/privacy.php. Vad som sades står i `--note`, och
 * kommunikationen ändrar inte ärendets status.
 *
 * Logiken bor i App\Actions\Privacy\RecordPrivacyCommunication.
 */
class PrivacyCommunicate extends Command
{
    protected $signature = 'privacy:communicate
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {kind : Ett av kommunikationsslagen i config/privacy.php}
                            {--direction= : in eller out}
                            {--channel= : En av kanalerna i config/privacy.php}
                            {--note= : Handläggarens anteckning, valfri fritext}
                            {--by= : Handläggarens namn}';

    protected $description = 'Anteckna kommunikationen med den registrerade.';

    public function handle(RecordPrivacyCommunication $communicate): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $direction = $this->text('direction');
        $channel = $this->text('channel');

        if ($direction === null || $channel === null) {
            $this->error('--direction och --channel måste anges.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');
        $kind = (string) $this->argument('kind');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $communicate->handle(
                request: $request,
                kind: $kind,
                direction: $direction,
                channel: $channel,
                note: $this->text('note'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line("Kommunikation: {$kind} · {$direction} · {$channel}");

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
