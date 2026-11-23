<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 454 · M76. Se [[ADR-0093 Ägarbytets bonus till nya kunder]].
 *
 * `grants_bonus` säger att bonusen erbjöds vid ägarbytet; `bonus_granted_at`
 * säger när accepten faktiskt gav den. Befintliga rader, även de som väntar,
 * får `false` och `NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ownership_transfer', function (Blueprint $table) {
            $table->boolean('grants_bonus')->default(false);
            $table->timestamp('bonus_granted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ownership_transfer', function (Blueprint $table) {
            $table->dropColumn(['grants_bonus', 'bonus_granted_at']);
        });
    }
};
