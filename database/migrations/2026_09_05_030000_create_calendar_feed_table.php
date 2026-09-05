<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 36a · ICS-kalenderfeed, första halvan (nyckeln och ytan). Se
 * [[Notiser]] § ICS-kalenderfeed och App\Http\Controllers\Api\CalendarFeedController.
 *
 * En hemlig prenumerationslänk per container och användare, återkallbar:
 * Apple Calendar eller Google Calendar hämtar feeden på URL:en och ser bara
 * det den här användaren får se. Själva feeden — rutten som svarar med
 * text/calendar — är issue 36b och rör inte den här tabellen mer än genom
 * token_hash-uppslaget och last_fetched_at.
 *
 * `token_hash` är en SHA-256-hex av den slump som bara finns i URL:en i
 * svaret på POST /calendar-feeds, aldrig slumpen själv — därav exakt
 * CHAR(64), se issue 36a § Beslut 5 och förlagan App\Models\Invitation.
 *
 * `ulid` finns därför att raden syns i API:et som en resurs man listar och
 * återkallar (AGENTS.md § Databaskonventioner) — löpnumret lämnar aldrig
 * svaret, och tabellnamnet `calendar_feed` är singular precis som
 * `invitation`, `container` och `user`. Tabellen har ingen tabell för sig i
 * [[Notiser]] — namnet är en lucka i dokumentet, se issue 36a § Beslut 1.
 *
 * Inget `deleted_at`, till skillnad från AGENTS.md § Databaskonventioner:
 * en feed som ska bort återkallas med `revoked_at`, den mjukraderas inte —
 * historiken är poängen, man ska kunna se att en länk en gång fanns och
 * stängdes. Samma avvikelse, av samma skäl, som `container_access` och
 * `invitation` gjorde (se deras migrationsdocblock).
 *
 * Index, se issue 36a § Beslut 1 — dokumentet listar inga:
 * - `token_hash` UNIKT: 36b:s enda uppslagsväg vid prenumeration, och
 *   unikheten gör att två feeder aldrig delar klartext (en hashkollision
 *   blir ett skrivfel i stället för en tyst förväxling).
 * - `(container_id, user_id)`: listningen av den egna användarens feeder i
 *   CalendarFeedController::index() och den nästlade routebindningen.
 *
 * INGEN uniknyckel på `(container_id, user_id)` — en användare får ha flera
 * feeder till samma container (en i telefonen och en i datorn), se issue 36a
 * § Beslut 2.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_feed', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();

            $table->index(['container_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_feed');
    }
};
