<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue 1693 · M91: `confidential` säger om en myndighet ålagt oss tystnad om
 * spärren. Bara en tystnadsbelagd spärr utelämnas ur svaret på art. 15
 * (GDPR art. 23). Befintliga rader får `false`: ingen har hittills angett
 * något åläggande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_hold', function (Blueprint $table) {
            $table->boolean('confidential')->default(false)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('legal_hold', function (Blueprint $table) {
            $table->dropColumn('confidential');
        });
    }
};
