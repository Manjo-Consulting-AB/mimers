<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 232 · Listan och statusen i schemat. Se [[ADR-0052 Uppgifternas
 * listor och uppgifter utan datum]] § 1 och § 4 och [[Scheman och
 * uppgifter]] § schedule och § schedule_occurrence.
 *
 * Additiv: en kolumn per tabell och ett vidgat CHECK-villkor. Ingen rad
 * ändrar innebörd — varje befintlig förekomst får `gtd_list = next` genom
 * kolumnens förval (ADR-0052 § 4: ett tekniskt värde och ingen bedömning),
 * och varje befintligt schema får `default_gtd_list = next` för att
 * användaren redan har bearbetat dem. Statusen rörs inte; det finns inga
 * rader med `in_progress`.
 *
 * `default_gtd_list` är NULLBAR och har inget kolumnförval: en ny regel får
 * sin lista först när koden i issue 235 bestämt den ur ADR-0052 § 2. Att
 * sätta `next` som DEFAULT hade flyttat den regeln in i databasen.
 *
 * CHECK-villkoren läggs bara på mysql, som i
 * 2026_09_03_000000_create_schedule_occurrence_table.php: sqlite (testsviten)
 * saknar ALTER TABLE ... DROP CONSTRAINT. Villkoret på `status` måste släppas
 * innan det nya sätts — samma två steg och samma skäl som i förlagan
 * 2026_09_21_000000_rename_item_link_relation_to_related.php.
 *
 * Migrationer rullas aldrig tillbaka i produktion (AGENTS.md §
 * Databaskonventioner); `down()` är för utvecklingsmaskinen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->string('gtd_list', 10)->default('next')->after('status');
        });

        Schema::table('schedule', function (Blueprint $table) {
            $table->string('default_gtd_list', 10)->nullable()->after('is_active');
        });

        // Varje befintligt schema har redan bearbetats av användaren och får
        // `next` (ADR-0052 § 4). Frågebyggaren och inte modellen, så
        // `updated_at` står stilla.
        DB::table('schedule')->update(['default_gtd_list' => 'next']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE schedule_occurrence DROP CONSTRAINT schedule_occurrence_status_check');
            DB::statement("ALTER TABLE schedule_occurrence ADD CONSTRAINT schedule_occurrence_status_check CHECK (status IN ('open', 'in_progress', 'completed', 'skipped'))");
            DB::statement("ALTER TABLE schedule_occurrence ADD CONSTRAINT schedule_occurrence_gtd_list_check CHECK (gtd_list IN ('inbox', 'next', 'waiting', 'someday'))");
            DB::statement("ALTER TABLE schedule ADD CONSTRAINT schedule_default_gtd_list_check CHECK (default_gtd_list IN ('next', 'someday'))");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Bara för utvecklingsmaskinen (AGENTS.md § Databaskonventioner). Ett
     * schema som hunnit få `default_gtd_list` förlorar den, och en förekomst
     * med `in_progress` hade fallit på det gamla statusvillkoret — därför
     * släpps det innan det gamla sätts tillbaka.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE schedule_occurrence DROP CONSTRAINT schedule_occurrence_status_check');
            DB::statement('ALTER TABLE schedule_occurrence DROP CONSTRAINT schedule_occurrence_gtd_list_check');
            DB::statement('ALTER TABLE schedule DROP CONSTRAINT schedule_default_gtd_list_check');
            DB::statement("ALTER TABLE schedule_occurrence ADD CONSTRAINT schedule_occurrence_status_check CHECK (status IN ('open', 'completed', 'skipped'))");
        }

        Schema::table('schedule', function (Blueprint $table) {
            $table->dropColumn('default_gtd_list');
        });

        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->dropColumn('gtd_list');
        });
    }
};
