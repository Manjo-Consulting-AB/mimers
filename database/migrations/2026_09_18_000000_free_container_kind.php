<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 84 · Containerns art blir fri. Se [[ADR-0036 Containerns art]] och
 * [[ADR-0033 Produktens omfång]] § Beslut.
 *
 * Två saker frigörs, och båda rör samma kolumn:
 *
 * - CHECK-villkoret `kind IN ('boat', 'caravan', 'house', 'car', 'other')`
 *   släpps. Det är en gräns som tas bort, inte data: de fem värdena är
 *   fortfarande giltiga strängar, och ingen rad skrivs om.
 * - Kolumnen blir NULLBAR. Fältet är frivilligt, och "ingen art angiven" ska
 *   lagras som tomt och inte som en tom sträng.
 *
 * Villkoret skapades i 2026_08_25_010000_create_container_table.php och lades
 * bara på mysql — sqlite saknar ALTER TABLE ... DROP CHECK. Samma
 * drivrutinsvakt här, av samma skäl.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE container DROP CHECK container_kind_check');
        }

        Schema::table('container', function (Blueprint $table) {
            $table->string('kind', 40)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Bägge ändringarna är förluster för en rad som redan skrivits: en EGEN
     * art ryms inte i det gamla villkoret, och en rad helt UTAN art ryms inte
     * i en NOT NULL-kolumn. Den som rullar tillbaka får städa dem först.
     * Migrationer rullas aldrig tillbaka i produktion (AGENTS.md
     * § Databaskonventioner); det här är för utvecklingsmaskinen.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE container ADD CONSTRAINT container_kind_check CHECK (kind IN ('boat', 'caravan', 'house', 'car', 'other'))");
        }

        Schema::table('container', function (Blueprint $table) {
            $table->string('kind', 40)->nullable(false)->change();
        });
    }
};
