<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 233 · En uppgift utan datum. Se
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3 och
 * [[Scheman och uppgifter]] § schedule_occurrence.
 *
 * `due_at` och `visible_from` får NULL. En uppgift utan datum är själva
 * poängen med Inbox, Next och Someday: en rad med bara en titel ska gå att
 * skapa, och den har inget förfall. Har en förekomst inget `due_at` är
 * `visible_from` också null — glappet räknas ur förfallet.
 *
 * INGEN RAD ÄNDRAS. Att göra en NOT NULL-kolumn nullbar är en vidgning:
 * varje värde som fick plats förut får plats nu, och MariaDB gör det som en
 * metadataändring. Ännu finns ingen väg in för användaren — valideringen
 * kräver fortfarande `anchor_date` — så raderna skapas bara i proven, med
 * fabriken (issuens Mål). Vägen in öppnas i issue 236 (webben) och 238 (API).
 *
 * INDEXET `(due_at, status)` STÅR KVAR. Det bär todo-listans läsning över
 * alla containers, och en nullbar kolumn i ett index är inget hinder —
 * raden ligger kvar, den sorteras bara sist i sin gren.
 *
 * `down()` sätter tillbaka NOT NULL och är därför förlustbringande så fort en
 * rad står på NULL: den faller på MariaDB. Den finns för testsviten —
 * migrationer rullas aldrig tillbaka i produktion (AGENTS.md
 * § Databaskonventioner).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->date('visible_from')->nullable()->change();
            $table->date('due_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_occurrence', function (Blueprint $table) {
            $table->date('visible_from')->nullable(false)->change();
            $table->date('due_at')->nullable(false)->change();
        });
    }
};
