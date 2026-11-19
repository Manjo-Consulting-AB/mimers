<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 411 · Versionshistorik — inställningarna, se [[ADR-0088
 * Versionshistorik för items]] Riktning 1.
 *
 * - `account.versioning_max_versions`: heltal 0–20, där 0 är av. Förval 3,
 *   även för befintliga rader — skyddet ska finnas innan misstaget sker.
 *   `default(3)` fyller de rader som redan finns när kolumnen läggs till.
 * - `container.versioning_max_versions`: NULL följer kontot, 0 stänger av
 *   historiken i containern. Ett tal får aldrig överstiga kontots, men det
 *   prövas när det sparas och inte av schemat: kontots värde kan sänkas
 *   senare, och då ska containerns rad stå kvar orörd (Riktning 1).
 *
 * Ingen kod kopierar kontots värde till containern — `App\Support\Versioning\
 * VersionLimit::for()` räknar fram det som gäller vid varje skrivning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->unsignedTinyInteger('versioning_max_versions')->default(3)->after('status');
        });

        Schema::table('container', function (Blueprint $table) {
            $table->unsignedTinyInteger('versioning_max_versions')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn('versioning_max_versions');
        });

        Schema::table('account', function (Blueprint $table) {
            $table->dropColumn('versioning_max_versions');
        });
    }
};
