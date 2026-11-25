<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Antecknar hur identiteten eller ett ombuds behörighet prövades — se
 * [[ADR-0095 Integritetsbegäranden]] § 2 och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * **Händelsen säger vad som prövades, inte vad som visades.** `subject` är
 * `identity` eller `authority`, `method` är en av metoderna i
 * config/privacy.php (`account_email`, `signed_in_session`,
 * `additional_information`, `power_of_attorney`, `other`) och `result` är
 * `verified`, `insufficient` eller `failed`. Ingen kopia av en
 * identitetshandling sparas, varken som text eller som bilaga: `--note` är
 * handläggarens egen anteckning om prövningen, aldrig en avskrift av
 * handlingen.
 *
 * Verifieringen ändrar inte ärendets status. Att gå vidare till
 * `under_assessment` eller `in_progress` är ett eget byte med
 * ChangePrivacyRequestStatus, så att varje steg i handläggningen står som
 * sin egen händelse.
 */
class RecordPrivacyVerification
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som är utanför den här issuen.
     */
    private const KIND = 'verification';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $subject  `identity` eller `authority`.
     * @param  string  $method  En av metoderna i config('privacy.verification_methods').
     * @param  string  $result  Ett av resultaten i config('privacy.verification_results').
     * @param  string|null  $note  Handläggarens anteckning; valfri fritext.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när ämnet, metoden eller resultatet
     *                                  är okänt; ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        string $subject,
        string $method,
        string $result,
        ?string $note,
        string $actor,
    ): PrivacyRequestEvent {
        if (! in_array($subject, (array) config('privacy.verification_subjects'), true)) {
            throw new InvalidArgumentException("Okänt ämne: {$subject}.");
        }

        if (! in_array($method, (array) config('privacy.verification_methods'), true)) {
            throw new InvalidArgumentException("Okänd verifieringsmetod: {$method}.");
        }

        if (! in_array($result, (array) config('privacy.verification_results'), true)) {
            throw new InvalidArgumentException("Okänt verifieringsresultat: {$result}.");
        }

        return DB::transaction(fn (): PrivacyRequestEvent => $this->recordEvent->handle(
            request: $request,
            kind: self::KIND,
            actor: $actor,
            body: $this->text($note),
            data: [
                'subject' => $subject,
                'method' => $method,
                'result' => $result,
            ],
        ));
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
