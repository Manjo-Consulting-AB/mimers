<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Flyttar ett ärende mellan statusarna — se [[ADR-0095
 * Integritetsbegäranden]] § 4 och [[Konton och åtkomst]] § privacy_request.
 *
 * De öppna lägena är `received`, `verification_required`,
 * `under_assessment`, `in_progress` och `awaiting_requester`. De avslutade är
 * `completed`, `partially_completed`, `rejected` och `closed`, där `closed`
 * betyder avslutat utan sakprövning. Uppdelningen bor i config/privacy.php.
 *
 * Reglerna, i den ordning de prövas:
 *
 * - Mellan två öppna lägen går det fritt.
 * - Till ett avslutat läge krävs ett skäl, utom till `completed`. Skälet
 *   sparas i `outcome_reason` och på händelsen, och `closed_at` sätts till nu.
 * - Från ett avslutat läge tillbaka till ett öppet krävs ett skäl.
 *   `closed_at` och `outcome_reason` nollställs, och händelsen bär det gamla
 *   skälet i `data`.
 * - Mellan två avslutade lägen går det inte: ärendet ska öppnas först.
 *
 * Varje byte är en händelse `status_changed` med `from`, `to` och skälet.
 * Ärendet och händelsen skrivs i EN transaktion: en statusändring utan spår
 * av varför vore värre än ingen ändring.
 */
class ChangePrivacyRequestStatus
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som är utanför den här issuen; RecordPrivacyEvent prövar
     * värdet mot PrivacyRequestEvent::EVENT_KINDS.
     */
    private const KIND = 'status_changed';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string|null  $reason  Skälet ur `--reason`; tomt blir null.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när statusen är okänd eller bytet
     *                                  bryter mot reglerna ovan; ingenting
     *                                  har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        string $status,
        ?string $reason,
        string $actor,
    ): PrivacyRequest {
        $reason = $this->text($reason);
        $from = $request->status;

        if (! in_array($status, (array) config('privacy.statuses'), true)) {
            throw new InvalidArgumentException("Okänd status: {$status}.");
        }

        $fromClosed = PrivacyRequest::isClosedStatus($from);
        $toClosed = PrivacyRequest::isClosedStatus($status);

        if ($fromClosed && $toClosed) {
            throw new InvalidArgumentException('Ärendet är redan avslutat. Öppna det först.');
        }

        // `completed` är sitt eget skäl: begäran bifölls, och det finns
        // inget avslag att förklara (ADR-0095 § 4).
        if ($toClosed && $status !== PrivacyRequest::STATUS_COMPLETED && $reason === null) {
            throw new InvalidArgumentException("--reason krävs för att avsluta ärendet som {$status}.");
        }

        if ($fromClosed && $reason === null) {
            throw new InvalidArgumentException('--reason krävs för att öppna ett avslutat ärende igen.');
        }

        return DB::transaction(function () use (
            $request,
            $status,
            $reason,
            $actor,
            $from,
            $fromClosed,
            $toClosed,
        ): PrivacyRequest {
            $data = [
                'from' => $from,
                'to' => $status,
            ];

            if ($reason !== null) {
                $data['reason'] = $reason;
            }

            if ($toClosed) {
                $request->status = $status;
                $request->outcome_reason = $reason;
                $request->closed_at = now();
            } elseif ($fromClosed) {
                // Det gamla skälet försvinner ur ärendet men inte ur
                // historiken: händelsen bär det, så att en återöppning går
                // att följa i efterhand.
                $data['previous_outcome_reason'] = $request->outcome_reason;
                $request->status = $status;
                $request->outcome_reason = null;
                $request->closed_at = null;
            } else {
                $request->status = $status;
            }

            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $reason,
                data: $data,
            );

            return $request;
        });
    }

    /**
     * Ett skäl som text, eller null när det inte angetts. Ett `--reason=`
     * med bara blanksteg är inget skäl.
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
