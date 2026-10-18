<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 301 · Itemets ikon i datamodellen. Se [[ADR-0060 Itemets ikon]]
 * § 7 och [[M35 Itemets ikon och kartans kort]] § 301.
 *
 * Två nullbara kolumner på `item`:
 *
 * - `icon` är ett Lucide-namn i kebab-case. Servern kontrollerar FORMATET
 *   (gemener, siffror och bindestreck, högst 64 tecken) och aldrig att
 *   ikonen finns — listan bor i klientens bygge (ADR-0060 § 4).
 * - `icon_color` är en nyckel ur paletten i `Item::ICON_COLORS`, inte en
 *   hex-kod (ADR-0060 § 5).
 *
 * Ingen av dem får ett index och ingen får ett CHECK-villkor: varken
 * namnet eller färgen söks eller filtreras på, och paletten valideras i
 * applikationen som `kind` ([[ADR-0036 Containerns art]]). Kolumnerna är
 * itemets egen uppgift och läggs bland itemets egna fält, före historiken.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->string('icon', 64)->nullable()->after('position_note');
            $table->string('icon_color', 16)->nullable()->after('icon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->dropColumn(['icon', 'icon_color']);
        });
    }
};
