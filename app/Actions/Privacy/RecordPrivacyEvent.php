<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use InvalidArgumentException;

/**
 * Den enda vägen IN i privacy_request_event — se [[ADR-0095
 * Integritetsbegäranden]] § 3 och [[Konton och åtkomst]]
 * § privacy_request_event. Varje händelse på ett ärende skrivs här:
 * `created` från OpenPrivacyRequest, `viewed` från ShowPrivacyRequest, och
 * senare issuers statusbyten, verifieringar och bedömningar.
 *
 * **Ingen egen transaktion**, av samma skäl som
 * App\Actions\Security\RecordSecurityEvent: en nästlad transaktion ser ut att
 * skydda något den inte skyddar. Anroparen äger transaktionen — och
 * OpenPrivacyRequest skriver ärendet och sin `created`-händelse i en.
 *
 * Slaget prövas mot PrivacyRequestEvent::EVENT_KINDS även om kolumnen har ett
 * CHECK-villkor: villkoret läggs bara på mysql, och en felstavad sträng ska
 * bli ett fel i testsviten i stället för en tyst rad.
 */
class RecordPrivacyEvent
{
    /**
     * Skriv en händelse. `$actor` är handläggarens namn och `$data` är det
     * som hör till slaget — aldrig en kopia av ärendets fritext.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(
        PrivacyRequest $request,
        string $kind,
        string $actor,
        ?string $body = null,
        array $data = [],
    ): PrivacyRequestEvent {
        if (! in_array($kind, PrivacyRequestEvent::EVENT_KINDS, true)) {
            throw new InvalidArgumentException("Okänd händelsetyp: {$kind}.");
        }

        $event = new PrivacyRequestEvent;
        $event->privacy_request_id = $request->getKey();
        $event->kind = $kind;
        $event->actor = $actor;
        $event->body = $body;
        $event->data = $data;
        $event->save();

        return $event;
    }
}
