<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 382 · Profilbildens BESKÄRNING — en fokuspunkt och en zoom på
 * personens bild, så att ansiktet hamnar i mitten av den runda avataren i
 * stället för att bildens mitt skärs ut. Se [[ADR-0076 Profilbilden]] § 7 och
 * [[M59 Profilbilden]] § 382.
 *
 * **Tre kolumner på `user`, inte på `attachment`.** Fokus och zoom är hur
 * PERSONEN visar sin bild, inte en egenskap hos bilagan — samma resonemang
 * som för containerns `cover_focus_x/y` (issue 682, migration
 * 2026_10_04_000000). Originalet lagras orört; utsnittet ritas med CSS, så
 * ändringen skapar ingen ny fil och påverkar inte kvoten.
 *
 * **Paret sätts TILLSAMMANS, och NULL betyder mitten.** `avatar_focus_x` och
 * `avatar_focus_y` är procent 0–100 av originalet, och `avatar_zoom` är
 * procent 100–300. `NULL` i alla tre betyder "som förut": mitten och 100 %.
 * De sätts aldrig av massildelning — App\Models\User har dem utanför
 * #[Fillable] — bara av App\Actions\User\SetUserAvatarCrop. Bytet och
 * borttagningen av bilden nollställer dem (App\Actions\User\SetUserAvatar
 * och RemoveUserAvatar): en punkt vald på en bild säger ingenting om nästa.
 *
 * **Tre CHECK-villkor, bara på mysql.** `user_avatar_focus_range` håller
 * punkten inom 0–100, `user_avatar_zoom_range` håller zoomen inom 100–300
 * eller NULL, och `user_avatar_focus_pair` kräver att x och y är satta
 * tillsammans: `(avatar_focus_x IS NULL) = (avatar_focus_y IS NULL)`.
 * Villkoren läggs bara på mysql — sqlite kan inte lägga till ett CHECK i en
 * befintlig tabell (samma lucka som 2026_10_04_000000 beskriver) — och
 * formen bevisas av CI:s migreringsjobb (.github/workflows/migreringar.yml)
 * mot MariaDB, samma motor som staging och produktion.
 *
 * **Migreringen är ADDITIV och rör ingen befintlig rad.** Tre nullbara
 * kolumner läggs till, och varje rad har dem som NULL — mitten och 100 %,
 * exakt som förut. Expand/contract: den destruktiva delen hör till en
 * senare release om kolumnerna någonsin ska bort.
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
            $table->unsignedTinyInteger('avatar_focus_x')
                ->nullable()
                ->after('avatar_attachment_id');

            $table->unsignedTinyInteger('avatar_focus_y')
                ->nullable()
                ->after('avatar_focus_x');

            $table->unsignedSmallInteger('avatar_zoom')
                ->nullable()
                ->after('avatar_focus_y');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // BETWEEN ger NULL för en NULL-kolumn, och ett CHECK som får NULL
            // godkänns — en rad utan utsnitt passerar alla tre villkoren.
            DB::statement('ALTER TABLE user ADD CONSTRAINT user_avatar_focus_range CHECK (avatar_focus_x BETWEEN 0 AND 100 AND avatar_focus_y BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE user ADD CONSTRAINT user_avatar_zoom_range CHECK (avatar_zoom IS NULL OR avatar_zoom BETWEEN 100 AND 300)');
            DB::statement('ALTER TABLE user ADD CONSTRAINT user_avatar_focus_pair CHECK ((avatar_focus_x IS NULL) = (avatar_focus_y IS NULL))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE user DROP CONSTRAINT user_avatar_focus_pair');
            DB::statement('ALTER TABLE user DROP CONSTRAINT user_avatar_zoom_range');
            DB::statement('ALTER TABLE user DROP CONSTRAINT user_avatar_focus_range');
        }

        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn(['avatar_focus_x', 'avatar_focus_y', 'avatar_zoom']);
        });
    }
};
