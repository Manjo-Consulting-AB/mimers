<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ett tips användaren kryssat bort i informationsytan, se
 * [[M19 Dashboarden]] § 128 och [[ADR-0039 Containerns översikt]]
 * § Konsekvenser.
 *
 * **En rad är ett par, och paret är unikt** — `(user_id, tip_key)` bärs av ett
 * unikt index i migrationen. Modellen är därför en pivot i samma form som
 * App\Models\Favorite och App\Models\ItemLink: ingen `ulid` (ingen rutt
 * identifierar en enskild rad) och ingen `deleted_at` (ett dolt tips raderas
 * hårt, och en mjukraderad rad hade blockerat en ny rad för samma nyckel).
 *
 * `user_id` och `tip_key` är medvetet UTESLUTNA ur `#[Fillable]`, samma regel
 * som App\Models\Favorite: de sätts explicit av
 * App\Http\Controllers\DismissedTipController — ur den inloggade användarens
 * relation och ur ruttens nyckel — aldrig via massildelning.
 *
 * **Modellen känner inte tipsen.** Vilka nycklar som finns och i vilken
 * ordning de visas står i App\Support\Tips, och den listan bor i kod och inte
 * i en tabell (issuens krav 3): en tabell hade gjort ordningen till data som
 * kunde glida isär från strängarna i `lang/en/ui.php`, och en nyckel utan
 * översättning hade blivit en tom ruta. Modellen bär därför ingen validering
 * av `tip_key` — grinden är `Tips::knows()` och ligger i kontrollern, före
 * varje skrivning hit.
 *
 * Ingen `HasFactory`: ingen fabrik behövs — en rad skapas genom sin rutt eller
 * genom relationen, och den är en främmande nyckel och en sträng och inget
 * mer.
 */
#[Fillable([])]
class DismissedTip extends Model
{
    /**
     * Tabellen heter `dismissed_tip`, inte Eloquents standardplural.
     */
    protected $table = 'dismissed_tip';

    /**
     * Användaren raden tillhör.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
