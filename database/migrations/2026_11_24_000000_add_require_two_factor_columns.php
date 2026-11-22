<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1520 · ADR-0092 Tvåfaktor som krav för delade containrar — kravet i
 * datamodellen. Kontot kan kräva tvåfaktor för ALLA sina containrar, och en
 * enskild container kan kräva den för sig själv; en container kräver den om
 * någon av de två flaggorna är på (Container::requiresTwoFactor()).
 *
 * Migreringen är ADDITIV: två BOOLEAN NOT NULL DEFAULT false, så varje
 * befintlig rad bär false och inget beteende ändras. `down()` finns för
 * testsviten — migrationer rullas aldrig tillbaka i produktion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });

        Schema::table('container', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });

        Schema::table('account', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });
    }
};
