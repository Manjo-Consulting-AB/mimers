<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Förlänger fristen — se [[ADR-0095 Integritetsbegäranden]] § 5 och
 * [[Konton och åtkomst]] § privacy_request.
 *
 * Art. 12.3 tillåter en förlängning med högst två månader sammanlagt, och
 * `privacy:extend` förlänger i steg om en eller två månader ur
 * config('privacy.extension_months'). Taket är summan i `extended_months`
 * (`max_extension_months`): 1 + 1 går, 2 + 1 går inte.
 *
 * **Förlängningen måste ske före den URSPRUNGLIGA fristen** — mottagandet
 * plus `deadline_months`, utan förlängning. Den registrerade ska ha beskedet
 * inom den första månaden, också när fristen redan förlängts; därför prövas
 * alltid `originalDeadline()` och inte den gällande `deadline_at`. En
 * förlängning på den ursprungliga fristens sista dag går igenom, en efter
 * den gör det inte.
 *
 * **Den nya fristen räknas från den gällande fristen**, inte från
 * mottagandet. Det gör att en förlängning efter en omräkning med
 * `privacy:deadline` läggs ovanpå den dag handläggaren satte, och inte backar
 * tillbaka till mottagningsdagen. För ett ärende som aldrig räknats om är
 * resultatet detsamma som mottagandet plus en månad plus summan.
 *
 * Händelsen `deadline_changed` bär `kind: extended`, fristen före och efter
 * och skälet; skälet står också som `body`. Ärendet och händelsen skrivs i EN
 * transaktion.
 */
class ExtendPrivacyRequestDeadline
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar mot EVENT_KINDS.
     */
    private const KIND = 'deadline_changed';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  int  $months  En av månaderna i config('privacy.extension_months').
     * @param  string|null  $reason  Skälet ur `--reason`; obligatoriskt.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när månaden är okänd, skälet saknas,
     *                                  den ursprungliga fristen passerat
     *                                  eller taket överskrids; ingenting har
     *                                  då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        int $months,
        ?string $reason,
        string $actor,
    ): PrivacyRequest {
        $reason = $this->text($reason);

        if (! in_array($months, (array) config('privacy.extension_months'), true)) {
            throw new InvalidArgumentException('Förlängningen måste vara 1 eller 2 månader.');
        }

        if ($reason === null) {
            throw new InvalidArgumentException('--reason krävs: skälet till förlängningen.');
        }

        // Bara dagen räknas: fristen är en kalenderdag, och att förlänga på
        // dess sista dag är fortfarande inom den första månaden.
        if (today()->isAfter($request->originalDeadline())) {
            throw new InvalidArgumentException(
                'Den ursprungliga fristen har passerat. En förlängning ska ha skett inom den första månaden.'
            );
        }

        $total = $request->extended_months + $months;

        if ($total > (int) config('privacy.max_extension_months')) {
            throw new InvalidArgumentException('Förlängningarna får tillsammans vara högst två månader.');
        }

        return DB::transaction(function () use (
            $request,
            $months,
            $total,
            $reason,
            $actor,
        ): PrivacyRequest {
            $from = $request->deadline_at->copy();

            $request->extended_months = $total;
            $request->deadline_at = $from->copy()->addMonthsNoOverflow($months);
            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $reason,
                data: [
                    'kind' => 'extended',
                    'from' => $from->format('Y-m-d'),
                    'to' => $request->deadline_at->format('Y-m-d'),
                    'months' => $months,
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
