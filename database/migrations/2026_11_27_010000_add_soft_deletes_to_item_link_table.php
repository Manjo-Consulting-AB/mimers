<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Issue 502 · En borttagen relation mjukraderas, se [[ADR-0097 Borttagna
 * relationer i papperskorgen]] Beslut 2. Ändrar issue 14 § Beslut 10.
 *
 * `deleted_at` bär mjukraderingen. `ulid` pekar ut raden för papperskorgen
 * (issue 503); ingen rutt för relationer ändras. `deleted_by_agent_id` och
 * `agent_deletion_batch` är agentmärkningen, samma kolumner och namn som på
 * `item` (se 2026_11_19_000000_add_agent_deletion_to_trashable_tables).
 *
 * UNIQUE `(from_item_id, to_item_id, relation)` rörs inte: den gäller även
 * mjukraderade rader, och LinkItems/MoveItem raderar en mjukraderad rad för
 * samma par hårt innan den nya skrivs.
 *
 * `ulid` läggs till som nullable, fylls för befintliga rader och får sedan
 * sitt unika index — en unik kolumn utan värden går inte att lägga till på en
 * tabell med rader. Modellen fyller i den vid skapandet.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Kan köras en gång till för hand (provet i RelationMjukraderingTest
        // gör det): kolumnerna och indexet läggs bara till om de saknas.
        if (! Schema::hasColumn('item_link', 'ulid')) {
            Schema::table('item_link', function (Blueprint $table): void {
                $table->char('ulid', 26)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->foreignId('deleted_by_agent_id')->nullable()->constrained('agent')->nullOnDelete();
                $table->char('agent_deletion_batch', 26)->nullable();
                $table->index('agent_deletion_batch');
            });
        }

        // chunkById, inte each/chunk: de paginerar med offset, och rader som
        // fått ulid faller ur whereNull-filtret så nästa sida hoppar över rader.
        DB::table('item_link')->whereNull('ulid')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('item_link')->where('id', $row->id)->update(['ulid' => (string) Str::ulid()]);
            }
        });

        if (! Schema::hasIndex('item_link', ['ulid'], 'unique')) {
            Schema::table('item_link', function (Blueprint $table): void {
                $table->unique('ulid');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_link', function (Blueprint $table): void {
            $table->dropUnique(['ulid']);
            $table->dropForeign(['deleted_by_agent_id']);
            $table->dropIndex(['agent_deletion_batch']);
            $table->dropColumn(['ulid', 'deleted_at', 'deleted_by_agent_id', 'agent_deletion_batch']);
        });
    }
};
