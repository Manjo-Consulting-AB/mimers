<?php

namespace App\Actions\Access;

use App\Models\Item;
use App\Models\User;
use App\Support\Agent\ItemAudience;
use Illuminate\Database\Eloquent\Collection;

/**
 * Vilka PERSONER en flytt ger åtkomst till itemet, se [[ADR-0069 Flytta ett
 * item inom containern]] § Uppföljning 2026-11-18 och [[ADR-0028 Åtkomst på
 * itemnivå]] regel 1.
 *
 * Frågan är skillnaden mellan två mängder: de som når itemet efter flytten
 * men inte före. En flytt in under ett delat item ger förälderns mottagare
 * åtkomsten; en flytt därifrån tar bort den men ger ingen ny.
 *
 * **Ingen andra vandring.** "Vem når det här itemet" formulerades redan i
 * App\Support\Agent\ItemAudience (issue 1391 · [[ADR-0087 MCP-server för
 * agenter]] Riktning 10), som flytt- och kopieringsspärren använder, och
 * den här actionen är ett tunt lager ovanpå den i stället för en egen
 * slutning. Den använder samma giltighetsvillkor (ContainerAccess::valid())
 * och samma `parent`-kanter som App\Actions\Access\ResolveItemScope, och
 * `afterMoving()` bygger den hypotetiska grafen åt oss: dagens kanter med
 * `fromParent → item` utbytt mot `toParent → item`. #1391:s behov ligger
 * kvar oförändrat; det här är samma svar med personer i stället för id:n.
 *
 * Reglerna som följer av det:
 *
 * - Ägarkontots medlemmar når allt både före och efter och faller därför
 *   bort i skillnaden — de får aldrig åtkomst genom en flytt.
 * - En återkallad eller utgången åtkomst räknas inte; `valid()` filtrerar.
 * - Utestående inbjudningar räknas inte, för de är inga personer än.
 * - Mängder jämförs, inte antal: en flytt som ger färre läsare men en ny
 *   har en gainer.
 *
 * Anroparen bestämmer vad som får visas. Den som ser containerns åtkomstlista
 * (ContainerPolicy::viewAccesses) får namnen; alla andra får bara veta att
 * frågan är sann.
 */
class ResolveMoveAudience
{
    public function __construct(private readonly ItemAudience $audience) {}

    /**
     * Personerna som når itemet efter flytten men inte före.
     *
     * @param  ?Item  $fromParent  placeringen som lämnas, eller null när itemet har exakt en förälder (då härleds den)
     * @param  ?Item  $toParent  den nya placeringen, eller null för toppnivån
     * @return Collection<int, User>
     */
    public function gainers(Item $item, ?Item $fromParent, ?Item $toParent): Collection
    {
        $container = $item->container;

        $before = $this->audience->forItem($container, $item);
        $after = $this->audience->afterMoving($container, $item, $fromParent, $toParent);

        $ids = array_values(array_diff($after, $before));

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }
}
