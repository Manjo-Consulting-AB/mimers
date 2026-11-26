<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 509 · Fältet `boolean`, se [[ADR-0099 Kryssrutefält]] och
 * [[ADR-0089 Egna fält på items]] § Riktning 1 och 9.
 *
 * Lägger kolumnen `item_field_value.value_boolean` och dess index, och
 * släpper in `boolean` i typlistan. Ett ikryssat fält är en rad med
 * `value_boolean = 1`; ett fält som inte är ikryssat har ingen rad, så
 * kolumnen är antingen `1` eller `NULL` och `0` skrivs aldrig.
 *
 * CHECK-villkoret finns bara på mysql — sqlite (testsviten) hoppar över det,
 * samma drivrutinsgrening som 2026_11_15_026000_create_item_field_tables.php.
 *
 * Migrationen lägger bara till, så expand/contract behövs inte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_field_value', function (Blueprint $table) {
            $table->boolean('value_boolean')->nullable()->after('value_date');
            $table->index(['item_field_id', 'value_boolean']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_field DROP CONSTRAINT item_field_type_check');
            DB::statement("ALTER TABLE item_field ADD CONSTRAINT item_field_type_check CHECK (type IN ('text', 'number', 'date', 'boolean'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_field DROP CONSTRAINT item_field_type_check');
            DB::statement("ALTER TABLE item_field ADD CONSTRAINT item_field_type_check CHECK (type IN ('text', 'number', 'date'))");
        }

        Schema::table('item_field_value', function (Blueprint $table) {
            $table->dropIndex(['item_field_id', 'value_boolean']);
            $table->dropColumn('value_boolean');
        });
    }
};
