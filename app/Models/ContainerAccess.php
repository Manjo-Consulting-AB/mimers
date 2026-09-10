<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ContainerAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En delegerad åtkomst till en container, utöver ägarskapet
 * (`container.account_id`) — se [[Konton och åtkomst]] § container_access
 * och [[ADR-0003 Åtkomstmodell]]. `kind` (`member` | `managed` | `guest`)
 * styr bara presentation, se issue 9a § Beslut 6: ingen `match`/`if` på
 * `kind` hör hemma i den här klassen, i App\Policies\ContainerPolicy eller i
 * kod som använder den — bara `level` avgör behörighet.
 *
 * `grantee_type`/`grantee_id` pekar polymorft på `User` eller `Account`
 * utan främmandenyckel, se migrationens docblock och issue 9a § Beslut 3.
 * Ingen `grantee()`-relation finns medvetet — Eloquents `morphTo()` hade
 * krävt en morph-map för strängarna `user`/`account` utan att lösa
 * N+1-problemet App\Policies\ContainerPolicy måste undvika (issue 9a §
 * Att se upp med), så uppslagningen görs som en platt fråga i stället, se
 * scopeValidFor() nedan.
 *
 * `container_id`, `item_id` och `granted_by_user_id` är medvetet UTESLUTNA
 * ur `#[Fillable]`, samma resonemang som `Container::$account_id` — de
 * sätts explicit av den kod som skapar raden (issue 9b, och för `item_id`
 * issue 72), aldrig via massildelning. `revoked_at` sätts av en dedikerad
 * återkallningsåtgärd (issue 9b), inte via `fill()`, och är därför inte
 * heller `#[Fillable]`.
 */
#[Fillable(['grantee_type', 'grantee_id', 'level', 'kind', 'expires_at'])]
#[RouteKey('ulid')]
class ContainerAccess extends Model
{
    /** @use HasFactory<ContainerAccessFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `container_access`, inte Eloquents standardplural
     * `container_accesses`.
     */
    protected $table = 'container_access';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Containern åtkomsten gäller.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Itemet åtkomsten är avgränsad till, eller NULL för en container-bred
     * åtkomst — se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och
     * [[Konton och åtkomst]] § container_access. Relationen behövs av
     * issue 72:s förvaltningsvy; i den här issuen är kolumnen alltid NULL.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Den som beviljade åtkomsten. Obligatorisk, se issue 9a § Att se upp
     * med: "granted_by_user_id är obligatorisk."
     *
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * Begränsar till rader som är GILTIGA (ej återkallade, ej utgångna) —
     * regel 2 i [[Konton och åtkomst]] § Behörighetsregler, utan att knytas
     * till en viss mottagare. `expires_at` NULL betyder "går aldrig ut", se
     * issue 9a § Att se upp med.
     *
     * Utbrutet ur scopeValidFor() i issue 9c § Beslut 5, som anropar det
     * nedan: deltagarlistan behöver samma villkor utan mottagarfiltret.
     * Formulera INTE villkoret en andra gång någon annanstans — två
     * formuleringar av "giltig" kan glida isär, se issue 9a § Beslut 8.
     *
     * @param  Builder<ContainerAccess>  $query
     * @return Builder<ContainerAccess>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Begränsar till rader som är GILTIGA (ej återkallade, ej utgångna,
     * regel 2 — via scopeValid() ovan) och som träffar $user på någon av de
     * två vägarna i issue 9a
     * § Beslut 5: hens egen rad (`grantee_type = user`) eller en rad på ett
     * konto hon är medlem i (`grantee_type = account`, `$accountIds`).
     *
     * Delad mellan App\Policies\ContainerPolicy och
     * App\Http\Controllers\Api\ContainerController::index() så de två
     * frågorna aldrig kan glida isär — se issue 9a § Beslut 8 och § Att se
     * upp med ("uppslagningen får inte bli N+1").
     *
     * Vet INGET om kontostatus (regel 4/9) — anroparen skickar in redan
     * filtrerade `$accountIds` när ett `read_only`-konto som mottagare ska
     * uteslutas (App\Policies\ContainerPolicy::update()), och den ofiltrerade
     * listan när det inte spelar roll (läsning, se regel 4: "läsning
     * påverkas aldrig").
     *
     * @param  Builder<ContainerAccess>  $query
     * @param  list<int>  $accountIds
     * @return Builder<ContainerAccess>
     */
    public function scopeValidFor(Builder $query, User $user, array $accountIds): Builder
    {
        return $query
            ->valid()
            ->where(function (Builder $q) use ($user, $accountIds) {
                $q->where(fn (Builder $q2) => $q2->where('grantee_type', 'user')->where('grantee_id', $user->id))
                    ->orWhere(fn (Builder $q2) => $q2->where('grantee_type', 'account')->whereIn('grantee_id', $accountIds));
            });
    }
}
