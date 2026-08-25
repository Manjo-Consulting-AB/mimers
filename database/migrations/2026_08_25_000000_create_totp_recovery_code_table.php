<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 6c · Återställningskoder. Se [[ADR-0011 Autentisering]] och
 * App\Support\Auth\RecoveryCodeBroker för hela flödet.
 *
 * En rad per utfärdad engångskod, bunden till `user_id` (FK,
 * ->onDelete('restrict'), AGENTS.md § Databaskonventioner-standarden — till
 * skillnad från magic_link_token, som binder via e-postadressen av skäl
 * som inte gäller här: en återställningskod har ingen mening frikopplad
 * från kontot den hör till, det finns inget "byt e-post mitt i" att skydda
 * mot).
 *
 * `code_hash` är alltid `Hash::make()` (bcrypt, samma mekanism som
 * `user.password_hash`) — INTE ett rakt `hash('sha256', ...)` som
 * magic_link_token. Skälet är entropin: ett magic link-token är en
 * 64-tecken slump (~380 bitar) där en snabb hash är ofarlig, en
 * återställningskod är kort nog för en människa att skriva av (10 tecken,
 * se RecoveryCodeBroker::CODE_LENGTH, ~59 bitar) — en läckt tabell ska
 * kräva samma kostsamma offline-gissning som ett läckt lösenord, inte en
 * miljoner-per-sekund SHA-256-genomsökning. Det är också därför kolumnen
 * inte har ett unikt index för direkt uppslag (bcrypt saltar varje hash
 * olika) — App\Support\Auth\RecoveryCodeBroker::consume() söker i stället
 * bland kontots ~10 oförbrukade rader och kör `Hash::check()` mot var och
 * en, precis som `Hash::check()` mot ett enda lösenord.
 *
 * `used_at` ger engångsanvändning utan en separat borttagning — samma
 * mönster som magic_link_token.used_at. En omgenerering
 * (RecoveryCodeBroker::generate()) raderar i stället HELA raduppsättningen
 * för kontot och skapar en ny — se den metodens docblock för varför en
 * bulk-DELETE räcker i stället för att markera de gamla förbrukade.
 *
 * Ingen `ulid`: tabellen exponeras aldrig som en egen resurs i API:et,
 * samma resonemang som account_user- och magic_link_token-migrationerna.
 * Inget soft delete: det här är inte användarskapat innehåll (AGENTS.md §
 * Databaskonventioner) utan en kortlivad säkerhetsartefakt — samma
 * resonemang som magic_link_token-migrationen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('totp_recovery_code', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('totp_recovery_code');
    }
};
