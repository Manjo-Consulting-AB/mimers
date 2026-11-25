<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\RestartPrivacyRequestDeadline;
use App\Models\PrivacyRequest;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * `privacy:deadline` — räkna om fristen från en ny dag. Se [[ADR-0095
 * Integritetsbegäranden]] § 1 och § 5.
 *
 * Det sker bara när handläggaren bedömt att klockan börjat om, till exempel
 * den dag kompletterande uppgifter för identiteten kom in. Den nya fristen
 * är `--from` plus `deadline_months` plus `extended_months`; det är en
 * omräkning, inte en förlängning.
 *
 * Kommandot körs för hand över SSH och schemaläggs aldrig (ADR-0095 § 1).
 * Handläggarens namn ur `--by` och skälet ur `--reason` är obligatoriska.
 *
 * Reglerna — en dag före mottagandet eller i framtiden vägras — bor i
 * App\Actions\Privacy\RestartPrivacyRequestDeadline.
 */
class PrivacyDeadline extends Command
{
    protected $signature = 'privacy:deadline
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--from= : Den nya dagen i formen Y-m-d}
                            {--reason= : Skälet till att fristen räknas om}
                            {--by= : Handläggarens namn}';

    protected $description = 'Räkna om fristen för ett integritetsärende från en ny dag.';

    public function handle(RestartPrivacyRequestDeadline $restart): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $reason = $this->text('reason');

        if ($reason === null) {
            $this->error('--reason måste anges: skälet till att fristen räknas om.');

            return self::FAILURE;
        }

        try {
            $from = $this->fromDate();
        } catch (InvalidFormatException) {
            $this->error('--from måste vara ett datum i formen Y-m-d.');

            return self::FAILURE;
        }

        if ($from === null) {
            $this->error('--from måste anges: dagen fristen räknas om från.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            // Ingen händelse skrivs: ett okänt ärende finns inte att räkna
            // om, och en `deadline_changed`-rad hade påstått motsatsen.
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        $old = $request->deadline_at->copy();

        try {
            $restart->handle(
                request: $request,
                from: $from,
                reason: $reason,
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line('Frist: '.$old->format('Y-m-d').' → '.$request->deadline_at->format('Y-m-d'));

        return self::SUCCESS;
    }

    /**
     * `--from` som kalenderdag, eller null när det inte angetts.
     *
     * Formatet prövas genom att tolka och skriva tillbaka, som i
     * PrivacyOpen::receivedAt(): PHP accepterar `2026-02-30` som 2 mars, och
     * en tyst omräkning av dagen hade flyttat fristen utan att någon märkte
     * det.
     *
     * @throws InvalidFormatException
     */
    private function fromDate(): ?Carbon
    {
        $varde = $this->text('from');

        if ($varde === null) {
            return null;
        }

        $datum = Carbon::createFromFormat('Y-m-d', $varde);

        if ($datum->format('Y-m-d') !== $varde) {
            throw new InvalidFormatException("Ogiltigt datum: {$varde}.");
        }

        return $datum;
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
