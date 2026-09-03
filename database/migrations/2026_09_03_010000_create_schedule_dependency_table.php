<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 23a · Beroenden mellan scheman. Se [[Scheman och uppgifter]] §
 * occurrence_dependency och issue 23 § Beslut 1.
 *
 * En regel på schemanivå: "B beror på A" skrivs `schedule_id` = B och
 * `depends_on_schedule_id` = A (§ Beslut 2). En egen tabell i stället för
 * "samma tabellstruktur fast på schemanivå" (§ Beslut 1): två tabeller med
 * var sitt deklarerade nyckelpar är enklare att läsa och gör det omöjligt att
 * av misstag koppla ett schema till en förekomst.
 *
 * Inga kolumner utöver paret: ingen `ulid` (paret identifierar raden och
 * ingen rutt pekar ut en enskild rad — samma resonemang som `item_link`,
 * issue 14 § Beslut 1), ingen `deleted_at` (raderingen är hård, § Beslut 7),
 * ingen `container_id` (containern härleds ur schemats item, som aldrig byter
 * item eller container).
 *
 * Indexen (§ Beslut 1):
 * - UNIQUE `(schedule_id, depends_on_schedule_id)`: samma par får finnas en
 *   gång. Dubbletten avvisas i valideringen (Beslut 9); unikheten gör ett
 *   fel till ett skrivfel i stället för en tyst dubblett.
 * - `(depends_on_schedule_id, schedule_id)`: läsningen "vad beror på det här
 *   schemat" och den bakåtriktning cykelkontrollen behöver (§ Beslut 6) —
 *   båda riktningarna läses, så båda indexeras.
 *
 * `ON DELETE RESTRICT` på båda nycklarna (§ Beslut 7): ett schema som är part
 * i ett beroende kan inte hårdraderas. Scheman mjukraderas alltid (issue 21
 * § Beslut 9), och en mjukradering är en UPDATE — raderna ligger kvar.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_dependency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedule')->onDelete('restrict');
            $table->foreignId('depends_on_schedule_id')->constrained('schedule')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['schedule_id', 'depends_on_schedule_id']);
            $table->index(['depends_on_schedule_id', 'schedule_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_dependency');
    }
};
