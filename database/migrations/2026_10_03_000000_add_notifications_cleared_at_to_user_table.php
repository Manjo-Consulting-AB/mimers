<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 647 · Notisklockan utan rubrik och med Clear. Se
 * [[Konton och åtkomst]] § user och [[M19 Dashboarden]] § 127 (grannkolumnen,
 * som den här följer efter i schemat).
 *
 * **Rensningen är en tidsstämpel, ingen radering.** `notification` är outboxen
 * och ingenting annat ([[ADR-0010 Notisarkitektur]] § Beslut): en DELETE per
 * rad hade tystat ett register över vad som HÄNT, och nästa fråga hade varit
 * varför en notis som en gång skickats inte längre finns. Klockan frågar i
 * stället "vad har skapats sedan jag rensade den senast?" — ett tal ur en
 * kolumn, och samma fråga som `notifications_read_at` redan ställer.
 *
 * **Två tidsstämplar och inte en.** `notifications_read_at` (issue 127) styr
 * SIFFRAN, den här styr LISTAN. Den som rensar har läst allt som låg i listan
 * och vill se en tom panel med en nolla; att bara flytta listans gräns hade
 * lämnat siffran stående över en tom yta. Därför sätter skrivningen BÅDA, och
 * kolumnerna förblir åtskilda: en framtida "markera som läst utan att rensa"
 * hade annars inte haft någonstans att bo (se App\Http\Controllers\
 * NotificationInboxController::clear()).
 *
 * **Additiv, och det är kravet och inte en stilfråga** (AGENTS.md
 * § Databaskonventioner): en NULL-bar kolumn på en befintlig tabell. `NULL`
 * betyder "har aldrig rensat" och visar ALLA användarens rader, så en
 * befintlig användare får sin historik orörd — vilket är rätt svar, för hon
 * har inte rensat den. Migreringen rullas aldrig tillbaka i produktion;
 * `down()` finns för testsviten och för en utvecklarmaskin.
 *
 * Kolumnen läggs efter `notifications_read_at`: de två hör ihop och svarar på
 * samma fråga om samma lista, och ordningen i schemat ska visa det.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->timestamp('notifications_cleared_at')->nullable()->after('notifications_read_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('notifications_cleared_at');
        });
    }
};
