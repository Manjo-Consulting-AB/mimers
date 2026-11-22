<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 434 · Personens hjältebild — en egen bild för dashboardens hjälte, med
 * samma regler som profilbilden. Se [[ADR-0090 Dashboardens hjälte]] § 2 och
 * [[Konton och åtkomst]] § user.
 *
 * **Kolumnerna ligger på `user`, inte på `attachment`.** Bilden är personens
 * och pekaren är en preferens, precis som `avatar_attachment_id`: `hero_attachment_id`
 * är nullbar FK mot `attachment` med **ON DELETE RESTRICT** som husets
 * nycklar. Att bilagan är personens EGEN kan ett CHECK-villkor inte uttrycka,
 * så det upprätthålls i App\Actions\User\SetUserHero, som för profilbilden.
 * `PurgeAttachment` nollställer pekaren innan bilagan raderas hårt — annars
 * hade RESTRICT fällt varje gallring av en hjältebild.
 *
 * **Fokus och zoom följer profilbildens form.** `hero_focus_x` och
 * `hero_focus_y` är TINYINT UNSIGNED NULL (procent 0–100), `hero_zoom` är
 * SMALLINT UNSIGNED NULL (procent 100–300). NULL i alla tre betyder mitten och
 * 100 %, och de nollställs av byte och borttagning. Kolumnerna sätts aldrig av
 * massildelning — de ligger utanför App\Models\User:s #[Fillable] — bara av
 * App\Actions\User\SetUserHeroCrop.
 *
 * **Tre CHECK-villkor, bara på mysql.** `user_hero_focus_range` håller punkten
 * inom 0–100, `user_hero_zoom_range` håller zoomen inom 100–300 eller NULL,
 * och `user_hero_focus_pair` kräver att x och y sätts tillsammans. Samma form
 * som `user_avatar_*` (migration 2026_11_15_000000). Villkoren läggs bara på
 * mysql — sqlite kan inte lägga till ett CHECK i en befintlig tabell — och
 * formen bevisas av CI:s migreringsjobb (.github/workflows/migreringar.yml)
 * mot MariaDB, samma motor som staging och produktion.
 *
 * **Migreringen är ADDITIV och rör ingen befintlig rad.** Fyra nullbara
 * kolumner läggs till, och varje rad har dem som NULL — ingen hjältebild,
 * mitten och 100 %. Expand/contract: den destruktiva delen hör till en senare
 * release om kolumnerna någonsin ska bort.
 *
 * `down()` släpper villkoren och kolumnerna. Den finns för testsviten —
 * migrationer rullas aldrig tillbaka i produktion (AGENTS.md
 * § Databaskonventioner).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->foreignId('hero_attachment_id')
                ->nullable()
                ->after('avatar_zoom')
                ->constrained('attachment')
                ->onDelete('restrict');

            $table->unsignedTinyInteger('hero_focus_x')
                ->nullable()
                ->after('hero_attachment_id');

            $table->unsignedTinyInteger('hero_focus_y')
                ->nullable()
                ->after('hero_focus_x');

            $table->unsignedSmallInteger('hero_zoom')
                ->nullable()
                ->after('hero_focus_y');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // BETWEEN ger NULL för en NULL-kolumn, och ett CHECK som får NULL
            // godkänns — en rad utan utsnitt passerar alla tre villkoren.
            DB::statement('ALTER TABLE user ADD CONSTRAINT user_hero_focus_range CHECK (hero_focus_x BETWEEN 0 AND 100 AND hero_focus_y BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE user ADD CONSTRAINT user_hero_zoom_range CHECK (hero_zoom IS NULL OR hero_zoom BETWEEN 100 AND 300)');
            DB::statement('ALTER TABLE user ADD CONSTRAINT user_hero_focus_pair CHECK ((hero_focus_x IS NULL) = (hero_focus_y IS NULL))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE user DROP CONSTRAINT user_hero_focus_pair');
            DB::statement('ALTER TABLE user DROP CONSTRAINT user_hero_zoom_range');
            DB::statement('ALTER TABLE user DROP CONSTRAINT user_hero_focus_range');
        }

        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['hero_attachment_id']);
            $table->dropColumn(['hero_attachment_id', 'hero_focus_x', 'hero_focus_y', 'hero_zoom']);
        });
    }
};
