<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\PlacePrivacyRestriction;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:restrict` — sätt en begränsning (art. 18) på ett ärende. Se
 * [[ADR-0095 Integritetsbegäranden]] § 8.
 *
 * Kommandot körs för hand över SSH av den som sköter ärendena, och
 * schemaläggs aldrig: åtkomsten till ärendena är åtkomsten till servern
 * (ADR-0095 § 1). Handläggarens namn ur `--by` sparas på händelsen; ett
 * saknat `--by` är ett fel, för en händelse utan avsändare är inget bevis.
 *
 * **`--scope` är obligatoriskt** och säger vilka uppgifter och vilka ändamål
 * begränsningen gäller — det är driftinstruktionen till personalen. En
 * begränsning är en markering, inte ett skrivskydd: den ändrar inget i appen
 * och läses inte av någon kod (ADR-0095 § 8).
 *
 * En gällande begränsning vägras; den ska hävas med `privacy:unrestrict`
 * först, så att historiken får ett slut och en ny begränsning en egen början.
 *
 * Logiken bor i App\Actions\Privacy\PlacePrivacyRestriction, testad direkt.
 */
class PrivacyRestrict extends Command
{
    protected $signature = 'privacy:restrict
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--scope= : Vilka uppgifter och vilka ändamål begränsningen gäller}
                            {--by= : Handläggarens namn}';

    protected $description = 'Sätt en begränsning på ett integritetsärende.';

    public function handle(PlacePrivacyRestriction $restrict): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $scope = $this->text('scope');

        if ($scope === null) {
            $this->error('--scope måste anges: vilka uppgifter och vilka ändamål begränsningen gäller.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            // Ingen händelse skrivs: ett okänt ärende finns inte att
            // begränsa, och en `restriction_placed`-rad hade påstått
            // motsatsen.
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $restrict->handle(
                request: $request,
                scope: $scope,
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line("Begränsning: {$request->restriction_scope}");
        $this->line('Satt: '.$request->restricted_at->format('Y-m-d'));

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
