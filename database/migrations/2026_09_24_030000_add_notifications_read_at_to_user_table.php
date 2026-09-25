<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 127 · Notisklockan. Se [[Notiser]] § notification och
 * [[M19 Dashboarden]] § 127.
 *
 * **"Oläst" är en tidsstämpel på användaren, inte en kolumn per rad.**
 * `notification` är outboxen och ingenting annat ([[ADR-0010 Notisarkitektur]]
 * § Beslut): en `read_at` på varje rad hade lagt en läsestatus i ett register
 * över vad som HÄNT, och varje ny notistyp hade ärvt frågan om den alls kan
 * läsas. Klockan frågar i stället "vad har skapats sedan jag öppnade den
 * senast?" — ett tal ur en kolumn, och en fråga som redan har sitt index:
 * `(user_id, created_at)`.
 *
 * **Klockan är ingen kanal** (issue 127 § Beslut). Den här kolumnen styr
 * varken leverans, preferenser eller tysta timmar — `available_at` på raden
 * äger det — och `POST /notifications/read` skapar ingen
 * `notification_delivery`-rad. Utan den skillnaden hade klockan blivit en
 * fjärde kanal i en arkitektur som har tre.
 *
 * **Additiv, och det är kravet och inte en stilfråga** (AGENTS.md
 * § Databaskonventioner): en NULL-bar kolumn på en befintlig tabell. `NULL`
 * betyder "har aldrig öppnat klockan" och räknar ALLA användarens rader, så en
 * befintlig användare får sin historik oläst en gång — vilket är rätt svar,
 * för hon har inte läst den i den här ytan. Migreringen rullas aldrig tillbaka
 * i produktion; `down()` finns för testsviten och för en utvecklarmaskin.
 *
 * Kolumnen läggs efter `last_active_at`: båda svarar på "när gjorde personen
 * det här senast", och ordningen i schemat ska visa att de hör ihop.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->timestamp('notifications_read_at')->nullable()->after('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('notifications_read_at');
        });
    }
};
