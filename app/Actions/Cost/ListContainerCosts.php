<?php

namespace App\Actions\Cost;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Category\ResolveCategoryDescendants;
use App\Models\Container;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Containerns kostnadsrader, en sida i taget — underlaget för tabellen på
 * containerns kostnadsflik (issue 175 · [[ADR-0050 Desktopdesignen]] § 9).
 *
 * **Radmängden är rapportens, rad för rad** ([[ADR-0016 Kostnadsregistrering]]
 * § Vad som ingår): `cost_entry` i containern, mjukraderade rader bort, och
 * bara rader på LEVANDE items — en kostnad på ett raderat item är osynlig i
 * listning, sök och todo, och ska inte dyka upp i en tabell användaren inte
 * kan klicka sig fram till. Det är samma två `whereNull` som
 * App\Support\Cost\CostReport::rowSet() ställer, och av samma skäl: en
 * tabell som visade fler rader än brickan ovanför räknar hade varit två svar
 * på frågan *vilka rader finns*.
 *
 * **Omfånget ligger på raderna, som i `ListCostSuppliers`.** Behörigheten
 * prövas av anroparen mot containerns `view` — den här rutten har ingen
 * item-ULID att pröva en itemgrind mot — men listan LÄCKER: en kostnadsrad
 * bär beskrivning och leverantör, alltså samma sorts upplysning som
 * leverantörslistan (issue 74 § Beslut 6). En OMFÅNGSBEGRÄNSAD mottagare får
 * därför `whereIn` på omfångets item-id:n, och ett OMFATTANDE omfång slipper
 * villkoret helt: `ItemScope::itemIds()` svarar `null` för "hela containern",
 * och den listan ska inte materialiseras till en `whereIn` med varje
 * löpnummer (issue 73 § Beslut 1).
 *
 * **Joinen mot `item` görs alltid, och det skiljer den från
 * `ListCostSuppliers`.** Där var joinen bara ett sätt att applicera omfånget,
 * och ett obegränsat omfång slapp den. Här bär raden itemets namn och ULID —
 * tabellen länkar varje rad till itemets kostnadsflik (issue 168) — och
 * itemet måste alltså läsas i båda fallen. Papperskorgen är den andra halvan
 * av samma join: `item.deleted_at IS NULL` filtrerar bort raderna på ett
 * raderat item, vilket ingen annan kolumn i `cost_entry` kan avgöra.
 *
 * **Ordningen är densamma som itemets kostnadsflik har** (issue 45a
 * § Beslut 7): `incurred_on` fallande med
 * `id` fallande som andrasortering, så den senaste kostnaden står först och
 * två kostnader samma dag ändå får en stabil ordning. `id` är löpnumret och
 * lämnar aldrig svaret — det är sorteringsnyckeln, inte en identifierare.
 *
 * **Tjugofem rader per sida.** Antalet står här och inte i en query-parameter:
 * en sidstorlek som gick att skicka in vore ännu en fråga användaren ställer,
 * och [[ADR-0038 Gränsen för Pro i kostnaderna]] drar gränsen vid frågan. Det
 * är samma tal som bilden ritar i tabellen utan att räkna.
 *
 * **Raden är en array och inte en modell.** Formen är tabellens och inte
 * API:ets: `CostEntryResource` är `/api`s svar och bär varken itemets namn
 * eller dess ULID (den nästlade rutten vet redan vilket itemet är). Att lägga
 * ett `item`-fält i resursen hade varit att ändra API:ets kontrakt för en
 * webbvy, vilket [[ADR-0021 Frontendteknik]] lägger i kontrollern —
 * `can.update`-mönstret — och inte i resursen.
 *
 * **Datumet klipps till tio tecken.** `incurred_on` är en DATE-kolumn, men
 * sqlite lagrar den med en tidskomponent (samma egenskap som gör att
 * CostReport jämför med `whereDate()`), och en rå `DB::table`-rad går inte
 * genom någon Eloquent-cast. `substr()` ger 'YYYY-MM-DD' i både sqlite och
 * MariaDB, precis som `groupByPeriod()` i CostReport redan gör av samma skäl —
 * och en kostnads dag har ingen tidszon att visa ([[ADR-0016
 * Kostnadsregistrering]] § Konsekvenser).
 *
 * **Sedan issue 176 följer tabellen Pro-filtret.** `$filters` är de fem
 * validerade parametrarna ur App\Http\Requests\Cost\ContainerCostFilterRequest
 * — `from`, `to`, `item`, `category` och `supplier` — och de tillämpas med
 * EXAKT samma betydelse som CostReport::baseQuery() ger dem, så att tabellen
 * och talen ovanför den svarar på samma fråga. Särskilt `category`: filtret
 * tar hela underträdet, precis som i rapporten, för en tabell som visade färre
 * rader än nedbrytningen räknar hade varit två svar på *vilka rader finns*.
 * Tomt `$filters` är den fria fliken oförändrad (issue 175) — en gratisanvändare
 * når den här metoden utan filter, och raderna är då containerns.
 */
final class ListContainerCosts
{
    /**
     * Antalet rader per sida i containerns kostnadstabell (issue 175).
     */
    public const PER_PAGE = 25;

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ResolveCategoryDescendants $resolveCategoryDescendants,
    ) {}

    /**
     * Formen på varje rad, led för led — tabellens kontrakt:
     *
     *     {ulid, incurred_on: 'YYYY-MM-DD', description, supplier|null, amount,
     *      currency, item: {ulid, name}}
     *
     * `amount` är heltalet i minsta valutaenhet, aldrig en formaterad sträng
     * ([[ADR-0016 Kostnadsregistrering]] § Konsekvenser) — formateringen sker i
     * vyn, av samma `formatAmount` som donuten ritar sina tal med. Den står
     * som en rad i docblocken och inte som ett `@return`-märke: `through()`
     * byter radtyp men behåller paginatorns nyckeltyp, och det paret kan
     * PHPStan inte bevisa — märket hade varit en påstådd typ ingen prövar.
     *
     * @param  array{from?: string|null, to?: string|null, item?: string|null, category?: string|null, supplier?: string|null}  $filters
     */
    public function handle(User $user, Container $container, array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('cost_entry')
            ->join('item', 'item.id', '=', 'cost_entry.item_id')
            ->where('cost_entry.container_id', $container->id)
            ->whereNull('cost_entry.deleted_at')
            ->whereNull('item.deleted_at');

        $itemIds = $this->resolveItemScope->handle($user, $container)->itemIds();

        if ($itemIds !== null) {
            $query->whereIn('item.id', $itemIds);
        }

        $this->applyFilters($query, $container, $filters);

        $sidor = $query
            ->select([
                'cost_entry.ulid',
                'cost_entry.incurred_on',
                'cost_entry.description',
                'cost_entry.supplier',
                'cost_entry.amount',
                'cost_entry.currency',
                'item.ulid as item_ulid',
                'item.name as item_name',
            ])
            ->orderByDesc('cost_entry.incurred_on')
            ->orderByDesc('cost_entry.id')
            ->paginate(self::PER_PAGE);

        // Raden byggs med uttryckliga omvandlingar: en `DB::table`-rad är
        // `mixed` i varje led, och formen är tabellens kontrakt — den ska stå
        // skriven, inte härledas.
        return $sidor->through(static fn (object $rad): array => [
            'ulid' => (string) $rad->ulid,
            'incurred_on' => substr((string) $rad->incurred_on, 0, 10),
            'description' => (string) $rad->description,
            'supplier' => $rad->supplier === null ? null : (string) $rad->supplier,
            'amount' => (int) $rad->amount,
            'currency' => (string) $rad->currency,
            'item' => ['ulid' => (string) $rad->item_ulid, 'name' => (string) $rad->item_name],
        ]);
    }

    /**
     * Filtren, i samma form och med samma betydelse som
     * CostReport::baseQuery() ger dem (issue 176).
     *
     * `item` jämförs på `item.ulid` och inte på ett uppslaget löpnummer:
     * joinen mot `item` ligger redan i frågan, och ULID:n är bevisad av
     * ContainerCostFilterRequest — ett uppslag till hade varit en fråga för
     * att byta ut en kolumn mot en annan i en join som redan är gjord.
     *
     * `category` tar hela underträdet, som i rapporten: en underkategori är
     * en kategori, och fliken filtrerar på samma begrepp som nedbrytningen
     * grupperar på. Kategorin slås upp i containern — `firstOrFail()` och inte
     * en tyst null, för ett ULID som försvunnit mellan valideringen och
     * frågan ska ge ett fel och inte en tabell som tyst visar allt.
     *
     * `from`/`to` jämförs med `whereDate()`, av samma skäl som i CostReport:
     * sqlite lagrar en DATE-kolumn med en tidskomponent, och en datumgräns ska
     * inte bero på klockslaget när frågan körs.
     *
     * @param  array{from?: string|null, to?: string|null, item?: string|null, category?: string|null, supplier?: string|null}  $filters
     */
    private function applyFilters(Builder $query, Container $container, array $filters): void
    {
        if (! empty($filters['item'])) {
            $query->where('item.ulid', $filters['item']);
        }

        if (! empty($filters['category'])) {
            $category = $container->categories()
                ->where('ulid', $filters['category'])
                ->firstOrFail();

            $query->whereIn('item.category_id', $this->resolveCategoryDescendants->handle($category));
        }

        if (($filters['supplier'] ?? null) !== null) {
            $query->where('cost_entry.supplier', $filters['supplier']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->whereDate('cost_entry.incurred_on', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->whereDate('cost_entry.incurred_on', '<=', $filters['to']);
        }
    }
}
