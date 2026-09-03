<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 23b · Beroenden mellan förekomster. Se [[Scheman och uppgifter]] §
 * occurrence_dependency och issue 23 § Beslut 1.
 *
 * Samma form som `schedule_dependency` (issue 23a), andra nycklar: en rad
 * betyder "förekomsten `occurrence_id` väntar på förekomsten
 * `depends_on_occurrence_id`" (§ Beslut 1). Riktningen är den lagrade — B
 * väntar på A skrivs `occurrence_id` = B, `depends_on_occurrence_id` = A.
 *
 * Inga kolumner utöver paret, av samma skäl som 23a: ingen `ulid` (paret
 * identifierar raden och ingen rutt pekar ut en enskild rad), ingen
 * `deleted_at` (raderingen är hård, och historiken — "den här gången väntade
 * impellerbytet på motorservicen" — ska ligga kvar, § Beslut 5), ingen
 * `container_id` (containern härleds ur förekomstens schemas item).
 *
 * Indexen, spegelbilden av 23a:s (§ Beslut 1):
 * - UNIQUE `(occurrence_id, depends_on_occurrence_id)`: samma par får finnas
 *   en gång.
 * - `(depends_on_occurrence_id, occurrence_id)`: läsningen "vad väntar på det
 *   här förekomsten" och den bakåtriktning cykelkontrollen behöver.
 *
 * `ON DELETE RESTRICT` på båda nycklarna: en förekomst som är part i ett
 * beroende kan inte hårdraderas. Förekomster raderas aldrig i M3 (de följer
 * med schemat genom mjukradering), så det syns inte här — men gallringsjobb i
 * senare milstolpar kommer att märka det (§ Att se upp med).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('occurrence_dependency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('schedule_occurrence')->onDelete('restrict');
            $table->foreignId('depends_on_occurrence_id')->constrained('schedule_occurrence')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['occurrence_id', 'depends_on_occurrence_id']);
            $table->index(['depends_on_occurrence_id', 'occurrence_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('occurrence_dependency');
    }
};
