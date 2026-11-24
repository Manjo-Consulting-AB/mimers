<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Database\Eloquent\Collection;

/**
 * Läser ett ärende och skriver att det lästes — se [[ADR-0095
 * Integritetsbegäranden]] § 3.
 *
 * **En läsning ska gå att följa.** Därför skriver `privacy:show` en händelse
 * `viewed` med handläggarens namn innan ärendet lämnas ut. Händelsen skrivs
 * först, så att listan som returneras är ärendets händelser i tidsordning
 * inklusive den läsning som just gjordes.
 *
 * Tidsordningen sorteras på `created_at` och sedan `id`: tidsstämpeln har
 * sekundprecision, och två händelser skrivna i samma sekund ska ändå komma i
 * den ordning de skrevs.
 */
class ShowPrivacyRequest
{
    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @return array{request: PrivacyRequest, events: Collection<int, PrivacyRequestEvent>}
     */
    public function handle(PrivacyRequest $request, string $actor): array
    {
        $this->recordEvent->handle(
            request: $request,
            kind: PrivacyRequestEvent::KIND_VIEWED,
            actor: $actor,
        );

        return [
            'request' => $request,
            'events' => $request->events()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        ];
    }
}
