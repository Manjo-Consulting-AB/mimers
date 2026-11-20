<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grunden för att text räknas mot kvoten ([[ADR-0081 All lagrad data räknas
 * mot kvoten]], issue 1301):
 *
 * - `usage_counter.metadata_bytes` — textens byte, bredvid `storage_bytes`.
 * - `billed_account_id` på varje räknad tabell utom `container`, vars
 *   betalare är ägaren (`container.account_id`). FK mot `account`, RESTRICT,
 *   med index. NOT NULL, utom i `schedule_occurrence` där kolumnen är NULL
 *   tills en anteckning skrivs.
 *
 * Befintliga rader fylls i här, och kolumnen görs NOT NULL först efter
 * ifyllningen (annars faller ALTER på de rader som fanns):
 *
 * - `item` och `cost_entry`: `created_by_account_id`, eller containerns ägare
 *   om kolumnen är NULL (kontoradering nollställer den, ADR-0045 § Beslut 2).
 * - `schedule_occurrence`: `completed_by_account_id` där anteckningen inte är
 *   tom, annars NULL; är `completed_by_account_id` NULL används containerns
 *   ägare.
 * - `category`, `tag`, `schedule`, `loan`: containerns ägare, eftersom raden
 *   saknar författarkolumn.
 *
 * Sist räknas `metadata_bytes` fram för alla konton. Tabellerna och
 * fritextkolumnerna står skrivna i migreringen, frysta som de såg ut när den
 * skrevs (2026-11-15) — den läser inte App\Support\Usage. Listan där växer:
 * `item_version`, `item_field`, `item_field_value` och `container_template`
 * kom efter den här migreringen, och `item` tappar de sex fasta fältens
 * kolumner i issue 425. En migrering som redan kört i produktionen kör inte
 * om; frysningen gäller bara en färsk databas, där migreringen körs i
 * tidsordning före de senare tabellerna och kolumnbytena. Räknaren rättas
 * därefter av nattens avstämning, som läser den levande listan (issue 1061).
 *
 * Ett konto vars räknarrad saknas får en.
 *
 * Ifyllningen är en egen metod så att provet kan köra den utan att schemat
 * byggs om.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['category', 'tag', 'item', 'schedule', 'schedule_occurrence', 'cost_entry', 'loan'];

    /**
     * De räknade tabellernas fritextkolumner, frysta som de såg ut
     * 2026-11-15. `item` bär de sex fasta fältens kolumner här: det var de
     * som räknades då, och en färsk databas kör den här migreringen före
     * issue 420:s migrering som flyttar värdena till `item_field_value`.
     * `container.kind` är fri text sedan `free_container_kind` och räknas.
     *
     * @var array<string, list<string>>
     */
    private const TEXT_COLUMNS = [
        'container' => ['name', 'description', 'kind'],
        'category' => ['name'],
        'tag' => ['name'],
        'item' => ['name', 'description', 'notes', 'manufacturer', 'model', 'serial_number', 'position_note'],
        'schedule' => ['title', 'notes'],
        'schedule_occurrence' => ['completion_note'],
        'cost_entry' => ['description', 'supplier'],
        'loan' => ['borrower_name', 'borrower_email', 'note'],
    ];

    private const ROW_OVERHEAD_BYTES = 256;

    /** @var list<string> */
    private const WITHOUT_OVERHEAD = ['schedule_occurrence'];

    public function up(): void
    {
        Schema::table('usage_counter', function (Blueprint $table) {
            $table->unsignedBigInteger('metadata_bytes')->default(0)->after('container_count');
        });

        // Ett block per tabell med tabellnamnet utskrivet: Larastan läser
        // modellernas kolumner ur migreringarna och följer inte ett variabelt namn.
        Schema::table('category', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        Schema::table('tag', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        Schema::table('item', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        Schema::table('schedule', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        Schema::table('loan', function (Blueprint $table) {
            $table->foreignId('billed_account_id')->nullable()->index()->constrained('account')->onDelete('restrict');
        });

        $this->backfillBilledAccounts();

        foreach (self::TABLES as $name) {
            if ($name === 'schedule_occurrence') {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('billed_account_id')->nullable(false)->change();
            });
        }

        $this->backfillMetadataBytes();
    }

    /**
     * Ägaren av containern som raden ligger i, som korrelerad underfråga.
     * Vägen dit skiljer per tabell, se App\Support\Usage\CountedRows.
     */
    private function ownerOf(string $table): string
    {
        return match ($table) {
            'schedule', 'loan' => "(SELECT c.account_id FROM container c JOIN item i ON i.container_id = c.id WHERE i.id = {$table}.item_id)",
            'schedule_occurrence' => "(SELECT c.account_id FROM container c JOIN item i ON i.container_id = c.id JOIN schedule s ON s.item_id = i.id WHERE s.id = {$table}.schedule_id)",
            default => "(SELECT c.account_id FROM container c WHERE c.id = {$table}.container_id)",
        };
    }

    public function backfillBilledAccounts(): void
    {
        foreach (['item', 'cost_entry'] as $table) {
            DB::statement("UPDATE {$table} SET billed_account_id = COALESCE(created_by_account_id, {$this->ownerOf($table)}) WHERE billed_account_id IS NULL");
        }

        foreach (['category', 'tag', 'schedule', 'loan'] as $table) {
            DB::statement("UPDATE {$table} SET billed_account_id = {$this->ownerOf($table)} WHERE billed_account_id IS NULL");
        }

        DB::statement(
            "UPDATE schedule_occurrence SET billed_account_id = COALESCE(completed_by_account_id, {$this->ownerOf('schedule_occurrence')}) "
            ."WHERE billed_account_id IS NULL AND completion_note IS NOT NULL AND completion_note <> ''"
        );
    }

    public function backfillMetadataBytes(): void
    {
        $perAccount = $this->countedBytesPerAccount();

        foreach ($perAccount as $accountId => $bytes) {
            $updated = DB::table('usage_counter')
                ->where('account_id', $accountId)
                ->update(['metadata_bytes' => $bytes, 'updated_at' => now()]);

            if ($updated === 0) {
                DB::table('usage_counter')->insert([
                    'account_id' => $accountId,
                    'metadata_bytes' => $bytes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Summan av radernas vikt per betalande konto, enligt den frysta listan.
     * Samma regel som App\Support\Usage\MetadataSize::ofAttributes(): summan
     * av `strlen()` för fritextkolumnerna plus ett fast påslag per rad, utom
     * `schedule_occurrence` som bara räknar anteckningen. En rad utan
     * betalare räknas inte.
     *
     * @return array<int, int>
     */
    private function countedBytesPerAccount(): array
    {
        $totals = [];

        foreach (self::TEXT_COLUMNS as $table => $columns) {
            $this->countedQuery($table)->chunkById(500, function ($rows) use ($table, $columns, &$totals): void {
                foreach ($rows as $row) {
                    if ($row->payer_id === null) {
                        continue;
                    }

                    $bytes = 0;

                    foreach ($columns as $column) {
                        $value = $row->{$column} ?? null;

                        if ($value !== null) {
                            $bytes += strlen((string) $value);
                        }
                    }

                    if (! in_array($table, self::WITHOUT_OVERHEAD, true)) {
                        $bytes += self::ROW_OVERHEAD_BYTES;
                    }

                    $totals[(int) $row->payer_id] = ($totals[(int) $row->payer_id] ?? 0) + $bytes;
                }
            }, "{$table}.id", 'id');
        }

        return $totals;
    }

    /**
     * Raderna i en räknad tabell, med `id`, `payer_id` och fritextkolumnerna.
     * Vägen till betalaren skiljer per tabell: `container` betalas av sin
     * ägare, `schedule_occurrence` går genom schemat och itemet, `schedule`
     * och `loan` genom itemet, och resten bär `container_id` själva.
     */
    private function countedQuery(string $table): Builder
    {
        $text = array_map(
            fn (string $column): string => "{$table}.{$column}",
            self::TEXT_COLUMNS[$table],
        );

        if ($table === 'container') {
            return DB::table('container')->select([
                'container.id',
                'container.account_id as payer_id',
                ...$text,
            ]);
        }

        $query = DB::table($table);

        $query = match ($table) {
            'schedule_occurrence' => $query
                ->join('schedule', 'schedule.id', '=', 'schedule_occurrence.schedule_id')
                ->join('item', 'item.id', '=', 'schedule.item_id')
                ->join('container', 'container.id', '=', 'item.container_id'),
            'schedule', 'loan' => $query
                ->join('item', 'item.id', '=', "{$table}.item_id")
                ->join('container', 'container.id', '=', 'item.container_id'),
            default => $query->join('container', 'container.id', '=', "{$table}.container_id"),
        };

        return $query->select([
            "{$table}.id",
            "{$table}.billed_account_id as payer_id",
            ...$text,
        ]);
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign("{$name}_billed_account_id_foreign");
                $table->dropIndex("{$name}_billed_account_id_index");
                $table->dropColumn('billed_account_id');
            });
        }

        Schema::table('usage_counter', function (Blueprint $table) {
            $table->dropColumn('metadata_bytes');
        });
    }
};
