<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 401 · Agenten som handlande, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 3 och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * `agent_id` är en identifierare utan främmande nyckel, som `user_id`: en
 * loggrad får inte hindra att agenten raderas, och raden ska finnas kvar när
 * den gjort det. `user_id` är fortfarande användaren agenten handlade för.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->after('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn('agent_id');
        });
    }
};
