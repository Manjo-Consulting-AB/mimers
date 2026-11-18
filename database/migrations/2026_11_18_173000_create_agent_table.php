<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 400 · Tabellen `agent`, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 3, 6 och 12 och [[Konton och åtkomst]] § agent.
 *
 * **En rad per användare och OAuth-klient.** Det unika indexet
 * `(user_id, oauth_client_id)` är det som gör "samma klient igen" till en
 * återanvändning i stället för en andra rad: ett nytt godkännande från samma
 * klient skriver ingen ny rad utan behåller namn, rättigheter och paus. Ett
 * tak på tio räknar alltså agenter och inte godkännanden.
 *
 * **`oauth_client_id` är ingen främmande nyckel.** Passports klienter ligger i
 * `oauth_clients` med UUID som primärnyckel, och en klientrad raderas inte när
 * en agent kopplas bort — bara agentens tokens återkallas. Att binda raden
 * hade gjort en klientradering till en agentradering i smyg.
 *
 * **`account_id` är personkontot**, inte det konto en container råkar tillhöra:
 * agentens förbrukning och plan hör till personen som godkände den
 * (Riktning 4). Båda nycklarna kaskadraderar — en borttagen person eller ett
 * borttaget personkonto ska inte lämna en agent som pekar på ingenting.
 *
 * **Rättigheterna bor inte här.** `agent_account_grant` och
 * `agent_container_grant` kommer i issue 401; den här raden bär bara
 * anslutningen. En saknad rättighetsrad betyder ingen åtkomst.
 *
 * `paused_at` satt = pausad, och `last_used_at` skrivs högst en gång per minut
 * av anropet (issue 402). `name` är klientens namn vid registreringen —
 * användaren kan byta det på `/settings/agents` (issue 407).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('account')->cascadeOnDelete();
            $table->char('oauth_client_id', 36);
            $table->string('name', 100);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'oauth_client_id']);
            $table->index('account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent');
    }
};
