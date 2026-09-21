<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 88 · Containern får en beskrivning. Se [[ADR-0039 Containerns
 * översikt]] § Beslut.
 *
 * Ett ENDA fritextfält, nullbart, vid sidan av `name` och `kind`. Mockupens
 * formaterade underrubrik — modell och årtal med en punkt emellan — går inte
 * att generera ur fält som inte finns, och de strukturerade fälten (modell,
 * årtal, tillverkare) byggs därför inte: de hade varit domänen inbyggd i
 * containern, samma fel som artens värdelista i [[ADR-0036 Containerns art]]
 * och förbjudet av [[ADR-0033 Produktens omfång]].
 *
 * Kolumnen är TEXT och inte VARCHAR, som `item.description`, och `null` är
 * värdet för "ingen beskrivning" — en tom sträng hade varit precis den
 * sentinel [[ADR-0004 Fria taggar och kategorier]] vill undvika. Fältet är
 * FRIVILLIGT: att kräva en beskrivning vid skapandet är att ställa en fråga
 * användaren ännu inte kan svara på, samma resonemang som gjorde `kind`
 * frivillig i issue 84.
 *
 * Ingen datamigrering av befintliga rader: de har ingen beskrivning, och
 * `null` är exakt vad det betyder.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->text('description')->nullable()->after('kind');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
