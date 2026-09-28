<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En besöksrad: personen öppnade itemets sida, se [[ADR-0049 Nyligen besökta]]
 * och [[M23 Mobilen och kartan]] § 160.
 *
 * **Raden är ett par, och paret är unikt** — `(user_id, item_id)` bärs av ett
 * unikt index i migrationen, och skrivningen är en upsert på det. Modellen är
 * därför en pivot i samma form som App\Models\Favorite: ingen `ulid` (ingen
 * rutt identifierar en enskild rad), ingen `deleted_at` (raden är inget
 * innehåll, och papperskorgen listar fyra typer), och ingen `container_id`
 * (containern härleds ur itemet).
 *
 * `user_id` och `item_id` är medvetet UTESLUTNA ur `#[Fillable]`, samma regel
 * som App\Models\Favorite: de sätts av App\Actions\Item\RecordRecentVisit ur
 * den inloggade användaren och ur ruttens item, aldrig via massildelning.
 * `visited_at` står utanför av samma skäl — den är klockan vid skrivningen och
 * ingenting en anropare väljer.
 *
 * **Raden speglar åtkomsten, den ger den inte.** Modellen bär ingen
 * åtkomstregel och prövar ingenting: skrivningen ligger EFTER grinden i
 * App\Http\Controllers\ItemController::show(), och läsningen går genom
 * App\Actions\Item\ListRecentVisits, som filtrerar med
 * App\Actions\Access\ResolveItemScope som varje annan listning. Ett item
 * användaren förlorat åtkomsten till försvinner därför ur listan medan raden
 * ligger kvar — och blir synlig igen om åtkomsten kommer tillbaka.
 *
 * Ingen `HasFactory`: ingen fabrik behövs — en rad skrivs genom sin action
 * eller genom relationen, och den är två främmande nycklar och en tidsstämpel.
 */
#[Fillable([])]
class RecentVisit extends Model
{
    /**
     * Tabellen heter `recent_visit`, inte Eloquents standardplural.
     */
    protected $table = 'recent_visit';

    /**
     * Tidsstämpeln för besöket, i UTC som alla tidsstämplar i huset.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /**
     * Användaren besöket tillhör.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Itemet besöket gäller.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
