<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3b · Namn på användaren (#51). Lägger till `user.name`, se
 * [[Konton och åtkomst]] § user.
 *
 * Issue 3 utelämnade kolumnen med motiveringen "dokumentet har ingen", se
 * create_user_table-migrationen. Dokumentet har den nu — deltagarlistan
 * (issue 9c) behöver en läsbar identitet för den som delar en container,
 * annars är e-postadressen det enda alternativet, och den byter en
 * integritetsvinst mot en integritetsförlust.
 *
 * Kolumnen är `VARCHAR(255) NOT NULL` — inget namn är lika obligatoriskt
 * som en e-postadress. Tre steg i EN migration, inte tre releaser:
 *
 * 1. Lägg till kolumnen nullbar (annars kan steg 1 inte köras — det finns
 *    redan rader).
 * 2. Backfill: `name = email` för befintliga rader. Adressen är ett dåligt
 *    namn, men det är allt vi vet om en användare som registrerade sig
 *    innan fältet fanns. Det syns bara tills hon byter det i kontovyerna
 *    (M10) — kontona döps INTE om automatiskt här, se
 *    CreatesUserWithPersonalAccount § Beslut 4.
 * 3. `->nullable(false)->change()` — kolumnen blir NOT NULL.
 *
 * Det här är INTE ett brott mot expand/contract i AGENTS.md §
 * Databaskonventioner: den regeln finns för att gammal kod inte ska möta
 * ett nytt schema, men `deploy/deploy.sh` kör `php artisan down` FÖRE
 * `migrate --force` och flippar symlänken före `up`, se [[Pipeline]] §
 * `deploy/deploy.sh` — "Migrationerna körs innan flippen, medan ingen
 * trafik finns." Det finns alltså inget fönster där gammal kod (som inte
 * skickar `name`) kan skriva en rad utan namn mellan steg 1 och steg 3.
 *
 * `->change()` fungerar utan doctrine/dbal i den här Laravel-versionen,
 * och sqlite (testsviten) bygger om tabellen åt oss.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->string('name')->nullable()->after('ulid');
        });

        DB::table('user')->whereNull('name')->update([
            'name' => DB::raw('email'),
        ]);

        Schema::table('user', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
