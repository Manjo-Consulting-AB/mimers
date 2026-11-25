<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Antecknar kommunikationen med den registrerade — se [[ADR-0095
 * Integritetsbegäranden]] § 7 och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * Slaget, riktningen och kanalen är det som går att söka på: slaget kommer ur
 * config('privacy.communication_kinds'), kanalen ur config('privacy.channels')
 * och riktningen är `in` eller `out`. Vad som sades står i `--note`, som
 * sparas i händelsens `body`.
 *
 * Kommunikationen ändrar inte ärendets status: ett besked är ett besked,
 * oavsett om det är det sista.
 */
class RecordPrivacyCommunication
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar värdet mot.
     */
    private const KIND = 'communication';

    /**
     * Riktningarna. De finns inte i config/privacy.php, för de hör till
     * händelsen och inte till katalogen över uppgifterna.
     */
    private const DIRECTIONS = ['in', 'out'];

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $kind  Ett av slagen i config('privacy.communication_kinds').
     * @param  string  $direction  `in` eller `out`.
     * @param  string  $channel  En av kanalerna i config('privacy.channels').
     * @param  string|null  $note  Handläggarens anteckning, valfri fritext.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när slaget, riktningen eller kanalen
     *                                  är okänd; ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        string $kind,
        string $direction,
        string $channel,
        ?string $note,
        string $actor,
    ): PrivacyRequestEvent {
        if (! in_array($kind, (array) config('privacy.communication_kinds'), true)) {
            throw new InvalidArgumentException("Okänt kommunikationsslag: {$kind}.");
        }

        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException("Okänd riktning: {$direction}.");
        }

        if (! in_array($channel, (array) config('privacy.channels'), true)) {
            throw new InvalidArgumentException("Okänd kanal: {$channel}.");
        }

        $note = $this->text($note);

        return DB::transaction(fn (): PrivacyRequestEvent => $this->recordEvent->handle(
            request: $request,
            kind: self::KIND,
            actor: $actor,
            body: $note,
            data: [
                'kind' => $kind,
                'direction' => $direction,
                'channel' => $channel,
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
