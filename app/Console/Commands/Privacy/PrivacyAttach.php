<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\AttachPrivacyAttachment;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:attach` — lägg en fil på ärendet. Se [[ADR-0095
 * Integritetsbegäranden]] § 10.
 *
 * Filen kopieras in på disken `privacy`; källfilen lämnas orörd, och
 * utskriften påminner om att radera den om den inte behövs (ADR-0095 § 10:
 * inga onödiga kopior). Namnet, storleken och SHA-256 skrivs ut — det är
 * kontraktet för den som ska kunna se att rätt fil lades till.
 *
 * Ett avslutat ärende tar fortfarande emot bilagor: svaret arkiveras ofta
 * efter avslutet, och kommandot prövar därför inte statusen.
 *
 * Logiken bor i App\Actions\Privacy\AttachPrivacyAttachment.
 */
class PrivacyAttach extends Command
{
    protected $signature = 'privacy:attach
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {path : Sökvägen till filen som ska läggas till}
                            {--by= : Handläggarens namn}';

    protected $description = 'Lägg en fil på ett integritetsärende.';

    public function handle(AttachPrivacyAttachment $attach): int
    {
        // --by är obligatoriskt på varje privacy-kommando: händelsen bär
        // handläggarens namn, och en händelse utan avsändare är inget bevis
        // (ADR-0095 § 1).
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
            $attachment = $attach->handle(
                request: $request,
                path: (string) $this->argument('path'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            // En sökväg som inte är en läsbar vanlig fil och en fil över
            // gränsen. Ingenting har skrivits när actionen kastar.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Bilaga: {$attachment->ulid}");
        $this->line("Namn: {$attachment->original_name}");
        $this->line("Storlek: {$attachment->size_bytes} byte");
        $this->line("SHA-256: {$attachment->sha256}");
        $this->line('Källfilen är orörd. Radera den om den inte behövs.');

        return self::SUCCESS;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts. Samma
     * hjälpare som de andra privacy-kommandona använder.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
