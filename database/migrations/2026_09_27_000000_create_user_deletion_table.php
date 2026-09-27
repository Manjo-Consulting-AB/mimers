<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 145 · En person raderar sig själv från inställningarna. Se
 * [[M22 Redo för testare]] § 145, [[Konton och åtkomst]] § user_deletion och
 * [[ADR-0045 Radering av konto och person]] § Beslut 3.
 *
 * **Formen är `password_change`s**, och av samma skäl: tokenet lagras som en
 * SHA-256-hash av slumpen i länken, är engångs (`confirmed_at`) och går ut
 * (`expires_at`, en timme). Klartexten finns bara i mejlet. Läs
 * 2026_09_26_000000_create_password_change_table.php — den här tabellen
 * upprepar dess motivering och har ingen egen hemlighet att bära.
 *
 * **Skillnaden: raden bär ingen nyttolast alls.** Ett lösenordsbyte lagrar
 * det nya lösenordet och ett adressbyte den nya adressen; en personradering
 * har ingenting att komma med. Det som ska hända står i `user`-raden själv
 * (den raderas) och i personens medlemskap (kontona avgörs av
 * App\Actions\User\DeleteUser när länken öppnas) — ingen av dem hör hemma i
 * en kopia här, för en kopia kan glida isär från det den beskriver.
 *
 * **Raden binds till `user_id` och inte till något annat.** Den som bekräftar
 * måste vara samma användare som begärde raderingen — länken får inte kunna
 * radera ett konto när en session kapats, och en annan inloggad användare ska
 * mötas av 404 (ConfirmUserDeletion). Utan `user_id` hade länken varit en
 * bärartoken till en hel person.
 *
 * **En ny begäran ogiltigförklarar en tidigare obekräftad rad** genom att
 * sätta dess `expires_at` till nu, i stället för att radera den: raden är
 * beviset på att en begäran gjordes, och ett kvitto som försvinner är
 * svårare att utreda än ett som gått ut.
 *
 * **Raden raderas med personen.** `ON DELETE RESTRICT` är konventionen och
 * inte ett hinder: App\Actions\User\DeleteUser raderar personens egna rader —
 * den här bland dem — före `user`-raden, i samma transaktion. En
 * personradering som fastnade på sitt eget bekräftelsetoken vore en spärr
 * ingen kunde häva.
 *
 * **Ingen `ulid` och inget `deleted_at`** — samma avvägning som
 * `password_change` och `email_change`: raden exponeras aldrig som en egen
 * resurs i API:et, och en kortlivad säkerhetsartefakt är inte användarskapat
 * innehåll (AGENTS.md § Databaskonventioner). Ingen gallring byggs här.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_deletion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
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
        Schema::dropIfExists('user_deletion');
    }
};
