<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue 1055 · `claimed_at` — när en arbetare tog leveransraden. Raden tas i en
 * kort transaktion, HTTP-anropet görs utanför, och utfallet skrivs i en andra
 * kort transaktion; under anropet är det kolumnen som håller andra
 * arbetare borta. Ingen ny status, så CHECK-villkoret rörs inte. Inget index:
 * urvalet använder redan (status, next_attempt_at).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhook_delivery', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('next_attempt_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_delivery', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
