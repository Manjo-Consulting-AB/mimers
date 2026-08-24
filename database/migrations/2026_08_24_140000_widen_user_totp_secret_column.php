<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue #19 · TOTP-hemlighet: aktivering och verifiering (uppföljning
 * efter granskning av PR #36). Vidgar `user.totp_secret` från
 * VARBINARY(255) (create_user_table-migrationen, issue 3) till
 * VARBINARY(512).
 *
 * Bakgrund: Laravels `encrypted`-cast (obligatorisk, se issue #19 §
 * Beslut som redan är fattade punkt 1) lägger ett kuvert av IV, MAC och
 * JSON ovanpå klartexten innan base64. En hemlighet på
 * Google2FA::generateSecretKey()s standardlängd — 32 tecken, 160 bitar,
 * RFC 4226 § 4 R6:s rekommenderade nivå — krypterar till 256 bytes:
 * en byte för mycket för den gamla VARBINARY(255)-kolumnen. Se
 * App\Support\Auth\TotpBroker och tests/Feature/Auth/TotpAktiveringTest.php
 * för mätningen.
 *
 * Rör INTE create_user_table-migrationen — den är körd i produktion, se
 * AGENTS.md § Databaskonventioner: "Migrationer rullas aldrig tillbaka i
 * produktion. Expand/contract: additiva steg i en release, destruktiva i
 * en senare." Det här är ett rent expand-steg: kolumnen är fortfarande
 * tom (ingen TOTP är aktiverad någonstans, 6a är inte ens mergad), och
 * en breddning är inte destruktiv.
 *
 * 512, inte en snävare siffra som räcker för dagens 256 bytes: höljet är
 * inte konstant över tid. Byter appen någon gång krypteringsalgoritm
 * (t.ex. till AES-256-GCM, som fyller i `tag`-fältet Laravels nuvarande
 * kuvert lämnar tomt) ska marginalen inte behöva räknas om då.
 * App\Support\Auth\TotpBroker känner inte till kolumnbredden alls — den
 * bara sätter `$user->totp_secret` och litar på casten.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->binary('totp_secret', 512)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->binary('totp_secret', 255)->nullable()->change();
        });
    }
};
