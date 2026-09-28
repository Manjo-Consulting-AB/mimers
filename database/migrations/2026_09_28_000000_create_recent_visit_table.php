<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 160 · Nyligen besökta. Se [[ADR-0049 Nyligen besökta]] och
 * [[M23 Mobilen och kartan]] § 160.
 *
 * **Listan lagras på servern och per person.** Mobilmockupens sidomeny har en
 * sektion för de items man senast öppnade, och på en telefon sparar den flest
 * tryck av allt i bilden: vägen till ett item går annars genom containern och
 * trädet. `user.last_active_at` är en tidpunkt och ingen plats, och
 * händelseloggen ([[ADR-0043 Tre loggar]]) loggar ändringar och inte
 * visningar — ingenting i datamodellen svarade på frågan.
 *
 * **Ett par per person och item.** Det unika indexet `(user_id, item_id)` är
 * garanten för att ett nytt besök UPPDATERAR raden i stället för att skriva en
 * andra: skrivningen är en upsert på paret, och utan indexet hade varje
 * omladdning av en itemsida lagt en rad till.
 *
 * **Indexet `(user_id, visited_at)` bär både läsningen och taket.**
 * Läsningen tar de senaste för en person, och städningen i samma förfrågan
 * raderar det som ligger utanför de tjugo — båda sorterar på `visited_at` inom
 * en `user_id`.
 *
 * **Ingen `ulid` och ingen `deleted_at`.** Raden syns aldrig utåt — listan
 * visar itemets ULID och namn, och ingen rutt, resurs eller vy identifierar en
 * enskild besöksrad (samma resonemang som `favorite`, issue 105) — och den är
 * inget innehåll att återställa: papperskorgen listar fyra typer, och en
 * besöksrad hör inte bland dem.
 *
 * **`ON DELETE RESTRICT`, som husets konvention.** Raderna städas av
 * raderingarna själva och inte av en kaskad, och det är ett medvetet val: en
 * kaskad hade varit en radering ingen action har bett om (samma linje som
 * [[ADR-0045 Radering av konto och person]] § Beslut 2). `DeleteUser` tar
 * personens rader, `PurgeContent::item()` tar ett gallrat items, och
 * `PurgeContainer` når dem genom item-loopen — se de metodernas docblock.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recent_visit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->timestamp('visited_at');
            $table->timestamps();

            $table->unique(['user_id', 'item_id']);
            $table->index(['user_id', 'visited_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recent_visit');
    }
};
