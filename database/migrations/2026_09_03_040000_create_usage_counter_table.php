<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 26a · Förbrukning per konto, uppdaterad transaktionellt — aldrig
 * räknad om vid behov, då blir det fel så fort en beräkning avbryts. Se
 * [[Planer och kvoter]] § usage_counter.
 *
 * Räknaren är en cache av två frågor (Beslut 2) — summan av bytena för
 * kontots levande bilagor och antalet levande containers. Uppdateras i samma
 * transaktion som den radändring som föranleder det, genom den enda vägen in,
 * App\Actions\Usage\AdjustUsage (Beslut 3). Kontrollerna som LÄSER räknaren
 * är 27a/27b; avstämningen som fångar att den driver isär är 26b.
 *
 * En rad per konto: `account_id` är unikt. Inget `ulid` (raden syns aldrig
 * som en egen resurs i API:et — den läses genom sitt konto, Beslut 1) och
 * ingen `deleted_at` (en räknare är inte användarskapat innehåll). Bytena är
 * BIGINT UNSIGNED och containerantalet INT UNSIGNED (AGENTS.md §
 * Databaskonventioner) — ingendera får någonsin gå under noll.
 *
 * Ingen bakåtfyllning (Beslut 7): konton som redan har bilagor får sina rader
 * när 26b:s avstämning kör första gången. Att fylla i dem i en migration vore
 * samma omräkning som dokumentet säger att man inte ska förlita sig på, och
 * den skulle dessutom ligga i en fil som inte får ändras när formeln senare
 * rättas.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_counter', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('account')->onDelete('restrict');
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedInteger('container_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_counter');
    }
};
