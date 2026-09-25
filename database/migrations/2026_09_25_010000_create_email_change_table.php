<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 130 · E-postadressen går att byta. Se [[M20 Kontot]] § 130 och
 * [[Konton och åtkomst]] § email_change.
 *
 * **Formen är `magic_link_token`s**, och av samma skäl: tokenet lagras som en
 * SHA-256-hash av slumpen i länken, är engångs (`confirmed_at`) och går ut
 * (`expires_at`, en timme). Klartexten finns bara i mejlet. Läs
 * 2026_08_24_130000_create_magic_link_token_table.php — den här tabellen
 * upprepar dess motivering och lägger till den enda skillnaden.
 *
 * **Skillnaden: raden binds till `user_id` och inte till adressen.**
 * `magic_link_token` binds till `email` därför att adressen ingår i
 * verifieringen; här är `new_email` själva bytet, och den som bekräftar
 * måste vara samma PERSON som begärde det — länken får inte kunna flytta
 * kontot när en session kapats, och en annan inloggad användare ska mötas av
 * 404 (ConfirmEmailChange). Utan `user_id` hade länken varit en
 * bärartoken till ett helt konto.
 *
 * **Ingen `used_at`, utan `confirmed_at`.** Ett magic link förbrukas av en
 * inloggning; ett e-postbyte är inte förbrukat förrän adressen faktiskt
 * skrivits om, och kolumnen är kvittensen på att den skrivningen skedde.
 * `App\Actions\Account\ConfirmEmailChange` förbrukar den med en villkorad
 * UPDATE (`whereNull('confirmed_at')`) — samma spärr mot två samtidiga
 * klick som MagicLinkBroker § Beslut 3.
 *
 * **En ny begäran ogiltigförklarar en tidigare obekräftad rad** genom att
 * sätta dess `expires_at` till nu, i stället för att radera den: raden är
 * beviset på att en begäran gjordes, och ett kvitto som försvinner är
 * svårare att utreda än ett som gått ut (issuens flödespunkt 2).
 *
 * **Ingen `ulid` och inget `deleted_at`** — samma avvägning som
 * `magic_link_token`: raden exponeras aldrig som en egen resurs i API:et, och
 * en kortlivad säkerhetsartefakt är inte användarskapat innehåll
 * (AGENTS.md § Databaskonventioner).
 *
 * `ON DELETE RESTRICT` är konventionen och inte ett förbiseende: raden hör
 * till personen, och personraderingen finns inte än (se
 * [[Registerförteckning]] § email_change).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_change', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('new_email');
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
        Schema::dropIfExists('email_change');
    }
};
