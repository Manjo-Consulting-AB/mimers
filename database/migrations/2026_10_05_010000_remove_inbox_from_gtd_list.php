<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 244 (M27) · Inbox är en plats och inte en GTD-lista. Se
 * [[ADR-0054 Inboxen]] § 5 och § Konsekvenser, och [[Scheman och uppgifter]]
 * § schedule_occurrence.
 *
 * `inbox` utgår som värde i `gtd_list` och blir i stället en härledd vy:
 * förekomster vars schema ligger på användarens inbox-item. En sådan
 * förekomst har `gtd_list = null`, och en förekomst någon annanstans har ett
 * värde. Kolumnen blir därför NULLBAR och tappar sitt förval — förvalet
 * `next` var `inbox`-modellens, och ett förval som ingen längre vill ha är en
 * sanning som glider isär från koden.
 *
 * **Två omskrivningar, båda uttryckligen i ADR-0054 § Konsekvenser.**
 * Förekomster med `gtd_list = 'inbox'` får `next` — de är skapade på ett
 * riktigt item och ska ha en lista. `schedule.default_gtd_list` kontrolleras
 * också: kolumnens CHECK-villkor tillåter bara `next` och `someday`, men
 * migreringen skriver om varje rad med ett annat värde än just de två (och
 * null) till `next` i stället för att lita på villkoret. Bara staging har
 * sådana rader, eftersom v0.20.0 inte är släppt till produktion.
 *
 * **Frågebyggaren och inte modellen** (samma rad som
 * 2026_10_04_010000_add_gtd_list_to_schedule_occurrence_and_schedule.php):
 * `updated_at` står stilla, och migreringen rör bara rader som faktiskt
 * behöver skrivas om.
 *
 * **CHECK-villkoren läggs bara på mysql**, som i tidigare migreringar:
 * sqlite (testsviten) saknar `ALTER TABLE ... DROP CONSTRAINT`. Det gamla
 * villkoret måste släppas innan kolumnen ändras — `inbox` får inte finnas
 * kvar i det — och det nya sätts efter.
 *
 * Migrationer rullas aldrig tillbaka i produktion (AGENTS.md §
 * Databaskonventioner); `down()` är för utvecklingsmaskinen och kan inte
 * återskapa vilka förekomster som låg i inboxen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Varje förekomst som låg i inboxen skapades på ett riktigt item och
        // hör hemma i `next` (ADR-0054 § Konsekvenser).
        DB::table('schedule_occurrence')
            ->where('gtd_list', 'inbox')
            ->update(['gtd_list' => 'next']);

        // `waiting` och `inbox` var aldrig giltiga förval, men kolumnen är
        // äldre än regeln: varje rad utanför `next`/`someday`/null blir
        // `next`, utan att lita på CHECK-villkoret (ADR-0054 § Konsekvenser).
        DB::table('schedule')
            ->whereNotNull('default_gtd_list')
            ->whereNotIn('default_gtd_list', ['next', 'someday'])
            ->update(['default_gtd_list' => 'next']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE schedule_occurrence DROP CONSTRAINT schedule_occurrence_gtd_list_check');
        }

        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->string('gtd_list', 10)->nullable()->default(null)->change();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE schedule_occurrence ADD CONSTRAINT schedule_occurrence_gtd_list_check CHECK (gtd_list IN ('next', 'waiting', 'someday'))");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Bara för utvecklingsmaskinen (AGENTS.md § Databaskonventioner).
     * Kolumnen blir icke-nullbar igen med förvalet `next` — raderna i inboxen
     * går inte att skilja från dem i `next` efter `up()`, så `inbox` kommer
     * inte tillbaka.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE schedule_occurrence DROP CONSTRAINT schedule_occurrence_gtd_list_check');
        }

        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->string('gtd_list', 10)->default('next')->nullable(false)->change();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE schedule_occurrence ADD CONSTRAINT schedule_occurrence_gtd_list_check CHECK (gtd_list IN ('inbox', 'next', 'waiting', 'someday'))");
        }
    }
};
