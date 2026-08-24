<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare. Se [[Konton och åtkomst]] § user.
 *
 * `user` ersätter Laravels standard `users` (se drop_users_table-migrationen
 * i samma issue). Ingen `name`-kolumn — dokumentet har ingen. `locale` och
 * `timezone` åsidosätter kontots värden för den här personen. `unit_system`
 * finns här trots att dokumentets kolumnlista för § user inte listar den
 * separat — Backlog-filen och issue #16 säger uttryckligen "Locale,
 * timezone, unit_system på båda", se PR:ens Frågor och antaganden.
 * `totp_secret` är krypterad i kolumnen men själva TOTP-flödet byggs i
 * issue 6, inte här. Inget deleted_at, se create_account_table-migrationen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->binary('totp_secret', 255)->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('unit_system', 10)->nullable();
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamp('last_active_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user');
    }
};
