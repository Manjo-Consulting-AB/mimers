<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 114 · Mätningen. Se [[ADR-0043 Tre loggar]] § Mätningen och
 * App\Console\AggregatesUsageMetrics.
 *
 * `audit_log` har inget index på `created_at` ensamt. De tre som finns —
 * `(container_id, created_at)`, `(item_id, created_at)` och
 * `(user_id, created_at)`, de två sista från issue 107 — har alla en annan
 * ledande kolumn, och mätningens nattliga fråga ("vilka dygn har rader sedan
 * den senast räknade dagen?") kan därför inte använda något av dem: den hade
 * blivit en full genomsökning av hela tabellen varje natt.
 *
 * `security_log` fick `(created_at)` redan i sin migrering, av samma skäl som
 * gallringen i issue 115 sveper hela tabellen på `created_at`. Samma index
 * behövs här, både för mätningens gräns och för gallringens frist.
 *
 * Additivt: ett index ändrar ingen rad och ingen fråga som redan fungerar.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
