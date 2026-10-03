<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 682 · Containern sparar en FOKUSPUNKT på sin bild — den del av bilden
 * som ska synas när en yta beskär den. Se [[ADR-0047 Containerns bild]] och
 * fyndet från testarna 2026-10-03 (docs/Design/findings_20261003_b/items.png):
 * hjälten är bred och låg, `object-cover` beskär mitt i bilden, och motivet
 * hamnar utanför. Läsningen och ritningen (`object-position: x% y%`) kommer i
 * nästa issue; här byggs lagringen.
 *
 * **Två kolumner på `container`, inte på `attachment`.** Fokus är hur
 * CONTAINERN visar sin bild, inte en egenskap hos bilagan. Bilagan rensas ändå
 * när bilden byts (App\Actions\Container\SetContainerCover), och en punkt vald
 * på en bild säger ingenting om nästa. Kolumnerna ligger därför på
 * containerraden, efter `cover_attachment_id`.
 *
 * **Båda är NULLBARA, och NULL betyder "mitten".** En container utan vald
 * punkt beskärs som förut. De sätts aldrig av massildelning — App\Models\
 * Container har dem utanför #[Fillable], som `cover_attachment_id` — bara av
 * App\Actions\Container\SetContainerCoverFocus.
 *
 * **Två CHECK-villkor, bara på mysql.** `container_cover_focus_range` håller
 * båda värdena inom 0–100 (procent), och `container_cover_focus_pair` kräver
 * att de är satta TILLSAMMANS: `(cover_focus_x IS NULL) = (cover_focus_y IS
 * NULL)`. Ett ensamt x utan y vore en halv punkt. Villkoren läggs bara på
 * mysql — sqlite kan inte lägga till ett CHECK i en befintlig tabell (samma
 * lucka som 2026_09_27_020000 beskriver) — och formen bevisas av CI:s
 * migreringsjobb (.github/workflows/migreringar.yml) mot MariaDB, samma motor
 * som staging och produktion.
 *
 * **Migreringen är ADDITIV och rör ingen befintlig rad.** Två nullbara
 * kolumner läggs till, och varje rad har dem som NULL — mitten, exakt som
 * förut. Expand/contract: den destruktiva delen hör till en senare release om
 * kolumnerna någonsin ska bort.
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
        Schema::table('container', function (Blueprint $table) {
            $table->unsignedTinyInteger('cover_focus_x')
                ->nullable()
                ->after('cover_attachment_id');

            $table->unsignedTinyInteger('cover_focus_y')
                ->nullable()
                ->after('cover_focus_x');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // BETWEEN ger NULL för en NULL-kolumn, och ett CHECK som får NULL
            // godkänns — raden utan punkt passerar alltså båda villkoren.
            DB::statement('ALTER TABLE container ADD CONSTRAINT container_cover_focus_range CHECK (cover_focus_x BETWEEN 0 AND 100 AND cover_focus_y BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE container ADD CONSTRAINT container_cover_focus_pair CHECK ((cover_focus_x IS NULL) = (cover_focus_y IS NULL))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE container DROP CONSTRAINT container_cover_focus_range');
            DB::statement('ALTER TABLE container DROP CONSTRAINT container_cover_focus_pair');
        }

        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn(['cover_focus_x', 'cover_focus_y']);
        });
    }
};
