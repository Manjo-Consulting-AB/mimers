<?php

namespace App\Actions\Container;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use App\Support\Transaction\Currencies;
use Illuminate\Support\Facades\DB;

/**
 * Ersätter containerns urval av valutor för kostnader — issue 1254 ·
 * [[ADR-0078 Valutor ur ISO 4217]] § 4.
 *
 * **Hela urvalet byts i EN transaktion.** Raderna är en inställning och
 * inte innehåll: att lägga till en valuta är att skriva den nya listan, inte
 * att lägga en rad ovanpå den gamla. En halv skrivning hade lämnat ett
 * urval användaren aldrig valde.
 *
 * **En tom lista är ett giltigt svar och betyder alla valutor** — inte
 * "inga" (ADR-0078 § 4). Standardvalutan behöver inte stå i listan; den är
 * alltid tillåten genom App\Models\Container::permitsCurrency().
 *
 * **Koderna prövas mot App\Support\Transaction\Currencies::codes() även här**, och
 * inte bara i requesten. Actionen är det kontrakt testsviten och varje
 * framtida anropare möter, och en kod utanför ISO 4217 ska aldrig kunna
 * lagras — samma försvar på två nivåer som App\Rules\IsoCurrency och
 * valideringen i requesten. Felet är `currency.invalid`, samma kod som
 * regeln ger på `/api`.
 *
 * **Händelseloggen får en rad först när listan faktiskt ändras**, i samma
 * form som andra inställningsändringar (App\Actions\Container\UpdateContainer):
 * `meta.changed` bär fältets namn och `meta.values` gamla och nya listan.
 * En PUT med samma lista som förut sparar ingenting och loggar ingenting.
 *
 * `$actor` är den som ändrade inställningen och blir `user_id` på loggraden.
 * Signaturen är `handle(Container $container, array $codes, ?User $actor)`
 * — actionen kan därför också anropas av ett jobb utan användare, och
 * `user_id` blir då null precis som för `container.purged`.
 */
class SetAllowedCurrencies
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  list<string>  $codes  De valutor ägaren har markerat. Tom lista
     *                               betyder alla.
     */
    public function handle(Container $container, array $codes, ?User $actor = null): Container
    {
        $nya = $this->normalise($codes);

        DB::transaction(function () use ($container, $nya, $actor): void {
            // Radlåset serialiserar två samtidiga PUT:ar på samma container,
            // samma mönster som App\Actions\Container\SetContainerCover. Utan
            // det kunde två anrop läsa samma gamla lista, och den ena
            // skrivningens rader blandas med den andras — urvalet blir då
            // något ingen ägare valde. Läsningen av den gamla listan sker
            // innanför låset av samma skäl.
            Container::query()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            $gamla = $container->allowedCurrencies()->pluck('currency')->sort()->values()->all();

            $container->allowedCurrencies()->delete();

            foreach ($nya as $code) {
                $container->allowedCurrencies()->create(['currency' => $code]);
            }

            if ($nya === $gamla) {
                return;
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_CONTAINER_CURRENCIES_UPDATED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'container',
                subjectUlid: $container->ulid,
                meta: [
                    'changed' => ['allowed_currencies'],
                    'values' => ['allowed_currencies' => ['from' => $gamla, 'to' => $nya]],
                ],
            );
        });

        return $container->load('allowedCurrencies');
    }

    /**
     * Versaler, utan dubbletter, sorterade — och bara aktiva ISO 4217-koder.
     *
     * Sorteringen gör jämförelsen med den gamla listan stabil: `["SEK","EUR"]`
     * och `["EUR","SEK"]` är samma urval, och en PUT som bara byter ordning
     * ska inte skriva en loggrad.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function normalise(array $codes): array
    {
        $giltiga = Currencies::codes();

        $nya = [];

        foreach ($codes as $code) {
            $code = mb_strtoupper(trim($code));

            if (! in_array($code, $giltiga, true)) {
                throw ApiException::make('currency.invalid', ['currency' => $code]);
            }

            $nya[$code] = $code;
        }

        sort($nya);

        return $nya;
    }
}
