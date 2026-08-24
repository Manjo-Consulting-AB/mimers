<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare. Se [[Konton och åtkomst]] § account_user.
 *
 * Kopplingstabell mellan konto och användare. Den saknar `ulid` — den
 * konventionen krävs bara på tabeller som exponeras som egen resurs i
 * API:et, och medlemskap nås alltid via kontot eller användaren, aldrig via
 * en egen ulid. `id` och tidsstämplar följer däremot den generella
 * konventionen i [[Datamodell – översikt]] § Konventioner för alla tabeller
 * (created_at/updated_at på allt, inget uttalat undantag här), och behövs
 * för att svara på "medlem sedan" och "när ändrades rollen" samt för att
 * ägarbyte (issue 38) och revisionsloggen ska kunna referera en
 * medlemskapsrad via en egen PK i stället för kolumnparet. `role` är
 * VARCHAR med CHECK-villkor, aldrig ENUM — se AGENTS.md
 * § Databaskonventioner.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('account')->onDelete('restrict');
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('role', 20);
            $table->timestamps();

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
