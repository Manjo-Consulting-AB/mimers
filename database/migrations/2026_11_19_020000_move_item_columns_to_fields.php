<?php

use App\Support\Usage\CountedRows;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Issue 420 · De sex fasta fälten blir vanliga containerfält. Se
 * [[ADR-0089 Egna fält på items]] § Riktning 5 och 9 och
 * [[M68 Egna fält]] § 420.
 *
 * `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until`
 * och `position_note` står kvar som kolumner tills issue 425 (expand/contract,
 * AGENTS.md § Databaskonventioner). Den här migreringen KOPIERAR dem till
 * `item_field` och `item_field_value`; den rör inte kolumnerna.
 *
 * **Varje befintlig container får de sex fälten**, även en utan items och en i
 * papperskorgen: `DB::table('container')` går förbi SoftDeletes-scopet, och
 * fälten hör till containern, inte till dess items. Nya containrar skapas
 * utan fält — fälten kommer från en mall eller från användaren själv
 * (ADR-0089 § Riktning 5).
 *
 * **Rubrikerna skrivs in som text, inte som nycklar.** De är de engelska
 * formuläret visar i dag (`lang/en/ui.php`, `item.form.*`), och engelska är
 * enda språket ([[ADR-0034 Engelska vid lansering]]). En rubrik som är en
 * nyckel hade följt med nästa ordbyte, men fältets rubrik är användarens text
 * och ska stå still när den är skapad.
 *
 * **Ordningen** är `position` 1–6 i den ordning fälten står i formuläret. En
 * container som redan har egna fält får de sex EFTER dem, så att de befintliga
 * behåller sin ordning.
 *
 * **Ett icke-tomt värde kopieras med itemets `billed_account_id`** — den som
 * skapade itemet betalar också för dess värden ([[ADR-0081 All lagrad data
 * räknas mot kvoten]] § 3). Ett tomt värde (`NULL` eller `''`) har ingen rad:
 * ett tomt värde lagras aldrig (ADR-0089 § Riktning 9).
 *
 * **Migreringen är idempotent.** Fältet slås upp på container, rubrik och
 * `item_id IS NULL`; finns det återanvänds det, annars skapas det. Värdet
 * skrivs med `updateOrInsert` på den unika nyckeln `(item_id,
 * item_field_id)`. Att köra `up()` två gånger ger därför varken dubbla fält
 * eller dubbla värden — samma egenskap som proves i issue 420.
 *
 * **Bitar.** Containers och items läses med `chunkById`, så en stor
 * installation inte behöver hålla hela tabellen i minnet på en gång.
 *
 * **`metadata_bytes` räknas om för de konton som berörs** genom
 * App\Support\Usage\CountedRows — samma mått som App\Support\Usage\MetadataSize
 * och nattens avstämning använder. Fälten och värdena är nya räknade rader,
 * och `item`-raden väger mindre när de sex kolumnerna lämnar MetadataSize i
 * samma release. Utan omräkningen hade avstämningen larmat om drift första
 * natten. Bara konton som faktiskt fick en rad skrivs.
 */
return new class extends Migration
{
    /**
     * De sex fälten i formulärets ordning. `column` är kolumnen värdet
     * kopieras ur; `type` är fältets typ i `item_field`.
     *
     * @var list<array{label: string, type: string, column: string}>
     */
    private const FIELDS = [
        ['label' => 'Manufacturer', 'type' => 'text', 'column' => 'manufacturer'],
        ['label' => 'Model', 'type' => 'text', 'column' => 'model'],
        ['label' => 'Serial number', 'type' => 'text', 'column' => 'serial_number'],
        ['label' => 'Purchase date', 'type' => 'date', 'column' => 'purchased_at'],
        ['label' => 'Warranty until', 'type' => 'date', 'column' => 'warranty_until'],
        ['label' => 'Storage location', 'type' => 'text', 'column' => 'position_note'],
    ];

    public function up(): void
    {
        // Varje containerägare får sex nya `item_field`-rader, och varje
        // items betalare får en `item`-rad som väger mindre när de sex
        // kolumnerna lämnar MetadataSize. Båda mängderna räknas om.
        $affectedAccounts = $this->affectedAccounts();

        DB::table('container')
            ->select(['id', 'account_id'])
            ->orderBy('id')
            ->chunkById(500, function ($containers): void {
                foreach ($containers as $container) {
                    $payer = (int) $container->account_id;

                    $position = (int) DB::table('item_field')
                        ->where('container_id', $container->id)
                        ->whereNull('item_id')
                        ->whereNull('deleted_at')
                        ->max('position');

                    foreach (self::FIELDS as $definition) {
                        $fieldId = $this->fieldId($container->id, $definition);

                        if ($fieldId === null) {
                            $position++;
                            $fieldId = (int) DB::table('item_field')->insertGetId([
                                'ulid' => (string) Str::ulid(),
                                'container_id' => $container->id,
                                'item_id' => null,
                                'label' => $definition['label'],
                                'type' => $definition['type'],
                                'position' => $position,
                                'billed_account_id' => $payer,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        $this->copyValues($container->id, $fieldId, $definition);
                    }
                }
            }, 'id', 'id');

        $this->recountMetadata($affectedAccounts);
    }

    /**
     * Kontona som får en ändrad `metadata_bytes`: containrarnas ägare (de nya
     * fälten) och items betalare (itemradens vikt ändras). En itembetalare kan
     * vara en annan än containerns ägare ([[ADR-0081 All lagrad data räknas mot
     * kvoten]] § 3), så mängderna slås ihop.
     *
     * @return list<int>
     */
    private function affectedAccounts(): array
    {
        $owners = DB::table('container')->distinct()->pluck('account_id');
        $itemPayers = DB::table('item')->whereNotNull('billed_account_id')->distinct()->pluck('billed_account_id');

        return $owners
            ->merge($itemPayers)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Det levande containerfältet med rubriken, eller null. Ett fält i
     * papperskorgen räknas inte: containern ska ha sex SYNLIGA fält efter
     * migreringen, och en återställd dubblett hade gett två med samma rubrik.
     */
    private function fieldId(int $containerId, array $definition): ?int
    {
        $id = DB::table('item_field')
            ->where('container_id', $containerId)
            ->whereNull('item_id')
            ->whereNull('deleted_at')
            ->where('label', $definition['label'])
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Kopierar kolumnens icke-tomma värden till `item_field_value` för
     * containerns items — även mjukraderade, så att ett item som
     * återställs ur papperskorgen bär sina värden.
     *
     * @param  array{label: string, type: string, column: string}  $definition
     */
    private function copyValues(int $containerId, int $fieldId, array $definition): void
    {
        $column = $definition['column'];

        $query = DB::table('item')
            ->select(['id', 'billed_account_id', $column])
            ->where('container_id', $containerId)
            ->whereNotNull($column);

        // Textkolumnerna kan bära en tom sträng; en DATE-kolumn kan inte, och
        // att jämföra den med '' är onödigt (och olika strängt på MariaDB).
        if ($definition['type'] !== 'date') {
            $query->where($column, '<>', '');
        }

        $query
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($fieldId, $definition, $column): void {
                foreach ($items as $item) {
                    $value = $item->{$column};

                    if ($value === null || $value === '') {
                        continue;
                    }

                    $attributes = [
                        'billed_account_id' => (int) $item->billed_account_id,
                        'value_text' => null,
                        'value_number' => null,
                        'value_date' => null,
                        'updated_at' => now(),
                    ];

                    if ($definition['type'] === 'date') {
                        $attributes['value_date'] = $value;
                    } else {
                        $attributes['value_text'] = $value;
                    }

                    $row = DB::table('item_field_value')
                        ->where('item_id', $item->id)
                        ->where('item_field_id', $fieldId);

                    if ($row->exists()) {
                        $row->update($attributes);
                    } else {
                        DB::table('item_field_value')->insert($attributes + [
                            'item_id' => $item->id,
                            'item_field_id' => $fieldId,
                            'created_at' => now(),
                        ]);
                    }
                }
            }, 'id', 'id');
    }

    /**
     * Räknar om `metadata_bytes` för de konton migreringen rörde. Måttet
     * kommer ur CountedRows::bytesPerAccount(), samma kod som nattens
     * avstämning — en egen formulering här hade kunnat glida från den.
     *
     * @param  list<int>  $accountIds
     */
    private function recountMetadata(array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }

        $totals = CountedRows::bytesPerAccount();

        foreach ($accountIds as $accountId) {
            $bytes = $totals[$accountId] ?? 0;

            // Finns raden uppdateras den, annars skapas den. Att avgöra det
            // med `update()`s antal påverkade rader fungerar inte: MariaDB
            // räknar BARA rader som faktiskt ändrades, och en andra körning
            // skriver samma tal — då hade INSERT:en krockat på uniknyckeln.
            $exists = DB::table('usage_counter')->where('account_id', $accountId)->exists();

            if ($exists) {
                DB::table('usage_counter')
                    ->where('account_id', $accountId)
                    ->update(['metadata_bytes' => $bytes, 'updated_at' => now()]);

                continue;
            }

            DB::table('usage_counter')->insert([
                'account_id' => $accountId,
                'metadata_bytes' => $bytes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Gör ingenting: migrationer rullas aldrig tillbaka i produktion, fel
     * åtgärdas framåt (AGENTS.md § Databaskonventioner). Fälten och värdena
     * är dessutom användardata efter det att de skapats — användaren kan ha
     * döpt om dem — så en återrullning får inte ta bort dem.
     */
    public function down(): void
    {
        //
    }
};
