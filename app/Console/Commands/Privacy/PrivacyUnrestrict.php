<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\LiftPrivacyRestriction;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:unrestrict` — häv en gällande begränsning. Se [[ADR-0095
 * Integritetsbegäranden]] § 8.
 *
 * Kommandot körs för hand över SSH av den som sköter ärendena, och
 * schemaläggs aldrig: åtkomsten till ärendena är åtkomsten till servern
 * (ADR-0095 § 1). Handläggarens namn ur `--by` och skälet ur `--reason` är
 * obligatoriska — händelsen är beviset, och en hävd begränsning utan skäl går
 * inte att förklara i efterhand.
 *
 * **Utskriften påminner om underrättelsen.** Art. 18.3 kräver att den
 * registrerade underrättas innan begränsningen hävs, och beskedet antecknas
 * med `privacy:communicate`. Kommandot gör det inte åt handläggaren: det
 * skriver bara om att det ska göras.
 *
 * Logiken bor i App\Actions\Privacy\LiftPrivacyRestriction, testad direkt.
 */
class PrivacyUnrestrict extends Command
{
    protected $signature = 'privacy:unrestrict
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--reason= : Skälet till att begränsningen hävs}
                            {--by= : Handläggarens namn}';

    protected $description = 'Häv en begränsning på ett integritetsärende.';

    public function handle(LiftPrivacyRestriction $unrestrict): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $reason = $this->text('reason');

        if ($reason === null) {
            $this->error('--reason måste anges: skälet till att begränsningen hävs.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            // Ingen händelse skrivs: ett okänt ärende finns inte att häva
            // en begränsning på, och en `restriction_lifted`-rad hade
            // påstått motsatsen.
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $unrestrict->handle(
                request: $request,
                reason: $reason,
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line('Hävd: '.$request->restriction_lifted_at->format('Y-m-d'));
        $this->line("Skäl: {$reason}");

        // En hävd begränsning är inget besked. Den registrerade ska
        // underrättas innan (art. 18.3), och handläggaren antecknar det med
        // privacy:communicate.
        $this->warn(
            'Kom ihåg: den registrerade ska underrättas innan begränsningen hävs (art. 18.3), '
            .'och beskedet antecknas med privacy:communicate.'
        );

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
