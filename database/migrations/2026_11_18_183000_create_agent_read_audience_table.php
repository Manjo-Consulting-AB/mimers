<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 404 · Kopieringsspärren, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 10, *Kopiering*.
 *
 * **Snittet ligger i databasen, aldrig i cachen.** En tömd cache får inte
 * släppa spärren, så raderna är den enda sanningen om vad agenten har läst
 * under fönstret. `agent.read_audience_until` är fönstrets slut: det sätts
 * till nu + `mcp.read_audience_hours` vid varje noterad läsning och
 * nollställer snittet när det passerat.
 *
 * **En rad per person som når allt agenten läst.** Tabellen bär snittet som
 * en mängd rader i stället för en serialiserad lista, så att en krympning av
 * snittet är en DELETE och en jämförelse är ett `whereNotIn` — och så att
 * främmande nycklar städar raderna när agenten eller personen försvinner.
 *
 * Tabellen syns aldrig i API:et och har därför ingen `ulid`; den är intern
 * som `agent_account_grant` och `account_user`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_read_audience', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agent')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_id', 'user_id']);
        });

        Schema::table('agent', function (Blueprint $table) {
            $table->timestamp('read_audience_until')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent', function (Blueprint $table) {
            $table->dropColumn('read_audience_until');
        });

        Schema::dropIfExists('agent_read_audience');
    }
};
