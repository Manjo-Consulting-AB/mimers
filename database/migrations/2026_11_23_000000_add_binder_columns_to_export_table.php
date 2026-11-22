<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 445 · PDF-pärmen, grunden ([[ADR-0091 PDF-pärmen]]). En pärm är en
 * exportrad: samma lista, samma nedladdning, samma sju dagar. Tre kolumner
 * bär skillnaden:
 *
 *  - `kind` — `zip` | `binder`, en sluten mängd med CHECK-villkor på mysql
 *    som `status`. Befintliga rader blir `zip` genom standardvärdet.
 *  - `item_id` — nullbar nyckel till item, för en pärm som gäller ett
 *    delträd. ON DELETE RESTRICT av samma skäl som `container_id`: raden är
 *    en beställning någon gjort och ska inte tyst peka på intet.
 *  - `options` — JSON med pärmens val (`sections`, `costs`); null betyder
 *    förvalen.
 *
 * Additiv migration (expand): inget befintligt skrivs om.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export', function (Blueprint $table) {
            $table->string('kind', 20)->default('zip')->after('container_id');
            $table->foreignId('item_id')->nullable()->after('kind')->constrained('item')->onDelete('restrict');
            $table->json('options')->nullable()->after('item_id');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE export ADD CONSTRAINT export_kind_check CHECK (kind IN ('zip', 'binder'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE export DROP CONSTRAINT export_kind_check');
        }

        Schema::table('export', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->dropColumn(['kind', 'options']);
        });
    }
};
