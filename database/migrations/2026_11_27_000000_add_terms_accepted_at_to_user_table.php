<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1691 · M91. Tidpunkten då användaren bekräftade att hen är 18 eller
 * äldre och godkände villkoren vid registreringen. NULL betyder att
 * användaren registrerade sig före rutan — befintliga rader fylls inte i.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
