<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare. Se [[Konton och åtkomst]] § account_user.
 *
 * Ren kopplingstabell mellan konto och användare — dokumentets kolumnlista
 * har varken id, ulid eller tidsstämplar för den, till skillnad från account
 * och user. Den exponeras aldrig som en egen resurs i API:et; medlemskap nås
 * via kontot eller användaren. `role` är VARCHAR med CHECK-villkor, aldrig
 * ENUM — se AGENTS.md § Databaskonventioner.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained('account')->onDelete('restrict');
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('role', 20);

            $table->unique(['account_id', 'user_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE account_user ADD CONSTRAINT account_user_role_check CHECK (role IN ('owner', 'admin', 'member'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_user');
    }
};
