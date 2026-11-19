<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 406 · En radering gjord av en agent syns i papperskorgen, se
 * [[ADR-0087 MCP-server för agenter]] Riktning 7, 8, 9 och 14.
 *
 * **Två kolumner på varje tabell papperskorgen listar.** `deleted_by_agent_id`
 * pekar ut agenten som raderade raden, så papperskorgen kan skriva *Deleted by
 * {agentens namn}* i stället för att gissa ur händelseloggen.
 * `agent_deletion_batch` bär tillfället — en ULID som delas av alla rader
 * agenten kastade med högst 15 minuter emellan — så att hela tillfället kan
 * återställas i ett svep.
 *
 * Papperskorgen listar `item`, `attachment`, `category` och `tag` i
 * App\Actions\Trash\ListTrash och `container` i App\Actions\Trash\ListTrashedContainers.
 * Scheman och kostnadsrader mjukraderas av sina egna actions men listas inte i
 * papperskorgen (issue 20a § Beslut 3), så de får ingen kolumn här: deras
 * raderingar bär batchen i händelseloggen och kan inte återställas ur
 * papperskorgen.
 *
 * **Främmande nyckeln släpper taget när agenten kopplas bort.** En agent
 * raderas hårt av App\Actions\Agent\DisconnectAgent, och en `RESTRICT` hade
 * gjort en bortkoppling omöjlig så snart agenten raderat något. `nullOnDelete`
 * behåller raden i papperskorgen; namnet faller tillbaka på användaren i
 * händelseloggen, precis som för en raderad användare.
 *
 * Indexet på `agent_deletion_batch` bär återställningen av ett helt tillfälle
 * och cronen som skapar notisen — båda frågar på batchen, aldrig på agenten.
 *
 * **Fem uttryckliga block, inte en loop.** Larastan läser kolumnerna ur
 * migrationerna statiskt och förstår inte ett tabellnamn i en variabel (se
 * 2026_11_18_181000_add_agent_level_to_container_access_and_invitation.php),
 * och utan kolumnerna blir varje modellåtkomst i koden en okänd egenskap.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item', function (Blueprint $table): void {
            $table->foreignId('deleted_by_agent_id')->nullable()->constrained('agent')->nullOnDelete();
            $table->char('agent_deletion_batch', 26)->nullable();
            $table->index('agent_deletion_batch');
        });

        Schema::table('attachment', function (Blueprint $table): void {
            $table->foreignId('deleted_by_agent_id')->nullable()->constrained('agent')->nullOnDelete();
            $table->char('agent_deletion_batch', 26)->nullable();
            $table->index('agent_deletion_batch');
        });

        Schema::table('category', function (Blueprint $table): void {
            $table->foreignId('deleted_by_agent_id')->nullable()->constrained('agent')->nullOnDelete();
            $table->char('agent_deletion_batch', 26)->nullable();
            $table->index('agent_deletion_batch');
        });

        Schema::table('tag', function (Blueprint $table): void {
            $table->foreignId('deleted_by_agent_id')->nullable()->constrained('agent')->nullOnDelete();
            $table->char('agent_deletion_batch', 26)->nullable();
            $table->index('agent_deletion_batch');
        });

        Schema::table('container', function (Blueprint $table): void {
            $table->foreignId('deleted_by_agent_id')->nullable()->constrained('agent')->nullOnDelete();
            $table->char('agent_deletion_batch', 26)->nullable();
            $table->index('agent_deletion_batch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_agent_id']);
            $table->dropIndex(['agent_deletion_batch']);
            $table->dropColumn(['deleted_by_agent_id', 'agent_deletion_batch']);
        });

        Schema::table('attachment', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_agent_id']);
            $table->dropIndex(['agent_deletion_batch']);
            $table->dropColumn(['deleted_by_agent_id', 'agent_deletion_batch']);
        });

        Schema::table('category', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_agent_id']);
            $table->dropIndex(['agent_deletion_batch']);
            $table->dropColumn(['deleted_by_agent_id', 'agent_deletion_batch']);
        });

        Schema::table('tag', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_agent_id']);
            $table->dropIndex(['agent_deletion_batch']);
            $table->dropColumn(['deleted_by_agent_id', 'agent_deletion_batch']);
        });

        Schema::table('container', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_agent_id']);
            $table->dropIndex(['agent_deletion_batch']);
            $table->dropColumn(['deleted_by_agent_id', 'agent_deletion_batch']);
        });
    }
};
