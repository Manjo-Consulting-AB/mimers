<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 84 · Containerns art blir fri. Se [[ADR-0036 Containerns art]] och
 * [[ADR-0033 Produktens omfång]] § Beslut.
 *
 * EN sak ändras: CHECK-villkoret `kind IN ('boat', 'caravan', 'house',
 * 'car', 'other')` släpps. Det är en gräns som tas bort, inte data — de fem
 * värdena är fortfarande giltiga strängar, och ingen rad skrivs om.
 *
 * Kolumnen förblir NOT NULL. Fältet är frivilligt, och "ingen art angiven"
 * lagras som den tomma strängen — samma värde en tom ruta i ett formulär
 * alltid burit i den här kolumnen. Att i stället göra kolumnen nullbar hade
 * krävt att App\Actions\Container\CreateContainer::handle() tog `?string`,
 * och den filen ligger utanför den här issuns omfångsruta; se `Frågor och
 * antaganden` i PR:en. Schemaändringen hålls därför till den enda raden
 * issuen pekar ut.
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
    }

    /**
     * Reverse the migrations.
     *
     * Rader som bär en EGEN art kan inte rymmas i det gamla villkoret — den
     * som rullar tillbaka får städa dem först. Migrationer rullas aldrig
     * tillbaka i produktion (AGENTS.md § Databaskonventioner); det här är för
     * utvecklingsmaskinen.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE container ADD CONSTRAINT container_kind_check CHECK (kind IN ('boat', 'caravan', 'house', 'car', 'other'))");
        }
    }
};
