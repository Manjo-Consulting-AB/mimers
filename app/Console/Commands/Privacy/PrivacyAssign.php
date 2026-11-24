<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\AssignPrivacyRequest;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:assign` — fördela ett ärende och sätta dess prioritet. Se
 * [[ADR-0095 Integritetsbegäranden]] § 1 och § 4.
 *
 * Handläggaren anges som ett namn, för ärendet har ingen personalroll och
 * kommandot körs utan inloggad användare (ADR-0095 § 1). Ett tomt
 * handläggarnamn lämnar den befintliga orörd, så att prioriteten kan ändras
 * för sig:
 *
 *     php artisan privacy:assign PR-XXXXXXXX "" --priority=high --by="Anna"
 *
 * Logiken bor i App\Actions\Privacy\AssignPrivacyRequest, som skriver
 * händelsen `assigned` med båda värdena före och efter.
 */
class PrivacyAssign extends Command
{
    protected $signature = 'privacy:assign
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {assignee : Handläggarens namn; tom sträng ändrar bara prioriteten}
                            {--priority= : low, normal eller high}
                            {--by= : Handläggarens namn}';

    protected $description = 'Fördela ett integritetsärende och sätt dess prioritet.';

    public function handle(AssignPrivacyRequest $assign): int
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
            $assign->handle(
                request: $request,
                assignee: (string) $this->argument('assignee'),
                priority: $this->text('priority'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line('Handläggare: '.($request->assignee ?? '—'));
        $this->line("Prioritet: {$request->priority}");

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
