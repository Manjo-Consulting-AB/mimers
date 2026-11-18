<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 401 · Ägarens tak för medlemmens agenter, se [[ADR-0087 MCP-server
 * för agenter]] Riktning 6 (*Inbjudan bär agentåtkomsten* och *Takets regler*).
 *
 * `agent_level` ligger på samma ladder som `level` och är aldrig högre än
 * raden egen `level` — det upprätthåller applikationslagret
 * (`SetMemberAgentLevel`, `UpdateContainerAccess`), inte databasen. NULL
 * betyder att inga av medlemmens agenter når containern, så alla befintliga
 * rader och inbjudningar börjar utan agentåtkomst.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Två uttryckliga block, inte en loop: Larastan läser kolumnerna ur
        // migrationerna statiskt och förstår inte ett tabellnamn i en variabel.
        Schema::table('container_access', function (Blueprint $table) {
            $table->string('agent_level', 20)->nullable()->after('level');
        });

        Schema::table('invitation', function (Blueprint $table) {
            $table->string('agent_level', 20)->nullable()->after('level');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE container_access ADD CONSTRAINT container_access_agent_level_check CHECK (agent_level IS NULL OR agent_level IN ('read', 'create', 'write', 'delete'))");
            DB::statement("ALTER TABLE invitation ADD CONSTRAINT invitation_agent_level_check CHECK (agent_level IS NULL OR agent_level IN ('read', 'create', 'write', 'delete'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE container_access DROP CONSTRAINT container_access_agent_level_check');
            DB::statement('ALTER TABLE invitation DROP CONSTRAINT invitation_agent_level_check');
        }

        Schema::table('container_access', function (Blueprint $table) {
            $table->dropColumn('agent_level');
        });

        Schema::table('invitation', function (Blueprint $table) {
            $table->dropColumn('agent_level');
        });
    }
};
