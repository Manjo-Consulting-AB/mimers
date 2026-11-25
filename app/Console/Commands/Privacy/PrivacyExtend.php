<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\ExtendPrivacyRequestDeadline;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:extend` — förläng fristen med en eller två månader. Se
 * [[ADR-0095 Integritetsbegäranden]] § 1 och § 5.
 *
 * Kommandot körs för hand över SSH av den som sköter ärendena, och
 * schemaläggs aldrig: åtkomsten till ärendena är åtkomsten till servern
 * (ADR-0095 § 1). Handläggarens namn ur `--by` och skälet ur `--reason` är
 * obligatoriska — händelsen är beviset, och en förlängning utan skäl går inte
 * att förklara i efterhand.
 *
 * **Utskriften påminner om beskedet.** En förlängning ska meddelas den
 * registrerade, och det beskedet antecknas med `privacy:communicate`
 * (issue 484). Kommandot gör det inte åt handläggaren: det skriver bara om
 * att det ska göras.
 *
 * Reglerna — steg om 1 eller 2, högst 2 sammanlagt, bara före den
 * ursprungliga fristen — bor i App\Actions\Privacy\ExtendPrivacyRequestDeadline,
 * som prövar dem innan något skrivs.
 */
class PrivacyExtend extends Command
{
    protected $signature = 'privacy:extend
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {months : Antalet månader, 1 eller 2}
                            {--reason= : Skälet till förlängningen}
                            {--by= : Handläggarens namn}';

    protected $description = 'Förläng fristen för ett integritetsärende.';

    public function handle(ExtendPrivacyRequestDeadline $extend): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $reason = $this->text('reason');

        if ($reason === null) {
            $this->error('--reason måste anges: skälet till förlängningen.');

            return self::FAILURE;
        }

        $months = (string) $this->argument('months');

        if (! ctype_digit($months)) {
            $this->error('Månaderna måste anges som 1 eller 2.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            // Ingen händelse skrivs: ett okänt ärende finns inte att
            // förlänga, och en `deadline_changed`-rad hade påstått motsatsen.
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        try {
            $extend->handle(
                request: $request,
                months: (int) $months,
                reason: $reason,
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line('Frist: '.$request->deadline_at->format('Y-m-d'));
        $this->line("Förlängt: {$request->extended_months} månader");

        // En förlängning är inget besked. Den registrerade ska ha det, och
        // handläggaren antecknar det med privacy:communicate (issue 484).
        $this->warn(
            'Kom ihåg: beskedet om förlängningen ska skickas till den registrerade '
            .'och antecknas med privacy:communicate.'
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
