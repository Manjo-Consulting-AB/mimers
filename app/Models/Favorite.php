<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En favoritmarkering: en användares bokmärke på ett item, se
 * [[ADR-0042 Designsystemet]] § Konsekvenser och [[M17 Designsystemet]]
 * § 105.
 *
 * **En rad är ett par, och paret är unikt** — `(user_id, item_id)` bärs av ett
 * unikt index i migrationen. Modellen är därför en pivot i samma form som
 * App\Models\ItemLink: ingen `ulid` (ingen rutt identifierar en enskild rad),
 * ingen `deleted_at` (en borttagen markering raderas hårt), och ingen
 * `container_id` (containern härleds ur itemet, som aldrig byter container).
 *
 * `user_id` och `item_id` är medvetet UTESLUTNA ur `#[Fillable]`, samma regel
 * som App\Models\ItemLink och App\Models\ContainerAccess: de sätts explicit
 * av App\Http\Controllers\FavoriteController — ur den inloggade användarens
 * relation och ur ruttens item — aldrig via massildelning.
 *
 * **Markeringen speglar åtkomsten, den ger den inte.** Modellen bär ingen
 * åtkomstregel och prövar ingenting: grinden är App\Policies\ItemPolicy::
 * view() och ligger i kontrollern, före varje skrivning hit. Ett item
 * användaren inte når går därför inte att markera, och en markering öppnar
 * ingenting.
 *
 * Ingen `HasFactory`: ingen fabrik behövs — en favorit skapas genom sin rutt
 * eller genom relationen, och raden är två främmande nycklar och inget mer.
 */
#[Fillable([])]
class Favorite extends Model
{
    /**
     * Tabellen heter `favorite`, inte Eloquents standardplural `favorites`.
     */
    protected $table = 'favorite';

    /**
     * Användaren markeringen tillhör.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Itemet markeringen gäller.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
