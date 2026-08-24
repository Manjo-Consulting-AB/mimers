<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare — beslut 1 i #16.
 *
 * `0001_01_01_000000_create_users_table.php` är redan körd i produktion och
 * rörs därför inte; den skapade tabellen droppas här som ett eget,
 * destruktivt (contract-)steg i stället. `password_reset_tokens` och
 * `sessions`, som skapas i samma ursprungsfil, lämnas orörda — de behövs
 * fortfarande. `App\Models\User` pekar från och med den här issuen mot den
 * nya tabellen `user`, se create_user_table-migrationen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('users');
    }

    /**
     * Reverse the migrations.
     *
     * Migrationer rullas aldrig tillbaka i produktion (AGENTS.md), så den
     * här metoden återskapar avsiktligt inte tabellen.
     */
    public function down(): void
    {
        //
    }
};
