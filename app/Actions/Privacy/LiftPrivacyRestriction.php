<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Häver en gällande begränsning — se [[ADR-0095 Integritetsbegäranden]] § 8
 * och [[Konton och åtkomst]] § privacy_request.
 *
 * **Den hävs med skäl.** `restriction_lifted_at` sätts och `restriction_scope`
 * står kvar: raden ska fortfarande beskriva vilken begränsning som gällde och
 * när den slutade. Händelsen `restriction_lifted` bär skälet.
 *
 * **Art. 18.3 kräver att den registrerade underrättas innan begränsningen
 * hävs.** Actionen gör det inte åt handläggaren — kommandot påminner om att
 * anteckna beskedet med `privacy:communicate`.
 *
 * Ärendet och händelsen skrivs i EN transaktion.
 */
class LiftPrivacyRestriction
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar mot EVENT_KINDS.
     */
    private const KIND = 'restriction_lifted';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string|null  $reason  Skälet ur `--reason`; obligatoriskt.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när skälet saknas eller ingen
     *                                  gällande begränsning finns;
     *                                  ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        ?string $reason,
        string $actor,
    ): PrivacyRequest {
        $reason = $this->text($reason);

        if ($reason === null) {
            throw new InvalidArgumentException(
                '--reason krävs: skälet till att begränsningen hävs.'
            );
        }

        if (! $request->hasActiveRestriction()) {
            throw new InvalidArgumentException(
                'Ärendet har ingen gällande begränsning att häva.'
            );
        }

        return DB::transaction(function () use ($request, $reason, $actor): PrivacyRequest {
            $request->restriction_lifted_at = now();
            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $reason,
                data: [
                    'scope' => $request->restriction_scope,
                    'reason' => $reason,
                ],
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
