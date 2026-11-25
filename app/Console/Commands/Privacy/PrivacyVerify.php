<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\RecordPrivacyVerification;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:verify` — anteckna hur identiteten eller ett ombuds behörighet
 * prövades. Se [[ADR-0095 Integritetsbegäranden]] § 1 och § 2.
 *
 * Händelsen säger vad som prövades, inte vad som visades: `--method` är en av
 * metoderna i config/privacy.php, `--result` är `verified`, `insufficient`
 * eller `failed`, och `--note` är handläggarens egen anteckning. **Ingen
 * kopia av en identitetshandling sparas**, varken som text eller som bilaga.
 *
 * Logiken bor i App\Actions\Privacy\RecordPrivacyVerification.
 */
class PrivacyVerify extends Command
{
    protected $signature = 'privacy:verify
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {subject : identity eller authority}
                            {--method= : En av verifieringsmetoderna i config/privacy.php}
                            {--result= : verified, insufficient eller failed}
                            {--note= : Handläggarens anteckning, valfri fritext}
                            {--by= : Handläggarens namn}';

    protected $description = 'Anteckna en verifiering av identiteten eller ett ombuds behörighet.';

    public function handle(RecordPrivacyVerification $verify): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $method = $this->text('method');
        $result = $this->text('result');

        if ($method === null || $result === null) {
            $this->error('--method och --result måste anges.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $verify->handle(
                request: $request,
                subject: (string) $this->argument('subject'),
                method: $method,
                result: $result,
                note: $this->text('note'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line('Verifiering: '.$this->argument('subject')." · {$method} · {$result}");

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
