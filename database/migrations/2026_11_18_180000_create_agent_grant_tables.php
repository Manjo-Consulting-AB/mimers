<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 401 · Rättighetsmodellen för agenter, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 6 och [[Konton och åtkomst]] § agent_account_grant och
 * § agent_container_grant.
 *
 * **En saknad rad betyder ingen åtkomst.** Ingen av tabellerna har en
 * "ingen"-nivå som värde; `level` är en av `read`, `create`, `write` och
 * `delete`, samma ladder som `container_access.level`. En ny agent har därför
 * inga rader alls, och `agent.create_containers` är falsk från start.
 *
 * `agent_account_grant` är vad agenten får göra, `agent_container_grant` är
 * var — ögonblicksbilden från containerns inställningar. Båda kaskadraderar
 * med agenten, och den senare med containern: en hårt raderad container
 * lämnar inga rättighetsrader efter sig.
 */
return new class extends Migration
{
    private const LEVELS = "'read', 'create', 'write', 'delete'";

    private const GROUPS = "'items', 'schedules', 'costs', 'loans', 'files', 'containers'";

    public function up(): void
    {
        Schema::table('agent', function (Blueprint $table) {
            $table->boolean('create_containers')->default(false)->after('name');
        });

        Schema::create('agent_account_grant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agent')->cascadeOnDelete();
            $table->string('group', 20);
            $table->string('level', 20);
            $table->timestamps();

            $table->unique(['agent_id', 'group']);
        });

        Schema::create('agent_container_grant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agent')->cascadeOnDelete();
            $table->foreignId('container_id')->constrained('container')->cascadeOnDelete();
            $table->string('group', 20);
            $table->string('level', 20);
            $table->timestamps();

            $table->unique(['agent_id', 'container_id', 'group']);
            $table->index('container_id');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            foreach (['agent_account_grant', 'agent_container_grant'] as $tabell) {
                DB::statement("ALTER TABLE {$tabell} ADD CONSTRAINT {$tabell}_level_check CHECK (level IN (".self::LEVELS.'))');
                DB::statement("ALTER TABLE {$tabell} ADD CONSTRAINT {$tabell}_group_check CHECK (`group` IN (".self::GROUPS.'))');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_container_grant');
        Schema::dropIfExists('agent_account_grant');

        Schema::table('agent', function (Blueprint $table) {
            $table->dropColumn('create_containers');
        });
    }
};
