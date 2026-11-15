<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Containerns urval av valutor för kostnader — issue 1254 ·
 * [[ADR-0078 Valutor ur ISO 4217]] § 4 och 5.
 *
 * En rad per valuta som får användas när en kostnad registreras i
 * containern. **Ingen rad betyder alla** — ett tomt urval är inte "inga" —
 * och containerns standardvaluta är alltid tillåten utan att stå här
 * (App\Models\Container::permitsCurrency()).
 *
 * Ingen `deleted_at`: raden är en INSTÄLLNING och inte innehåll. Att ta bort
 * en valuta ur urvalet raderar raden; det finns ingenting att ångra i en
 * papperskorg, och en mjukraderad inställning hade varit en andra sanning om
 * vad urvalet är. Samma linje som `container.cover_attachment_id` och
 * `container_access.revoked_at` — inställningar och behörigheter
 * mjukraderas inte.
 *
 * Ingen `ulid`: tabellen syns aldrig i API:et som en egen resurs. Urvalet
 * följer med containern (`ContainerResource`), och en rad har ingen adress
 * utåt — samma skäl som `item_tag` och `container_access` för pivotrader.
 *
 * UNIQUE `(container_id, currency)` gör dubbletten omöjlig i databasen och
 * inte bara i actionen: `SetAllowedCurrencies` ersätter hela urvalet, men en
 * framtida skrivväg ska inte kunna lagra samma valuta två gånger.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('container_allowed_currency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['container_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_allowed_currency');
    }
};
