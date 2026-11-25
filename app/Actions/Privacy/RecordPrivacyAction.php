<?php

namespace App\Actions\Privacy;

use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Antecknar vad som gjordes, i vilket system — se [[ADR-0095
 * Integritetsbegäranden]] § 7 och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * **Systemet, operationen, kategorierna, skälet att behålla och funktionen
 * kommer ur listorna i config/privacy.php.** Fritexten står bara i `--note`,
 * som sparas i händelsens `body`, så att en sökning på `data` går att lita
 * på.
 *
 * Två krav hänger ihop med operationen:
 *
 * - `retained` kräver ett skäl att behålla, och `self_service` kräver en
 *   funktion. Ett behållande utan skäl och en hänvisning utan funktion är
 *   båda oanvändbara som bevis.
 * - `legal_hold` som skäl godtas bara när ärendets konto faktiskt omfattas av
 *   en gällande spärr. Spärren hindrar gallring, inte den registrerades
 *   åtkomst ([[ADR-0043 Tre loggar]] § Den rättsliga spärren), och ett ärende
 *   utan konto kan inte bära skälet: det finns ingen spärr att luta sig mot.
 *
 * `referred_to_controller` används när Mimers är biträde och begäran gäller
 * kundens innehåll (ADR-0095 § 7). Åtgärden ändrar inte ärendets status.
 */
class RecordPrivacyAction
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar värdet mot.
     */
    private const KIND = 'action';

    /**
     * Skälet som bara får anges när kontot omfattas av en spärr.
     */
    private const REASON_LEGAL_HOLD = 'legal_hold';

    /**
     * Operationen som kräver ett skäl att behålla.
     */
    private const OPERATION_RETAINED = 'retained';

    /**
     * Operationen som kräver en funktion.
     */
    private const OPERATION_SELF_SERVICE = 'self_service';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $system  Ett av systemen i config('privacy.systems').
     * @param  string  $operation  En av operationerna i config('privacy.operations').
     * @param  list<string>  $categories  Kategorier ur
     *                                    config('privacy.data_categories');
     *                                    tom listan när ingen anges.
     * @param  string|null  $retentionReason  Ett av skälen i
     *                                        config('privacy.retention_reasons').
     * @param  string|null  $feature  En av funktionerna i
     *                                config('privacy.self_service_features').
     * @param  string|null  $note  Handläggarens anteckning, valfri fritext.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när ett värde är okänt, ett krav som
     *                                  hör till operationen saknas eller
     *                                  `legal_hold` anges utan en gällande
     *                                  spärr; ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        string $system,
        string $operation,
        array $categories,
        ?string $retentionReason,
        ?string $feature,
        ?string $note,
        string $actor,
    ): PrivacyRequestEvent {
        if (! in_array($system, (array) config('privacy.systems'), true)) {
            throw new InvalidArgumentException("Okänt system: {$system}.");
        }

        if (! in_array($operation, (array) config('privacy.operations'), true)) {
            throw new InvalidArgumentException("Okänd operation: {$operation}.");
        }

        foreach ($categories as $category) {
            if (! in_array($category, (array) config('privacy.data_categories'), true)) {
                throw new InvalidArgumentException("Okänd datakategori: {$category}.");
            }
        }

        if (
            $retentionReason !== null
            && ! in_array($retentionReason, (array) config('privacy.retention_reasons'), true)
        ) {
            throw new InvalidArgumentException("Okänt skäl att behålla: {$retentionReason}.");
        }

        if (
            $feature !== null
            && ! in_array($feature, (array) config('privacy.self_service_features'), true)
        ) {
            throw new InvalidArgumentException("Okänd självbetjäningsfunktion: {$feature}.");
        }

        if ($operation === self::OPERATION_RETAINED && $retentionReason === null) {
            throw new InvalidArgumentException('--retention-reason krävs när operation är retained.');
        }

        if ($operation === self::OPERATION_SELF_SERVICE && $feature === null) {
            throw new InvalidArgumentException('--feature krävs när operation är self_service.');
        }

        if ($retentionReason === self::REASON_LEGAL_HOLD) {
            $account = $request->account;

            if ($account === null || ! LegalHold::covers($account)) {
                throw new InvalidArgumentException(
                    '--retention-reason=legal_hold kräver att ärendets konto omfattas av en gällande rättslig spärr.'
                );
            }
        }

        $note = $this->text($note);

        return DB::transaction(function () use (
            $request,
            $system,
            $operation,
            $categories,
            $retentionReason,
            $feature,
            $note,
            $actor,
        ): PrivacyRequestEvent {
            $data = [
                'system' => $system,
                'operation' => $operation,
            ];

            if ($categories !== []) {
                $data['categories'] = $categories;
            }

            if ($retentionReason !== null) {
                $data['retention_reason'] = $retentionReason;
            }

            if ($feature !== null) {
                $data['feature'] = $feature;
            }

            return $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $note,
                data: $data,
            );
        });
    }

    /**
     * En anteckning som text, eller null när den inte angetts. En `--note=`
     * med bara blanksteg är ingen anteckning.
     */
    private function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
