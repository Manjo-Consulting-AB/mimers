<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En rad *Was this helpful?* från en artikelsida — M82 · issue 471,
 * [[ADR-0094 Hjälpcentret]] § 7 och [[Konton och åtkomst]] § help_feedback.
 *
 * **Raden ägs av sessionen eller av personen.** En gästs rad har `user_id`
 * null och hittas bara genom ULID:n som ligger i sessionen under
 * `help_feedback` (App\Http\Controllers\HelpFeedbackController); en inloggad
 * äger sina rader genom `user_id` och kan ändra dem även när sessionen är
 * ny. `user_id` lämnas därför utanför massildelning och sätts ur den
 * inloggade användaren i kontrollern.
 *
 * **`reason` och `comment` hör till ett Nej.** Båda får bara finnas när
 * `helpful` är falskt; kontrollern nollställer dem när svaret blir Ja, och
 * databasen bär CHECK-villkoret för orsaken (migrationen).
 *
 * Ingen `deleted_at`: ingen kan radera en rad i gränssnittet, och gallringen
 * raderar på riktigt efter 90 dagar. Ingen `HasFactory`: en rad skrivs genom
 * sin rutt och har inget att fabriksbygga som ett test inte kan sätta själv.
 */
#[Fillable([])]
class HelpFeedback extends Model
{
    use HasUlid;

    /**
     * Orsakerna efter ett Nej — samma lista som CHECK-villkoret i migrationen
     * och som valideringen i App\Http\Requests\Help\HelpFeedbackUpdateRequest.
     * Ordningen är den ytan visar dem i.
     *
     * @var list<string>
     */
    public const REASONS = ['unclear', 'incomplete', 'outdated', 'incorrect', 'missing'];

    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
        ];
    }

    /**
     * Användaren raden tillhör, eller null för en gäst.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
