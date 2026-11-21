<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 433 · [[ADR-0090 Dashboardens hjälte]] § 5 — containerns bild får en
 * ZOOM bredvid fokuspunkten, i samma form som profilbildens `avatar_zoom`
 * (2026_11_15_000000). Punkten säger vad som ska synas; zoomen säger hur
 * mycket som ska skäras bort runt den.
 *
 * **En kolumn på `container`, inte på `attachment`.** Zoomen är hur
 * CONTAINERN visar sin bild, inte en egenskap hos bilagan — samma resonemang
 * som `cover_focus_x`/`cover_focus_y` (2026_10_04_000000) och personens
 * `avatar_zoom`. Originalet lagras orört; utsnittet ritas med CSS, så
 * ändringen skapar ingen ny fil och påverkar inte kvoten.
 *
 * **NULL betyder 100 %, alltså dagens bild.** Kolonnen är nullbar och sätts
 * aldrig av massildelning — App\Models\Container har den utanför #[Fillable] —
 * bara av App\Actions\Container\SetContainerCoverFocus. Bytet och
 * borttagningen av bilden nollställer den tillsammans med fokuspunkten
 * (App\Actions\Container\SetContainerCover och RemoveContainerCover): en zoom
 * vald på en bild säger ingenting om nästa.
 *
 * **Ett CHECK-villkor, bara på mysql.** `container_cover_zoom_range` håller
 * zoomen inom 100–300 eller NULL. Villkoret läggs bara på mysql — sqlite kan
 * inte lägga till ett CHECK i en befintlig tabell (samma lucka som
 * 2026_10_04_000000 beskriver) — och formen bevisas av CI:s migreringsjobb
 * (.github/workflows/migreringar.yml) mot MariaDB, samma motor som staging och
 * produktion.
 *
 * **Migreringen är ADDITIV och rör ingen befintlig rad.** En nullbar kolumn
 * läggs till, och varje rad har den som NULL — 100 %, exakt som förut.
 * Expand/contract: den destruktiva delen hör till en senare release om
 * kolumnen någonsin ska bort.
 *
 * `down()` släpper villkoret och kolumnen. Den finns för testsviten —
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
        Schema::table('container', function (Blueprint $table) {
            $table->unsignedSmallInteger('cover_zoom')
                ->nullable()
                ->after('cover_focus_y');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // BETWEEN ger NULL för en NULL-kolumn, och ett CHECK som får NULL
            // godkänns — raden utan zoom passerar villkoret.
            DB::statement('ALTER TABLE container ADD CONSTRAINT container_cover_zoom_range CHECK (cover_zoom IS NULL OR cover_zoom BETWEEN 100 AND 300)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE container DROP CONSTRAINT container_cover_zoom_range');
        }

        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn('cover_zoom');
        });
    }
};
