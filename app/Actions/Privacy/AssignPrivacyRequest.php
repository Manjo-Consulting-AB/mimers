<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tilldelar ett ärende en handläggare och sätter dess prioritet — se
 * [[ADR-0095 Integritetsbegäranden]] § 1 och § 4 samt [[Konton och åtkomst]]
 * § privacy_request.
 *
 * Handläggaren är ett namn, inte en användare: kommandona körs från servern
 * och ärendet har ingen personalroll (ADR-0095 § 1). Prioriteten är `low`,
 * `normal` eller `high` ur config/privacy.php.
 *
 * **Bara det som anges ändras.** Ett tomt handläggarnamn lämnar den
 * befintliga handläggaren orörd, så att `privacy:assign` med bara
 * `--priority` kan höja prioriteten utan att fördela om ärendet. Anges
 * varken handläggare eller prioritet finns ingenting att göra, och det är
 * ett fel — ett kommando som tyst inte gör något påstår ändå en händelse.
 *
 * Händelsen `assigned` bär BÅDA värdena före och efter, också det som inte
 * ändrades: den som läser historiken ska slippa pussla ihop tillståndet ur
 * flera rader. Ärendet och händelsen skrivs i EN transaktion.
 */
class AssignPrivacyRequest
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som är utanför den här issuen.
     */
    private const KIND = 'assigned';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string|null  $assignee  Handläggarens namn; null eller tomt
     *                                 lämnar den befintliga orörd.
     * @param  string|null  $priority  En av prioriteterna i
     *                                 config('privacy.priorities').
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när prioriteten är okänd eller när
     *                                  varken handläggare eller prioritet
     *                                  angetts; ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        ?string $assignee,
        ?string $priority,
        string $actor,
    ): PrivacyRequest {
        $assignee = $this->text($assignee);
        $priority = $this->text($priority);

        if ($priority !== null && ! in_array($priority, (array) config('privacy.priorities'), true)) {
            throw new InvalidArgumentException("Okänd prioritet: {$priority}.");
        }

        if ($assignee === null && $priority === null) {
            throw new InvalidArgumentException('Ange en handläggare eller en prioritet.');
        }

        return DB::transaction(function () use ($request, $assignee, $priority, $actor): PrivacyRequest {
            $before = [
                'assignee' => $request->assignee,
                'priority' => $request->priority,
            ];

            if ($assignee !== null) {
                $request->assignee = $assignee;
            }

            if ($priority !== null) {
                $request->priority = $priority;
            }

            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                data: [
                    'assignee' => [
                        'from' => $before['assignee'],
                        'to' => $request->assignee,
                    ],
                    'priority' => [
                        'from' => $before['priority'],
                        'to' => $request->priority,
                    ],
                ],
            );

            return $request;
        });
    }

    /**
     * Ett värde som text, eller null när det inte angetts. Ett tomt
     * `--priority=` är ingen prioritet.
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
