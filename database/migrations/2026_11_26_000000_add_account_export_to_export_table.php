<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1701 · Kontoexporten, grunden ([[ADR-0098 Kontoexporten]] § 4). En
 * kontoexport är en exportrad, med `kind = account`:
 *
 *  - `container_id` blir nullbar — en kontoexport gäller ingen container.
 *  - `account_id` är ny, nullbar nyckel till kontot. ON DELETE RESTRICT av
 *    samma skäl som `container_id`; DeleteAccount raderar kontots exporter
 *    först.
 *  - CHECK-villkoret för `kind` vidgas med `account`, och ett nytt villkor
 *    säger att `account`-rader har `account_id` och ingen container, och att
 *    alla andra rader har en container och inget `account_id`.
 *  - Index `(account_id, created_at)` bär listan över kontots exporter.
 *
 * Villkoren finns bara på mysql, som övriga CHECK-villkor i schemat.
 * Befintliga rader rörs inte: de är `zip` eller `binder` med container.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export', function (Blueprint $table) {
            $table->unsignedBigInteger('container_id')->nullable()->change();
        });

        Schema::table('export', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('container_id')->constrained('account')->onDelete('restrict');
            $table->index(['account_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE export DROP CONSTRAINT export_kind_check');
            DB::statement("ALTER TABLE export ADD CONSTRAINT export_kind_check CHECK (kind IN ('zip', 'binder', 'account'))");
            DB::statement("ALTER TABLE export ADD CONSTRAINT export_account_check CHECK ((kind = 'account' AND container_id IS NULL AND account_id IS NOT NULL) OR (kind <> 'account' AND container_id IS NOT NULL AND account_id IS NULL))");
        }
    }

    /**
     * `container_id` återställs inte till NOT NULL: efter en kontoexport kan
     * tabellen innehålla rader utan container, och en nedrullning ska inte
     * fälla på dem.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE export DROP CONSTRAINT export_account_check');
            DB::statement('ALTER TABLE export DROP CONSTRAINT export_kind_check');
            DB::statement("ALTER TABLE export ADD CONSTRAINT export_kind_check CHECK (kind IN ('zip', 'binder'))");
        }

        Schema::table('export', function (Blueprint $table) {
            // Nyckeln först: det sammansatta indexet stöder den, och MySQL
            // nekar att släppa ett index som en främmande nyckel behöver.
            $table->dropForeign(['account_id']);
            $table->dropIndex(['account_id', 'created_at']);
            $table->dropColumn('account_id');
        });
    }
};
