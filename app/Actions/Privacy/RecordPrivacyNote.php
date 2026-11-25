<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Skriver en fri anteckning på ärendet — se [[ADR-0095
 * Integritetsbegäranden]] § 7 och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * Anteckningen är den enda händelsen utan värden ur en lista: allt som inte
 * hör till en bedömning, en åtgärd eller en kommunikation hamnar här, i
 * `body`. `data` är tom, så att en sökning på katalogens värden aldrig får
 * träff på fritext.
 *
 * Anteckningen ändrar inte ärendets status: den är en minnesanteckning, inte
 * ett steg i handläggningen.
 */
class RecordPrivacyNote
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar värdet mot.
     */
    private const KIND = 'note';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $text  Anteckningen, fritext.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när texten är tom; ingenting har då
     *                                  skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        string $text,
        string $actor,
    ): PrivacyRequestEvent {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Anteckningen får inte vara tom.');
        }

        return DB::transaction(fn (): PrivacyRequestEvent => $this->recordEvent->handle(
            request: $request,
            kind: self::KIND,
            actor: $actor,
            body: $text,
        ));
    }
}
