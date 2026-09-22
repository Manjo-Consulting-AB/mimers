<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 87 · Relationen heter related. Se [[ADR-0035 Relationen mellan
 * objekt]] och [[M14 Besluten ur mockupgenomgången]] § 87.
 *
 * Namnbytet är allt som händer: `item_link.relation` bär samma tre värden
 * som förut, men det tredje heter `related` i stället för `sibling`.
 * Ingen regel om hur en relation skrivs, läses, normaliseras eller raderas
 * rörs, och åtkomstregeln står oförändrad — delning når nedåt längs
 * `parent`/`child`, en `related`-länk delar ingenting ([[ADR-0028 Åtkomst
 * på itemnivå]]).
 *
 * Två saker måste göras, och i den ordningen:
 *
 * 1. CHECK-villkoret släpps. Det gamla tillåter inte `related`, så en
 *    omskrivning av raderna hade fallit på det.
 * 2. Raderna som bär det gamla värdet skrivs om. En migrering som bara
 *    byter villkoret lämnar data som bryter mot det.
 *
 * Sedan sätts villkoret tillbaka med det nya värdet.
 *
 * Villkoret skapades i 2026_08_31_130000_create_item_link_table.php och lades
 * bara på mysql — sqlite saknar ALTER TABLE ... DROP CONSTRAINT. Samma
 * drivrutinsvakt här, av samma skäl, och samma form som
 * 2026_09_18_000000_free_container_kind.php. Den gamla migreringen lämnas
 * orörd: den beskriver vad som byggdes då, och migrationer rullas aldrig
 * tillbaka i produktion (AGENTS.md § Databaskonventioner).
 *
 * Omskrivningen går via frågebyggaren och inte modellen, så `updated_at`
 * står stilla: en rad byter namn, den ändras inte.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_link DROP CONSTRAINT item_link_relation_check');
        }

        DB::table('item_link')
            ->where('relation', 'sibling')
            ->update(['relation' => 'related']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE item_link ADD CONSTRAINT item_link_relation_check CHECK (relation IN ('parent', 'child', 'related'))");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Namnbytet är förlustfritt åt båda hållen — `related` och `sibling` är
     * samma värde — så raderna kan skrivas tillbaka exakt. Migrationer rullas
     * aldrig tillbaka i produktion (AGENTS.md § Databaskonventioner); det
     * här är för utvecklingsmaskinen.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_link DROP CONSTRAINT item_link_relation_check');
        }

        DB::table('item_link')
            ->where('relation', 'related')
            ->update(['relation' => 'sibling']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE item_link ADD CONSTRAINT item_link_relation_check CHECK (relation IN ('parent', 'child', 'sibling'))");
        }
    }
};
