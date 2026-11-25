<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\RecordPrivacyAction;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `privacy:action` — anteckna vad som gjordes, i vilket system. Se
 * [[ADR-0095 Integritetsbegäranden]] § 7.
 *
 * `--category` får anges flera gånger, en per datakategori åtgärden gällde:
 *
 *     php artisan privacy:action PR-XXXXXXXX --system=database \
 *         --operation=searched --category=profile --category=sessions \
 *         --by="Anna"
 *
 * `retained` kräver `--retention-reason`, `self_service` kräver `--feature`,
 * och `--retention-reason=legal_hold` godtas bara när ärendets konto omfattas
 * av en gällande rättslig spärr ([[ADR-0043 Tre loggar]] § Den rättsliga
 * spärren). Fritexten står bara i `--note`.
 *
 * Logiken bor i App\Actions\Privacy\RecordPrivacyAction.
 */
class PrivacyAction extends Command
{
    protected $signature = 'privacy:action
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--system= : Ett av systemen i config/privacy.php}
                            {--operation= : En av operationerna i config/privacy.php}
                            {--category=* : En datakategori; upprepas för flera}
                            {--retention-reason= : Ett skäl att behålla, krävs för retained}
                            {--feature= : En självbetjäningsfunktion, krävs för self_service}
                            {--note= : Handläggarens anteckning, valfri fritext}
                            {--by= : Handläggarens namn}';

    protected $description = 'Anteckna en åtgärd på ett integritetsärende.';

    public function handle(RecordPrivacyAction $action): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $system = $this->text('system');
        $operation = $this->text('operation');

        if ($system === null || $operation === null) {
            $this->error('--system och --operation måste anges.');

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
            $action->handle(
                request: $request,
                system: $system,
                operation: $operation,
                categories: $this->categories(),
                retentionReason: $this->text('retention-reason'),
                feature: $this->text('feature'),
                note: $this->text('note'),
                actor: $by,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Referens: {$request->reference}");
        $this->line("Åtgärd: {$system} · {$operation}");

        return self::SUCCESS;
    }

    /**
     * De angivna datakategorierna, i den ordning de skrevs. Tomma värden
     * hoppas över: `--category=` är ingen kategori.
     *
     * @return list<string>
     */
    private function categories(): array
    {
        $kategorier = [];

        foreach ((array) $this->option('category') as $varde) {
            if (is_string($varde) && trim($varde) !== '') {
                $kategorier[] = trim($varde);
            }
        }

        return $kategorier;
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
