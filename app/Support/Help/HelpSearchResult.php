<?php

namespace App\Support\Help;

/**
 * Svaret ur hjälpsökningen — M81 · issue 466, [[ADR-0094 Hjälpcentret]] § 5.
 *
 * `query` är den normaliserade frågan, inte den råa: det är den formen
 * sökningen faktiskt använde, och samma form som `help_search_gap_daily`
 * sparar (ADR-0094 § 8).
 *
 * `partial` är sant när inte alla ord kunde träffa och sökningen gjordes om
 * med kravet att minst hälften av orden träffar. Klienten kan då säga att
 * träffarna är ungefärliga i stället för att visa en tom lista.
 *
 * **Segmenten är färdiga att rita.** Både `title` och `snippet` är listor av
 * `{text, match}`, så klienten markerar en träff med `<mark>` utan `v-html`
 * och utan att själv behöva känna till normaliseringen. Segmenten fogade ihop
 * ger exakt titeln respektive utdraget.
 *
 * **`scores` serialiseras inte.** Poängen behövs för ordningen och för proven
 * som jämför två träffar mot varandra, men den är ingen del av API-kontraktet;
 * `toArray()` lämnar den utanför.
 */
final readonly class HelpSearchResult
{
    /**
     * @param  string  $query  Den normaliserade frågan.
     * @param  bool  $partial  Gjordes sökningen om med ett lägre krav på träffar?
     * @param  list<array{
     *     id: string,
     *     url: string,
     *     type: string,
     *     category: array{id: string, title: string},
     *     title: list<array{text: string, match: bool}>,
     *     snippet: list<array{text: string, match: bool}>,
     * }>  $results  Träffarna i rangordning.
     * @param  array<string, float>  $scores  Poängen per artikel-id, för ordningen och proven.
     */
    public function __construct(
        public string $query,
        public bool $partial,
        public array $results,
        private array $scores = [],
    ) {}

    /** Poängen för en träff, eller null om artikeln inte finns bland träffarna. */
    public function score(string $id): ?float
    {
        return $this->scores[$id] ?? null;
    }

    /**
     * Svaret som API-kontraktet ser det — utan poängen.
     *
     * @return array{
     *     query: string,
     *     partial: bool,
     *     results: list<array{
     *         id: string,
     *         url: string,
     *         type: string,
     *         category: array{id: string, title: string},
     *         title: list<array{text: string, match: bool}>,
     *         snippet: list<array{text: string, match: bool}>,
     *     }>,
     * }
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'partial' => $this->partial,
            'results' => $this->results,
        ];
    }
}
