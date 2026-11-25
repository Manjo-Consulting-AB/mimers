<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\DetachPrivacyAttachment;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:detach` — ta bort en bilaga från ärendet. Se [[ADR-0095
 * Integritetsbegäranden]] § 10.
 *
 * Både filen på disken och raden tas bort, och händelsen
 * `attachment_removed` skriver namnet, hashen och skälet. Skälet är
 * obligatoriskt: en borttagen bilaga är ett bevis som försvinner, och
 * `--reason` är det enda som förklarar varför.
 *
 * Logiken bor i App\Actions\Privacy\DetachPrivacyAttachment.
 */
class PrivacyDetach extends Command
{
    protected $signature = 'privacy:detach
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {attachment_ulid : Bilagans ULID}
                            {--reason= : Skälet till att bilagan tas bort}
                            {--by= : Handläggarens namn}';

    protected $description = 'Ta bort en bilaga från ett integritetsärende.';

    public function handle(DetachPrivacyAttachment $detach): int
    {
        // --by är obligatoriskt på varje privacy-kommando (ADR-0095 § 1).
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        // Skälet prövas före uppslagen: utan skäl ska ingenting röras, och
        // felet ska synas även om referensen råkar vara felstavad.
        $reason = $this->text('reason');

        if ($reason === null) {
            $this->error('--reason måste anges: skälet till att bilagan tas bort.');

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
            $attachment = $detach->handle(
                request: $request,
                attachmentUlid: (string) $this->argument('attachment_ulid'),
                reason: $reason,
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            // En okänd bilaga. Ingenting har tagits bort.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Bilaga: {$attachment->ulid}");
        $this->line("Namn: {$attachment->original_name}");
        $this->line('Borttagen.');

        return self::SUCCESS;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts. Ett
     * `--reason=` med bara blanksteg är inget skäl.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && trim($varde) !== '' ? $varde : null;
    }
}
