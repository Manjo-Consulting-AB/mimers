<?php

namespace App\Support\Usage;

use Illuminate\Database\Eloquent\Model;

/**
 * Hur många byte en räknad rad väger mot kvoten — den enda definitionen
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2). Räkningen vid
 * skrivning och nattens avstämning ska aldrig formulera måttet var för sig:
 * glider de isär rapporterar avstämningen sin egen drift och rättar sönder en
 * räknare som var rätt.
 *
 * En rad väger summan av `strlen()` (byte, inte tecken) för sina fritext-
 * kolumner plus ett fast påslag på `ROW_OVERHEAD_BYTES`. Påslaget står för id,
 * nycklar, index och datum, och gör att miljoner tomma rader inte är gratis.
 * `schedule_occurrence` räknar bara `completion_note` och utan påslag: raderna
 * skapas av systemet, inte av användaren.
 *
 * `strlen()` och inte SQL `LENGTH()` med flit: SQLite räknar tecken och
 * MariaDB byte, så summorna skulle skilja mellan testerna och produktionen.
 * Ikon- och färgkoder, uppräkningar och datum är inte fritext.
 */
final class MetadataSize
{
    public const ROW_OVERHEAD_BYTES = 256;

    /**
     * Fritextkolumnerna per räknad tabell som hänger på en container.
     * Nycklarna här är också listan CountedRows::move() och ägarbytets
     * genomgångar itererar; hela listan över räknade tabeller är
     * countedTables(), som även bär ACCOUNT_COLUMNS.
     *
     * `container.kind` är med: sedan `free_container_kind` är arten fri text
     * (en egen art, upp till 40 tecken), inte en kod ur en fast lista.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'container' => ['name', 'description', 'kind'],
        'category' => ['name'],
        'tag' => ['name'],
        // De sex fasta fälten är containerns fält sedan issue 420
        // ([[ADR-0089 Egna fält på items]] § Riktning 5): deras värden räknas
        // i `item_field_value`, inte här. Kolumnerna står kvar till 425 men
        // räknas inte — de är inte längre itemets lagrade innehåll.
        'item' => ['name', 'description', 'notes'],
        // En version väger som itemraden: de tre fritextkolumnerna och
        // `field_values` (issue 411 · [[ADR-0088 Versionshistorik för items]]
        // Riktning 3). `tag_ids` är löpnummer i JSON och räknas inte, samma
        // regel som för kopplingsrader ([[ADR-0081 All lagrad data räknas mot
        // kvoten]] § 2).
        'item_version' => ['name', 'description', 'notes', 'field_values'],
        // Egna fält (issue 417 · [[ADR-0089 Egna fält på items]]
        // § Riktning 3): definitionen bär rubriken, värdet den skrivna
        // texten. Ett fälts `label` är användarens fritext och räknas som en
        // rad; `item_field_value` räknas med samma påslag som allt annat,
        // oavsett om värdet är text, tal eller datum — bara `value_text` är
        // fritext ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2).
        'item_field' => ['label'],
        'item_field_value' => ['value_text'],
        'schedule' => ['title', 'notes'],
        'schedule_occurrence' => ['completion_note'],
        'cost_entry' => ['description', 'counterparty'],
        'loan' => ['borrower_name', 'borrower_email', 'note'],
    ];

    /**
     * Fritextkolumnerna för de räknade tabeller som ägs av ett KONTO och
     * saknar container. `container_template` bär inget `container_id`
     * ([[ADR-0089 Egna fält på items]] § Riktning 6): raden hör till kontot,
     * och radernas väg till en container finns inte.
     *
     * Listan står skild från COLUMNS med flit. COLUMNS är också listan
     * CountedRows::move() och ägarbytets genomgångar itererar, och de frågar
     * alltid efter `container.id` — en tabell utan container hade fallit på
     * en kolumn som inte finns. Den som lägger till en kontonivåtabell här
     * får den räknad i avstämningen utan att röra flyttarna.
     *
     * **En mjukraderad mall väger noll**, till skillnad från allt annat
     * mjukraderat ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3). En
     * mall går inte att återställa och har inget gallringsjobb, så byte som
     * räknades vidare skulle aldrig släppas: användaren hade betalat för
     * något hon varken ser eller kan ta tillbaka. Regeln står här och inte
     * bara i anroparen, eftersom `ofAttributes()` är den enda definitionen
     * av vad en rad väger. Avvikelsen från ADR-0081 § 3 ska skrivas in i
     * [[Items och organisation]] § container_template.
     *
     * @var array<string, list<string>>
     */
    public const ACCOUNT_COLUMNS = [
        'container_template' => ['name', 'content'],
    ];

    /**
     * Tabellerna som saknar påslaget.
     *
     * @var list<string>
     */
    private const WITHOUT_OVERHEAD = ['schedule_occurrence'];

    public static function of(Model $row): int
    {
        return self::ofAttributes($row->getTable(), $row->getAttributes());
    }

    /**
     * Fritextkolumnerna för en räknad tabell, oavsett om den hänger på en
     * container eller på ett konto.
     *
     * @return list<string>
     */
    public static function columnsFor(string $table): array
    {
        return self::COLUMNS[$table] ?? self::ACCOUNT_COLUMNS[$table] ?? [];
    }

    /**
     * Alla räknade tabeller — containertabellerna och kontonivåtabellerna.
     * Används av avstämningen, som ska summera varje räknad rad oavsett var
     * den bor. Flyttarna itererar COLUMNS och inte den här listan, se
     * ACCOUNT_COLUMNS.
     *
     * @return list<string>
     */
    public static function countedTables(): array
    {
        return array_merge(array_keys(self::COLUMNS), array_keys(self::ACCOUNT_COLUMNS));
    }

    /**
     * För anropare som har råa värden, till exempel vid massradering.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function ofAttributes(string $table, array $attributes): int
    {
        // En mjukraderad mall räknas inte — se ACCOUNT_COLUMNS ovan. Regeln
        // gäller bara `container_template`: alla andra tabeller räknar sina
        // slängda rader tills de raderas för gott (ADR-0081 § 3).
        if ($table === 'container_template' && ($attributes['deleted_at'] ?? null) !== null) {
            return 0;
        }

        $bytes = 0;

        foreach (self::columnsFor($table) as $column) {
            $value = $attributes[$column] ?? null;

            if ($value !== null) {
                $bytes += strlen((string) $value);
            }
        }

        return in_array($table, self::WITHOUT_OVERHEAD, true)
            ? $bytes
            : $bytes + self::ROW_OVERHEAD_BYTES;
    }
}
