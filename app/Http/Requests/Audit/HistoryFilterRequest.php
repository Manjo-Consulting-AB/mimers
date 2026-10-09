<?php

namespace App\Http\Requests\Audit;

/**
 * Validerar den GLOBALA historikens filterfält, issue 271. Filtren står i
 * querysträngen på `GET /history`: förälderns `type`, `user`, `item`, `from`
 * och `to`, plus `container`.
 *
 * **Ärver formen och lägger till ett fält.** App\Http\Requests\Audit\
 * ContainerHistoryFilterRequest prövar de fem gemensamma fälten — formfel i
 * stället för felkoder, ingen existensprövning, `to` efter `from` — och den
 * regeln är oförändrad. Att skriva om den här hade varit en andra formulering
 * av samma kontrakt, och de två hade glidit isär (ADR-0024).
 *
 * **`container` tar ett ULID eller ordet `account`.** Ett ULID är en
 * container; `account` är kontohändelserna utan container — led 3 i
 * App\Actions\Audit\ListAuditEvents, de rader som hör till kontot och inte
 * till någon container (`account.deleted`). Sidan ritar det valet själv
 * (`Account activity`), och det står därför inte bland `options.containers`.
 *
 * Mönstret upprepar förälderns ULID-form (tjugosex tecken Crockford base32,
 * versaler — samma form som App\Models\Concerns\HasUlid skriver) i stället för
 * att låna konstanten: den är privat i föräldern, och föräldern står utanför
 * den här issuen ruta. Ändras ULID-formen ändras den på båda ställena.
 *
 * **Existensen prövas inte**, precis som för `user` och `item` (Beslut 2 och
 * 3): en container användaren inte når ska ge en TOM lista och inte ett
 * avvisat filter, och svaret får inte skilja en container som finns från en
 * som inte gör det. Uppslaget och läsregeln bor i ListAuditEvents.
 */
class HistoryFilterRequest extends ContainerHistoryFilterRequest
{
    /**
     * ULID eller det bokstavliga ordet `account`.
     */
    private const CONTAINER = '/^(account|[0-9A-HJKMNP-TV-Z]{26})$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'container' => ['nullable', 'string', 'regex:'.self::CONTAINER],
        ];
    }
}
