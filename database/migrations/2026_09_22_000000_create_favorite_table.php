<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 105 · Favoritmarkeringen. Se [[ADR-0042 Designsystemet]] §
 * Konsekvenser och [[M17 Designsystemet]] § 105.
 *
 * **En favorit är per användare, alltså en pivot och inte en kolumn på
 * `item`.** En flagga på itemet hade gjort en användares markering till allas
 * i en delad container, och det är fel så snart två personer ser samma item.
 * [[ADR-0041 Itemets vy]] kallade raden "en önskan, inte ett beslut";
 * ADR-0042 § Konsekvenser gör den till ett beslut, och det är den här
 * tabellen.
 *
 * Kolumnerna är pivotens och ingenting mer: `user_id`, `item_id` och
 * tidsstämplarna. Ingen `ulid` — ingen rutt och ingen resurs identifierar en
 * enskild favoritrad, samma resonemang som `item_link` (issue 14 § Beslut 1).
 *
 * **Ingen `deleted_at`.** Att ta bort en markering är en växling och ingen
 * radering av innehåll: papperskorgen listar fyra typer (issue 76 § Beslut 3)
 * och en bokmärkesrad hör inte bland dem. Raden raderas hårt, och ett
 * `deleted_at` hade dessutom stått i vägen för det unika paret nedan — en
 * mjukraderad rad ligger kvar i indexet och hade blockerat en ny markering av
 * samma item.
 *
 * **Det unika paret `(user_id, item_id)` är garanten** för "en markering per
 * användare och item" (issuens *Klart när*: dubblett ger ingen andra rad), och
 * det är indexet och inte en läsning-i-förväg som bär den:
 * App\Http\Controllers\FavoriteController skriver rakt in och fångar
 * `UniqueConstraintViolationException`. En check-then-act hade varit en
 * kapplöpning mot ett dubbelklick — samma skäl som `item_link`s unikhet
 * (issue 14 § Beslut 3), men löst i indexet i stället för i applikationslagret,
 * eftersom en favorit inte har någon domänregel att pröva utöver paret självt.
 *
 * Det omvända indexet `(item_id, user_id)` bär läsningen "vem har märkt det
 * här itemet" — App\Models\Item::favoritedBy(). Det unika indexet leder på
 * `user_id` och täcker bara användarens sida.
 *
 * `ON DELETE RESTRICT` som allt annat (AGENTS.md § Databaskonventioner): både
 * itemet och användaren mjukraderas i stället för att försvinna, och en hård
 * radering ska vara ett medvetet beslut.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('favorite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['user_id', 'item_id']);
            $table->index(['item_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('favorite');
    }
};
