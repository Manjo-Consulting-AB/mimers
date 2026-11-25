<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\RecordPrivacyAssessment;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:assess` — anteckna en bedömning och sätt ärendets roll. Se
 * [[ADR-0095 Integritetsbegäranden]] § 7.
 *
 * Rollen, behandlingen, grunden, beslutet och motiveringen krävs.
 * `--data-set` är valfri, och ett ärende kan ha flera bedömningar med olika
 * datamängd: det är så delvis bifall dokumenteras (ADR-0095 § 7).
 *
 * Logiken bor i App\Actions\Privacy\RecordPrivacyAssessment.
 */
class PrivacyAssess extends Command
{
    protected $signature = 'privacy:assess
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--role= : controller, processor eller both}
                            {--activity= : Behandlingen bedömningen gäller}
                            {--basis= : En av de rättsliga grunderna i config/privacy.php}
                            {--decision= : grant, partial eller refuse}
                            {--rationale= : Motiveringen till beslutet}
                            {--data-set= : Datamängden, när bedömningen gäller en av flera}
                            {--by= : Handläggarens namn}';

    protected $description = 'Anteckna en bedömning av ett integritetsärende.';

    public function handle(RecordPrivacyAssessment $assess): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $role = $this->text('role');
        $activity = $this->text('activity');
        $basis = $this->text('basis');
        $decision = $this->text('decision');
        $rationale = $this->text('rationale');

        if (
            $role === null
            || $activity === null
            || $basis === null
            || $decision === null
            || $rationale === null
        ) {
            $this->error('--role, --activity, --basis, --decision och --rationale måste anges.');

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
            $assess->handle(
                request: $request,
                role: $role,
                activity: $activity,
                basis: $basis,
                decision: $decision,
                rationale: $rationale,
                dataSet: $this->text('data-set'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line("Roll: {$request->role}");
        $this->line("Beslut: {$decision}");

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
