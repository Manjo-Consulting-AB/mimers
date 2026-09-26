<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 140 · Lösenordsbytet bekräftas via mejl. Se [[M20 Kontot]] § 140,
 * [[Konton och åtkomst]] § password_change och [[ADR-0011 Autentisering]]
 * § Uppföljning 2026-09-26.
 *
 * **Formen är `email_change`s**, och av samma skäl: tokenet lagras som en
 * SHA-256-hash av slumpen i länken, är engångs (`confirmed_at`) och går ut
 * (`expires_at`, en timme). Klartexten finns bara i mejlet. Läs
 * 2026_09_25_010000_create_email_change_table.php — den här tabellen
 * upprepar dess motivering och lägger till den enda skillnaden.
 *
 * **Skillnaden: `password_hash` i stället för `new_email`.** Det nya
 * lösenordet lagras HASHAT med `Hash::make()` och klartexten lämnar aldrig
 * requesten — den enda plats klartexten finns är mejlets länk, och den bär
 * den inte. `VARCHAR(255)` är samma bredd som `user.password_hash`, så en
 * rad kan flyttas dit utan att klippas.
 *
 * **Raden binds till `user_id` och inte till något annat.** Den som
 * bekräftar måste vara samma användare som begärde bytet — länken får inte
 * kunna sätta ett lösenord när en session kapats, och en annan inloggad
 * användare ska mötas av 404 (ConfirmPasswordChange). Utan `user_id` hade
 * länken varit en bärartoken till ett helt konto.
 *
 * **En ny begäran ogiltigförklarar en tidigare obekräftad rad** genom att
 * sätta dess `expires_at` till nu, i stället för att radera den: raden är
 * beviset på att en begäran gjordes, och ett kvitto som försvinner är
 * svårare att utreda än ett som gått ut (issuens flödespunkt 2).
 *
 * **Ingen `ulid` och inget `deleted_at`** — samma avvägning som
 * `email_change` och `magic_link_token`: raden exponeras aldrig som en egen
 * resurs i API:et, och en kortlivad säkerhetsartefakt är inte användarskapat
 * innehåll (AGENTS.md § Databaskonventioner). Ingen gallring byggs här,
 * precis som för `email_change`.
 *
 * `ON DELETE RESTRICT` är konventionen och inte ett förbiseende: raden hör
 * till personen, och personraderingen finns inte än (se
 * [[Registerförteckning]] § password_change).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('password_change', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('password_hash');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_change');
    }
};
