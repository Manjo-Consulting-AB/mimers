<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 69 · Laddern och migreringen — M11:s första steg och enda
 * schemaändring. Se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och
 * [[Konton och åtkomst]] § container_access och § invitation.
 *
 * `level` går från två värden (`read`|`write`) till en ladder om fyra
 * (App\Support\Access\AccessLevel::LADDER:
 * `read` < `create` < `write` < `delete`), och `container_access` och
 * `invitation` får `item_id`: FK mot `item`, NULL, ON DELETE RESTRICT.
 * NULL betyder hela containern, precis som i dag — kolumnen finns men
 * skrivs aldrig av den här issuen, så efter migrationen fungerar systemet
 * exakt som före den.
 *
 * Datamigreringen bevarar beteendet EXAKT: `write` blir `delete`. En
 * `write`-innehavare kan i dag radera items och bilagor
 * (App\Http\Controllers\Api\ItemController::destroy() och
 * AttachmentController::destroy() står båda på `update`-grinden, se
 * ADR:ns § Kontext), och ska behålla precis det. `read` står kvar, och
 * `create` och `write` börjar utan innehavare. `invitation` får samma
 * avbildning av samma skäl: en `pending`-inbjudan som skickades i går ska
 * ge samma behörighet vid accept i morgon som den utlovade när den
 * skickades.
 *
 * Uppdateringen körs EFTER att CHECK-villkoret bytts — annars avvisar
 * mysql det nya värdet — och via `DB::table()`, inte Eloquent: raden
 * ändrar inte mening, den byter bara ord för samma mening, så ingen
 * modellhändelse får gå och `updated_at` ska stå still.
 *
 * `down()` vänder avbildningen och är medvetet förlustbringande:
 *
 *   delete → write
 *   write  → write
 *   create → read
 *   read   → read
 *
 * En rundtur på data som fanns FÖRE `up()` lämnar `level` intakt
 * (`read` → `read`, `write` → `delete` → `write`). Rader som skapats EFTER
 * migreringen kan inte återställas troget, eftersom fyra värden inte får
 * plats i två — och `create` degraderas till `read` i stället för `write`:
 * en rollback ska aldrig kunna ge någon MER behörighet än hon hade.
 * `down()` finns för testsviten; migrationer rullas aldrig tillbaka i
 * produktion (AGENTS.md § Databaskonventioner).
 *
 * CHECK-villkoren rörs bara på mysql; sqlite (testsviten) fick dem aldrig
 * och kan inte lägga till dem i efterhand, se förlagan
 * 2026_08_26_000000_create_container_access_table.php. Av samma skäl blir
 * främmande nyckeln på de nya kolumnerna en tyst no-op på sqlite:
 * SQLiteGrammar::compileForeign() genererar ingen SQL för en FK som läggs
 * till i en befintlig tabell. Kolumnen finns och är nullbar överallt;
 * FK:ns form bevisas av .github/workflows/migreringar.yml mot MySQL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('container_access', function (Blueprint $table) {
            // Indexet (item_id, revoked_at) är den väg issue 72:s
            // förvaltningsvy och issue 70:s upplösning läser, se
            // [[Konton och åtkomst]] § container_access.
            $table->foreignId('item_id')->nullable()->after('container_id')->constrained('item')->onDelete('restrict');
            $table->index(['item_id', 'revoked_at']);
        });

        // invitation får INGET motsvarande index: tabellen slås upp på
        // `token_hash`, `(container_id, status)` och `(email, status)`,
        // aldrig på itemet.
        Schema::table('invitation', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('container_id')->constrained('item')->onDelete('restrict');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE container_access DROP CONSTRAINT container_access_level_check');
            DB::statement("ALTER TABLE container_access ADD CONSTRAINT container_access_level_check CHECK (level IN ('read', 'create', 'write', 'delete'))");

            DB::statement('ALTER TABLE invitation DROP CONSTRAINT invitation_level_check');
            DB::statement("ALTER TABLE invitation ADD CONSTRAINT invitation_level_check CHECK (level IN ('read', 'create', 'write', 'delete'))");
        }

        // write -> delete. Ingen Eloquent, ingen updated_at-bumpning.
        DB::table('container_access')->where('level', 'write')->update(['level' => 'delete']);
        DB::table('invitation')->where('level', 'write')->update(['level' => 'delete']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Avbildningen baklänges, och datat först: raderna bär `delete` och
        // `create` ända till dess, och CHECK-villkoret som sätts tillbaka
        // nedan tillåter bara två värden.
        DB::table('container_access')->where('level', 'delete')->update(['level' => 'write']);
        DB::table('container_access')->where('level', 'create')->update(['level' => 'read']);

        DB::table('invitation')->where('level', 'delete')->update(['level' => 'write']);
        DB::table('invitation')->where('level', 'create')->update(['level' => 'read']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE container_access DROP CONSTRAINT container_access_level_check');
            DB::statement("ALTER TABLE container_access ADD CONSTRAINT container_access_level_check CHECK (level IN ('read', 'write'))");

            DB::statement('ALTER TABLE invitation DROP CONSTRAINT invitation_level_check');
            DB::statement("ALTER TABLE invitation ADD CONSTRAINT invitation_level_check CHECK (level IN ('read', 'write'))");
        }

        Schema::table('container_access', function (Blueprint $table) {
            // dropForeign är en no-op på sqlite (ingen FK skapades där), men
            // obligatorisk på mysql: kolumnen kan inte släppas medan FK:n
            // pekar på den.
            $table->dropForeign(['item_id']);
            $table->dropIndex(['item_id', 'revoked_at']);
            $table->dropColumn('item_id');
        });

        Schema::table('invitation', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropColumn('item_id');
        });
    }
};
