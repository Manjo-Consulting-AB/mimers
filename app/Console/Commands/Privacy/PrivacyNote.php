<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\RecordPrivacyNote;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:note` — skriv en fri anteckning på ärendet. Se [[ADR-0095
 * Integritetsbegäranden]] § 7.
 *
 * Anteckningen är den enda händelsen utan värden ur en lista: allt som inte
 * hör till en bedömning, en åtgärd eller en kommunikation hamnar här.
 *
 * Logiken bor i App\Actions\Privacy\RecordPrivacyNote.
 */
class PrivacyNote extends Command
{
    protected $signature = 'privacy:note
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {text : Anteckningen, fritext}
                            {--by= : Handläggarens namn}';

    protected $description = 'Skriv en anteckning på ett integritetsärende.';

    public function handle(RecordPrivacyNote $note): int
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
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $note->handle(
                request: $request,
                text: (string) $this->argument('text'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line('Anteckning: sparad.');

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
