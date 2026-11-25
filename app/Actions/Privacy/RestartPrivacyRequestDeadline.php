<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Räknar om fristen från en ny dag — se [[ADR-0095 Integritetsbegäranden]]
 * § 5 och [[Konton och åtkomst]] § privacy_request.
 *
 * Det sker bara när handläggaren bedömt att klockan börjat om, till exempel
 * den dag kompletterande uppgifter för identiteten kom in. Den nya fristen
 * är dagen plus `deadline_months` plus den förlängning som redan beviljats
 * (`extended_months`), inte den gamla fristen plus något: det är en
 * omräkning, inte en förlängning.
 *
 * **En dag före `received_at` eller i framtiden vägras.** Fristen kan inte
 * ha börjat före begäran kom in, och en dag som inte varit kan inte ha satt
 * igång något.
 *
 * Händelsen `deadline_changed` bär `kind: restarted`, fristen före och efter,
 * dagen omräkningen skedde från och skälet; skälet står också som `body`.
 * Ärendet och händelsen skrivs i EN transaktion.
 */
class RestartPrivacyRequestDeadline
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
     * @param  Carbon  $from  Dagen fristen räknas om från, en kalenderdag.
     * @param  string|null  $reason  Skälet ur `--reason`; obligatoriskt.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när skälet saknas, dagen ligger före
     *                                  mottagandet eller i framtiden;
     *                                  ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        Carbon $from,
        ?string $reason,
        string $actor,
    ): PrivacyRequest {
        $reason = $this->text($reason);
        $from = $from->copy()->startOfDay();

        if ($reason === null) {
            throw new InvalidArgumentException('--reason krävs: skälet till att fristen räknas om.');
        }

        if ($from->isBefore($request->received_at->copy()->startOfDay())) {
            throw new InvalidArgumentException('Fristen kan inte räknas från en dag före mottagandet.');
        }

        if ($from->isAfter(today())) {
            throw new InvalidArgumentException('Fristen kan inte räknas från en dag i framtiden.');
        }

        return DB::transaction(function () use (
            $request,
            $from,
            $reason,
            $actor,
        ): PrivacyRequest {
            $before = $request->deadline_at->copy();

            $request->deadline_at = $from->copy()->addMonthsNoOverflow(
                (int) config('privacy.deadline_months') + $request->extended_months
            );
            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $reason,
                data: [
                    'kind' => 'restarted',
                    'from' => $before->format('Y-m-d'),
                    'to' => $request->deadline_at->format('Y-m-d'),
                    'from_date' => $from->format('Y-m-d'),
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
