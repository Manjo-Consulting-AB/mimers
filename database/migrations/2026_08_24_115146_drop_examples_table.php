<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare — beslut 2 i #16.
 *
 * `examples` bevisade ULID, mjuk radering och tidsstämplar i issue 2, innan
 * det fanns en riktig tabell. `account` och `user` bevisar samma
 * konventioner nu, så attrappen är redundant. Contract-steget i
 * expand/contract — tillåtet eftersom ingen kod längre använder tabellen.
 * Migrationen som skapade den (2026_08_24_000000_create_examples_table.php)
 * rörs inte, samma resonemang som för `users`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('examples');
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
