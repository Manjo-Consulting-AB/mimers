<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 314 · Containerns ikon i datamodellen. Se [[ADR-0064 Containerns
 * ikon]] § 1 och 2 och [[M38 Containerns ikon]] § 314.
 *
 * Två nullbara kolumner på `container`:
 *
 * - `icon` är ett Lucide-namn i kebab-case. Servern kontrollerar FORMATET
 *   (gemener, siffror och bindestreck, högst 64 tecken) och aldrig att
 *   ikonen finns — listan bor i klientens bygge ([[ADR-0060 Itemets ikon]]
 *   § 4).
 * - `icon_color` är en nyckel ur paletten i `Item::ICON_COLORS`, inte en
 *   hex-kod ([[ADR-0060 Itemets ikon]] § 5).
 *
 * Ingen av dem får ett index och ingen får ett CHECK-villkor: varken namnet
 * eller färgen söks eller filtreras på, och paletten valideras i
 * applikationen som `kind` ([[ADR-0036 Containerns art]]). Kolumnerna ligger
 * efter fokuspunkten på containerns bild, bland containerns egna
 * presentationsfält.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->string('icon', 64)->nullable()->after('cover_focus_y');
            $table->string('icon_color', 16)->nullable()->after('icon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn(['icon', 'icon_color']);
        });
    }
};
