<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ägarenheten i systemet, se [[Konton och åtkomst]] § account och
 * [[ADR-0002 Konto äger container]]. Ett privatkonto är bara ett konto med
 * en enda medlem — samma tabell som en organisation med flera.
 *
 * Inget soft delete: kontolivscykeln går via `status`
 * (`active` | `read_only` | `closed`), inte via `deleted_at`.
 */
#[Fillable(['type', 'name', 'locale', 'timezone', 'unit_system', 'status', 'read_only_reason'])]
#[RouteKey('ulid')]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `account`, inte Eloquents standardplural `accounts`.
     */
    protected $table = 'account';

    /**
     * Kontots medlemmar. Rollen (`owner` | `admin` | `member`) lagras på
     * kopplingstabellen `account_user`, se [[Konton och åtkomst]]
     * § account_user.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Kontots prenumeration, om den finns — högst en rad, se issue 25 §
     * Beslut 5. Bara relationen läggs till här; uppslaget som 27, 28 och 29
     * behöver är currentPlan(), nedan.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Den plan som gäller för kontot just nu — aldrig null.
     *
     * Reglerna, i ordning (issue 25 § Beslut 6): inga subscription-rad →
     * `free`; `active` → prenumerationens plan; `past_due` → prenumerationens
     * plan (en utebliven betalning ger read_only och frist, inte en omedelbar
     * krympning av kvoten — nedgraderingen är issue 28); `cancelled` → `free`.
     *
     * Saknas free-planen är systemet trasigt och det ska märkas — kasta
     * hellre än att returnera null, annars ärver varje kontroll i 27 en
     * nullkontroll som ingen kommer att skriva.
     */
    public function currentPlan(): Plan
    {
        $subscription = $this->subscription;

        if ($subscription !== null && in_array($subscription->status, ['active', 'past_due'], true)) {
            return $subscription->plan;
        }

        return Plan::query()->where('code', 'free')->firstOrFail();
    }

    /**
     * Läs en gräns ur kontots gällande plan. Den enda vägen in i limits för
     * kod utanför Plan, se issue 25 § Beslut 6 och 7.
     */
    public function planLimit(string $key): int|bool|null
    {
        return $this->currentPlan()->planLimit($key);
    }
}
