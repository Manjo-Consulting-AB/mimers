<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Antecknar en bedömning av begäran och sätter ärendets roll — se
 * [[ADR-0095 Integritetsbegäranden]] § 7 och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * **Rollen, behandlingen, grunden och beslutet kommer ur listorna i
 * config/privacy.php**, så att en sökning på ärendena går att lita på.
 * Motiveringen är fritext och sparas i händelsens `body`; datamängden är
 * också fritext och sparas i `data`, för den namnger det begäran gäller och
 * kan inte räknas upp i förväg.
 *
 * **Ett ärende kan ha flera bedömningar**, en per datamängd eller
 * behandling: två anrop med olika `--data-set` ger två händelser, och det är
 * så delvis bifall dokumenteras (ADR-0095 § 7). Rollen på ärendet sätts till
 * den senaste bedömningens — ärendet bär det som gäller, historiken bär
 * varje steg.
 *
 * Bedömningen ändrar inte status. Att gå vidare till `under_assessment`,
 * `in_progress` eller ett avslutat läge är ett eget byte med
 * ChangePrivacyRequestStatus, så att varje steg står som sin egen händelse.
 */
class RecordPrivacyAssessment
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar värdet mot.
     */
    private const KIND = 'assessment';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $role  En av rollerna i config('privacy.roles').
     * @param  string  $activity  Behandlingen bedömningen gäller, fritext.
     * @param  string  $basis  En av grunderna i config('privacy.legal_bases').
     * @param  string  $decision  Ett av besluten i config('privacy.decisions').
     * @param  string  $rationale  Motiveringen, fritext.
     * @param  string|null  $dataSet  Datamängden, när bedömningen gäller en
     *                                av flera; valfri fritext.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när rollen, grunden eller beslutet är
     *                                  okänt eller ett obligatoriskt värde
     *                                  saknas; ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        string $role,
        string $activity,
        string $basis,
        string $decision,
        string $rationale,
        ?string $dataSet,
        string $actor,
    ): PrivacyRequestEvent {
        if (! in_array($role, (array) config('privacy.roles'), true)) {
            throw new InvalidArgumentException("Okänd roll: {$role}.");
        }

        if (! in_array($basis, (array) config('privacy.legal_bases'), true)) {
            throw new InvalidArgumentException("Okänd rättslig grund: {$basis}.");
        }

        if (! in_array($decision, (array) config('privacy.decisions'), true)) {
            throw new InvalidArgumentException("Okänt beslut: {$decision}.");
        }

        $activity = trim($activity);

        if ($activity === '') {
            throw new InvalidArgumentException('--activity måste anges: behandlingen bedömningen gäller.');
        }

        $rationale = trim($rationale);

        if ($rationale === '') {
            throw new InvalidArgumentException('--rationale måste anges: motiveringen till beslutet.');
        }

        $dataSet = $this->text($dataSet);

        return DB::transaction(function () use (
            $request,
            $role,
            $activity,
            $basis,
            $decision,
            $rationale,
            $dataSet,
            $actor,
        ): PrivacyRequestEvent {
            $request->role = $role;
            $request->save();

            $data = [
                'role' => $role,
                'activity' => $activity,
                'basis' => $basis,
                'decision' => $decision,
            ];

            if ($dataSet !== null) {
                $data['data_set'] = $dataSet;
            }

            return $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $rationale,
                data: $data,
            );
        });
    }

    /**
     * En datamängd som text, eller null när den inte angetts. Ett `--data-set=`
     * med bara blanksteg är ingen datamängd.
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
