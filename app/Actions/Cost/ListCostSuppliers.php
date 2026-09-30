<?php

namespace App\Actions\Cost;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Distinkta leverantörer i en container, sorterade på användningsfrekvens
 * fallande med leverantörsnamn stigande som andrasortering — uppslagsytan för
 * autocomplete (issue 45b), delad av `/api` och webbens kostnadsflik sedan
 * issue 168.
 *
 * **Ytan ligger på CONTAINERN, inte på itemet** (issue 45b § Beslut 1): den
 * som registrerar en kostnad på ett nytt item ska få containerns hela
 * leverantörshistorik, och `container_id` är denormaliserad på raden just för
 * att frågan inte ska behöva joina `item`. En enda `GROUP BY` mot
 * `cost_entry` — ingen relation, ingen Eloquent-modell — som indexet
 * `(container_id, deleted_at, supplier)` från 45a gör billig i stället för en
 * full scan.
 *
 * `supplier` normaliseras aldrig ([[ADR-0016 Kostnadsregistrering]]): en
 * `strtolower()` i grupperingen hade gett en lista med värden som inte finns
 * i någon rad (issue 45b § Beslut 5). Mjukraderade rader räknas inte och
 * `NULL` filtreras bort — en kostnad i papperskorgen ska inte hålla liv i en
 * leverantör, och en kostnad utan leverantör är inte en leverantör som heter
 * ingenting (§ Beslut 3). Inga värden som förekommer på ett raderat ITEM
 * göms: kostnadsraden är inte raderad, och uppslaget är ett inmatningsstöd,
 * inte en summering.
 *
 * Behörigheten prövas av anroparen mot containerns `view` — den här rutten
 * har ingen item-ULID att pröva en itemgrind mot. Men listan LÄCKER: en lista
 * med "Advokatbyrån Ek & Partners" säger något om containern som mottagaren
 * av motorn inte ska veta (issue 74 § Beslut 6). För en OMFÅNGSBEGRÄNSAD
 * mottagare joinas därför `item` in och omfånget styr raderna. För ett
 * OMFATTANDE omfång läggs ingen join till: den befintliga frågan mot bara
 * `cost_entry` är billigare (indexet från 45a), och de två fallen är två
 * grenar med flit — en join som alltid görs hade kostat ägaren en join i
 * onödan. Raderna är desamma som förut; bara urvalet skiljer.
 *
 * Mjukraderade items filtreras INTE bort i den begränsade grenen: samma regel
 * som ägaren har, att ett värde som förekommer på ett raderat item inte göms.
 * Omfånget är grant-baserat och ett mjukraderat item behåller sin plats i
 * grafen (issue 74 § Beslut 3).
 */
class ListCostSuppliers
{
    /**
     * Högst 50 förslag. Uppslaget matar en autocomplete, inte en rapport, och
     * klienten hämtar listan en gång och filtrerar medan användaren skriver —
     * fler rader hade bara gjort hämtningen långsammare (issue 45b § Beslut
     * 3–4). Inget konfigvärde, ingen query-parameter.
     */
    private const SUPPLIER_SUGGESTION_LIMIT = 50;

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * @return list<array{supplier: string, count: int}>
     */
    public function handle(User $user, Container $container): array
    {
        $query = DB::table('cost_entry')
            ->where('cost_entry.container_id', $container->id)
            ->whereNull('cost_entry.deleted_at')
            ->whereNotNull('cost_entry.supplier');

        $scope = $this->resolveItemScope->handle($user, $container);
        $itemIds = $scope->itemIds();

        if ($itemIds !== null) {
            $query->join('item', 'item.id', '=', 'cost_entry.item_id')
                ->whereIn('item.id', $itemIds);
        }

        $rader = $query
            ->selectRaw('cost_entry.supplier AS supplier, COUNT(*) AS antal')
            ->groupBy('cost_entry.supplier')
            ->orderByDesc('antal')
            ->orderBy('cost_entry.supplier')
            ->limit(self::SUPPLIER_SUGGESTION_LIMIT)
            ->get();

        return array_values(array_map(
            static fn (object $rad): array => [
                'supplier' => $rad->supplier,
                'count' => (int) $rad->antal,
            ],
            $rader->all()
        ));
    }
}
