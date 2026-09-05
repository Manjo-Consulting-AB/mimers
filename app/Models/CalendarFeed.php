<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\CalendarFeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En hemlig prenumerationslänk per container och användare — "URL:en är i
 * praktiken ett lösenord", se [[Notiser]] § ICS-kalenderfeed och issue 36a.
 * Apple Calendar och Google Calendar hämtar feeden på URL:en och ser bara
 * det den här användaren får se.
 *
 * En feed hör till EN container och EN användare, och det är hela
 * åtkomstmodellen (issue 36a § Beslut 2). Ingen uniknyckel på
 * `(container_id, user_id)`: en användare får ha flera feeder till samma
 * container — en i telefonen och en i datorn — så att den ena kan återkallas
 * när telefonen tappas bort. Det är hela poängen med `revoked_at`.
 *
 * `token_hash` är alltid en SHA-256-hex av den slump som skapas i
 * App\Http\Controllers\Api\CalendarFeedController::store(), aldrig slumpen
 * själv (issue 36a § Beslut 5) — samma modell som App\Models\Invitation.
 * Klartexten finns i svaret på POST och ingen annanstans, någonsin.
 *
 * `container_id`, `user_id` och `token_hash` är medvetet UTESLUTNA ur
 * `#[Fillable]`, samma resonemang som `Container::$account_id` och
 * `ContainerAccess::$granted_by_user_id` — de sätts explicit av kontrollern,
 * aldrig via massildelning (issue 36a § Beslut 5). Inget fält alls är
 * massilldelningsbart: `revoked_at` sätts av en dedikerad återkallningsåtgärd
 * och `last_fetched_at` skrivs av 36b:s feedrutt.
 *
 * Ingen SoftDeletes: en återkallad feed (`revoked_at`) är en vanlig rad som
 * listas med sitt återkallandedatum ifyllt — historiken är poängen, se
 * issue 36a § Beslut 1 och migrationens docblock.
 *
 * `ulid` är routeidentifieraren (bindning via `#[RouteKey('ulid')]`), och
 * `last_fetched_at` skapas här men skrivs först av 36b.
 */
#[Fillable([])]
#[RouteKey('ulid')]
class CalendarFeed extends Model
{
    /** @use HasFactory<CalendarFeedFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `calendar_feed`, i singular liksom `invitation`,
     * `container` och `user` — se AGENTS.md § Databaskonventioner och
     * issue 36a § Beslut 1.
     */
    protected $table = 'calendar_feed';

    /**
     * Get the attributes that should be cast.
     *
     * `revoked_at` sätts av återkallningen i CalendarFeedController och
     * `last_fetched_at` skrivs av 36b — båda är tidsstämplar och castas till
     * Carbon, samma mönster som App\Models\ContainerAccess.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'last_fetched_at' => 'datetime',
        ];
    }

    /**
     * Containern feeden visar. Exakt en.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Användaren feeden visar innehållet för — feeden visar bara det den
     * här användaren får se. Exakt en.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
