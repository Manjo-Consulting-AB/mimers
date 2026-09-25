<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 134 · Växeln för framtida uppgifter. Se [[M21 Uppgifterna i
 * vardagen]] § 134.
 *
 * **Växeln följer användaren och inte webbläsaren.** `show_upcoming_tasks`
 * är personens val, och den bor därför på `user` och inte i `localStorage`
 * eller i en querysträng: ett val som bara gäller den här fliken är inget val
 * hon gjort, och en adressparameter hade gjort listan olika beroende på vem
 * som klistrade in länken (samma linje som `notifications_read_at` i issue
 * 127).
 *
 * **`DEFAULT TRUE` ger dagens beteende.** Todo-listan visar i dag alla
 * synliga uppgifter, och en befintlig användare ska inte se någon skillnad
 * förrän hon själv slår av växeln. Är flaggan sann lägger
 * App\Actions\Schedule\ListTodo inget extra villkor på frågan; är den falsk
 * begränsas listan till det som är aktuellt nu — försenat och i dag, se
 * `ScheduleOccurrence::scopeDueTodayOrEarlier()`.
 *
 * **Additiv, och det är kravet och inte en stilfråga** (AGENTS.md
 * § Databaskonventioner): en kolumn med ett standardvärde på en befintlig
 * tabell, utan att en enda rad skrivs om. Migreringen rullas aldrig tillbaka
 * i produktion; `down()` finns för testsviten och för en utvecklarmaskin.
 *
 * Kolumnen läggs efter `notifications_read_at`: båda är personliga
 * inställningar som styr vad användaren möts av, och ordningen i schemat ska
 * visa att de hör ihop.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->boolean('show_upcoming_tasks')->default(true)->after('notifications_read_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('show_upcoming_tasks');
        });
    }
};
