<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use RuntimeException;

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
     * nullkontroll som ingen kommer att skriva. Undantaget är ett
     * RuntimeException, inte ModelNotFound: en saknad grundrad är ett
     * serverfel (500), aldrig en 404 mot klienten.
     */
    public function currentPlan(): Plan
    {
        $subscription = $this->subscription;

        if ($subscription !== null && in_array($subscription->status, ['active', 'past_due'], true)) {
            $plan = $subscription->plan;

            if ($plan !== null) {
                return $plan;
            }
        }

        return Plan::query()->where('code', 'free')->first()
            ?? throw new RuntimeException('Grundplanen [free] saknas i tabellen plan.');
    }

    /**
     * Läs en gräns ur kontots gällande plan. Den enda vägen in i limits för
     * kod utanför Plan, se issue 25 § Beslut 6 och 7.
     */
    public function planLimit(string $key): int|bool|null
    {
        return $this->currentPlan()->planLimit($key);
    }

    /**
     * Konton vars aktivitet ligger FÖRE $cutoff — kontolivscykelns enda
     * definition av inaktivitet (issue 29 § Beslut 1), anropad av
     * App\Console\AdvancesAccountLifecycle (29a) och av 29b:s raderingsjobb.
     * Formulera villkoret aldrig en andra gång någon annanstans: två
     * formuleringar kan glida isär, och 29b ska använda exakt samma
     * definition som påminnelsen och stängningen.
     *
     * Kontots aktivitet är den senaste aktiviteten bland dess medlemmar —
     * `MAX(user.last_active_at)` över `account_user`. Den härleds och lagras
     * aldrig: en denormaliserad `account.last_active_at`-kolumn vore en
     * andra sanning som kunde säga emot medlemmarnas egna tidsstämplar.
     * `user.last_active_at` skrivs av App\Http\Middleware\UpdateLastActiveAt
     * på varje autentiserat API-anrop, se [[Konton och åtkomst]] § user.
     *
     * `COALESCE` faller tillbaka på `account.created_at` när MAX ger NULL —
     * ett konto utan medlemmar (eller vars medlemmar aldrig gjort ett anrop)
     * har ingen aktivitet alls och räknas som inaktivt sedan kontot
     * skapades. Utan fallbacken vore det osynligt för hela förloppet.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeInactiveSince(Builder $query, Carbon $cutoff): Builder
    {
        return $query->whereRaw(
            'COALESCE('
                .'(SELECT MAX(user.last_active_at)'
                .' FROM account_user'
                .' JOIN user ON user.id = account_user.user_id'
                .' WHERE account_user.account_id = account.id),'
                .' account.created_at) < ?',
            [$cutoff],
        );
    }
}
