<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sätter en begränsning på ett ärende — se [[ADR-0095 Integritetsbegäranden]]
 * § 8 och [[Konton och åtkomst]] § privacy_request.
 *
 * **Begränsningen är en markering, inte ett skrivskydd.** Den säger till
 * personalen vilka uppgifter som inte får användas och för vilka ändamål,
 * medan den gäller; den ändrar inget i appen, stoppar ingen inloggning och
 * läses inte av någon kod. Den rättsliga spärren är en annan sak och rörs
 * inte här.
 *
 * `--scope` är obligatoriskt: en begränsning utan omfattning är ingen
 * instruktion. En gällande begränsning vägras — den ska hävas först, med
 * LiftPrivacyRestriction, så att historiken får ett slut och ett nytt
 * `restriction_placed` får en egen början.
 *
 * Ärendet och händelsen `restriction_placed` skrivs i EN transaktion. Att
 * sätta en ny begränsning nollställer `restriction_lifted_at`, så att raden
 * beskriver den begränsning som gäller nu.
 */
class PlacePrivacyRestriction
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar mot EVENT_KINDS.
     */
    private const KIND = 'restriction_placed';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string|null  $scope  Vilka uppgifter och vilka ändamål
     *                              begränsningen gäller; obligatoriskt.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när omfattningen saknas eller en
     *                                  gällande begränsning redan finns;
     *                                  ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        ?string $scope,
        string $actor,
    ): PrivacyRequest {
        $scope = $this->text($scope);

        if ($scope === null) {
            throw new InvalidArgumentException(
                '--scope krävs: vilka uppgifter och vilka ändamål begränsningen gäller.'
            );
        }

        if ($request->hasActiveRestriction()) {
            throw new InvalidArgumentException(
                'Ärendet har redan en gällande begränsning. Häv den först med privacy:unrestrict.'
            );
        }

        return DB::transaction(function () use ($request, $scope, $actor): PrivacyRequest {
            $request->restriction_scope = $scope;
            $request->restricted_at = now();
            $request->restriction_lifted_at = null;
            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                data: ['scope' => $scope],
            );

            return $request;
        });
    }

    /**
     * En omfattning som text, eller null när den inte angetts. Ett
     * `--scope=` med bara blanksteg är ingen omfattning.
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
