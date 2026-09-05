<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 37a · Webhooks, registret — en mottagaradress per konto, se
 * [[Notiser]] § webhook_endpoint och App\Http\Controllers\Api\WebhookEndpointController.
 *
 * En webhook tillhör ett KONTO, inte en container (Beslut 1): kontot äger
 * URL:en, kontot betalar för funktionen och kontots plan grinden läser.
 * `secret` krypteras i kolumnen med Laravels `encrypted`-cast, precis som
 * `user.totp_secret` — servern måste kunna läsa den igen för att signera
 * varje leverans med HMAC-SHA256 (37b), så den hashas aldrig (Beslut 2).
 *
 * Kolumnbredden är 512, inte Beslut 1:s 255: en krypterad Str::random(64)
 * mäter 312 bytes (uppmätt i testsviten, samma hölje som
 * user.totp_secret) och ryms inte i VARBINARY(255). user.totp_secret
 * fick en följdmigration för exakt det här
 * (2026_08_24_140000_widen_user_totp_secret_column.php); i en NY tabell
 * skapas kolumnen rätt från början. Se Frågor och antaganden i PR:en.
 *
 * Inget `deleted_at` (Beslut 1): en endpoint som ska bort tas bort på
 * riktigt. Att behålla en avregistrerad mottagares URL vore att behålla en
 * adress någon bett oss sluta anropa — raden raderas hårt av DELETE-rutten
 * och av kontots städning i App\Actions\Account\DeleteAccount.
 *
 * Index (Beslut 1): `(account_id, is_active)` för listningen av ett kontos
 * endpoints och för 37b:s uppslag av aktiva rader.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoint', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('account_id')->constrained('account')->onDelete('restrict');
            $table->string('url', 500);
            $table->binary('secret', 512);
            $table->json('event_types');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoint');
    }
};
