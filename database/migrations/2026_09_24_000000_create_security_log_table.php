<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 113 · Säkerhetsloggen. Se [[ADR-0043 Tre loggar]] § Säkerhetsloggen
 * och [[Registerförteckning]]. Tabellen svarar på missbruk, intrång och
 * olagligt innehåll — den läses av oss, utom användarens egna inloggningar
 * (issue 117) — och den enda vägen in är
 * App\Actions\Security\RecordSecurityEvent.
 *
 * **Ingen rå IP-adress och ingen rå webbläsarsträng.** `ip_group` är de
 * första sexton hexatecknen av `hash_hmac('sha256', $ip, config('app.key'))`
 * — samma pseudonym som missbruksrapporten räknar fram, med formeln i
 * App\Support\Security\IpGroup så att de två aldrig kan glida isär.
 * `device_name` är webbläsarsträngen tolkad till *Firefox · macOS* när raden
 * skrivs; strängen själv kastas. Pseudonymen är fortfarande en personuppgift
 * enligt GDPR, men en läckt tabell avslöjar ingen adress (ADR § Motivering).
 *
 * **Ingen `updated_at` och ingen `deleted_at`.** En logg som kan ändras är
 * inget bevis, och raden tas bort hel eller inte alls — av gallringen i issue
 * 115, tolv månader efter `created_at`. `const UPDATED_AT = null` stänger av
 * Eloquents andra tidsstämpel.
 *
 * **Ingen `ulid`** — ingen rutt, ingen resurs och ingen vy identifierar en
 * rad (AGENTS.md § Databaskonventioner: kravet gäller tabeller som syns i
 * API:et, och den här har ingen yta). Issue 117 läser raderna serverat.
 *
 * **`account_id` och `user_id` är identifierare utan främmande nycklar**,
 * samma avvägning som issue 107 gjorde för `audit_log` och issue 112 för
 * `legal_hold`: en nyckel säger att raden inte får finnas utan det den pekar
 * på, och loggens poäng är motsatsen. En nyckel hade fällt kontoraderingen
 * varje natt för varje konto som någon gång loggat in.
 *
 * `ip_group` och `device_name` är nullbara: en misslyckad inloggning mot en
 * adress som inte finns har ingen användare men väl en pseudonym, och en
 * klient utan `User-Agent` har ingen enhet att tolka.
 *
 * `meta` är JSON och NOT NULL, samma regel som `audit_log`: ingen fritext,
 * aldrig ett lösenord, en kod eller ett token — inte ens hashad.
 *
 * Indexen är de tre läsvägarna: användarens egna inloggningar (issue 117),
 * en pseudonyms rader (utredningen) och gallringens frist, som sveper hela
 * tabellen på `created_at`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 60);
            $table->char('ip_group', 16)->nullable();
            $table->string('device_name', 60)->nullable();
            $table->json('meta');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip_group', 'created_at']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_log');
    }
};
