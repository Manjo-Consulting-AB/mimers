<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1784 · M99 Transaktioner på items, se [[ADR-0102 Transaktioner på
 * items]] § Riktning 8 och [[Items och organisation]] § cost_entry.
 *
 * Tabellen `cost_entry` döps om till `item_transaction`: raden är en
 * transaktion, och namnet följer nu begreppet. Beteendet ändras inte.
 *
 * Index, främmande nycklar och CHECK-villkoret bär tabellnamnet som prefix
 * och döps om med. Namnen slås upp i schemat i stället för att räknas upp, så
 * att migrationen också klarar en databas där ett index fått ett annat namn.
 * Främmande nycklar och CHECK döps bara om på mysql/mariadb; SQLite saknar
 * stöd för att byta namn på dem. `down()` gör allt baklänges.
 */
return new class extends Migration
{
    private function isMysql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if ($this->isMysql()) {
            $this->renameConstraints('cost_entry', 'cost_entry', 'item_transaction');
        }

        Schema::rename('cost_entry', 'item_transaction');

        $this->renameIndexes('item_transaction', 'cost_entry', 'item_transaction');
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            $this->renameConstraints('item_transaction', 'item_transaction', 'cost_entry');
        }

        Schema::rename('item_transaction', 'cost_entry');

        $this->renameIndexes('cost_entry', 'item_transaction', 'cost_entry');
    }

    private function renameIndexes(string $table, string $from, string $to): void
    {
        $indexes = array_filter(
            Schema::getIndexes($table),
            fn (array $index): bool => ! $index['primary'] && str_starts_with($index['name'], $from.'_'),
        );

        if ($indexes === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexes, $from, $to): void {
            foreach ($indexes as $index) {
                $blueprint->renameIndex($index['name'], $to.substr($index['name'], strlen($from)));
            }
        });
    }

    /**
     * Döper om främmande nycklar och CHECK-villkor innan tabellen byter namn.
     * Index döps om efteråt, när tabellen redan heter rätt.
     */
    private function renameConstraints(string $table, string $from, string $to): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (! str_starts_with($foreign['name'], $from.'_')) {
                continue;
            }

            $name = $to.substr($foreign['name'], strlen($from));

            Schema::table($table, function (Blueprint $blueprint) use ($foreign, $name): void {
                $blueprint->dropForeign($foreign['name']);
                $blueprint->foreign($foreign['columns'], $name)
                    ->references($foreign['foreign_columns'])
                    ->on($foreign['foreign_table'])
                    ->onDelete('restrict');
            });
        }

        $oldCheck = $from.'_kind_check';
        $newCheck = $to.'_kind_check';

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$oldCheck}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$newCheck} CHECK (kind IN ('expense','income'))");
    }
};
