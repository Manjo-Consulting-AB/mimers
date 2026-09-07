<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ContainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Det ägda objektet — båten, husvagnen, huset — se [[Konton och åtkomst]] §
 * container och [[ADR-0002 Konto äger container]]. Ägs av exakt ett konto,
 * aldrig en användare.
 *
 * `kind` styr bara presentation och mallval — systemet beter sig aldrig
 * olika beroende på värdet, se issue 8 § Beslut 5. Ingen `match`/`if` på
 * `kind` hör hemma i den här klassen eller i kod som använder den.
 *
 * `account_id` och `template_source_id` är medvetet UTESLUTNA ur
 * `#[Fillable]`: `account_id` kan bara sättas vid skapande (issue 8 §
 * Beslut 9, ägarbyte är issue 39) och `template_source_id` är förberedd för
 * mallar men aldrig påslagen (issue 8 § Att se upp med) — ingendera får
 * sättas via massildelning, vare sig från en request eller ett API-anrop.
 * `App\Http\Controllers\Api\ContainerController::store()` sätter
 * `account_id` explicit efter att `ContainerPolicy::create()` godkänt det.
 */
#[Fillable(['name', 'kind'])]
#[RouteKey('ulid')]
class Container extends Model
{
    /** @use HasFactory<ContainerFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * De giltiga värdena för `kind`, se migrationens CHECK-villkor. Delas
     * mellan FormRequests (App\Http\Requests\Container) och
     * ContainerFactory så listan bara underhålls på ett ställe.
     *
     * @var list<string>
     */
    public const KINDS = ['boat', 'caravan', 'house', 'car', 'other'];

    /**
     * Tabellen heter `container`, inte Eloquents standardplural `containers`.
     */
    protected $table = 'container';

    /**
     * Ägarkontot. Exakt ett, se [[ADR-0002 Konto äger container]].
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Delegerade åtkomster till containern, utöver ägarskapet — se
     * [[Konton och åtkomst]] § container_access och issue 9a. Bara
     * relationen läggs till här; policyn som använder den bor i
     * App\Policies\ContainerPolicy och API-ytan för att bevilja, lista och
     * återkalla är issue 9b.
     *
     * @return HasMany<ContainerAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(ContainerAccess::class);
    }

    /**
     * Inbjudningar att dela containern med någon som ännu inte har konto —
     * se [[Konton och åtkomst]] § invitation och issue 10a. Bara
     * relationen läggs till här; avsändarytan bor i
     * App\Http\Controllers\Api\ContainerInvitationController och
     * mottagarsidan (accept, avvisning, mejlet) är issue 10b.
     *
     * ALLA rader, oavsett `status` — listningen visar även tillbakadragna
     * och utgångna (issue 10a § Beslut 14), och duplikatspärren filtrerar
     * själv på `pending`.
     *
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Containerns kategoriträd, se [[Items och organisation]] § category
     * och issue 11. Bara relationen läggs till här — den behövs för nästlad
     * routebindning: `routes/api.php`s `->scopeBindings()` löser
     * `{category}` genom den HÄR relationen, vilket är hela skyddet mot att
     * en kategori-ULID från container A löses upp under container B (issue
     * 11 § Beslut 1). API-ytan bor i
     * App\Http\Controllers\Api\CategoryController.
     *
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Containerns taggar — platt lista, se [[ADR-0004 Fria taggar och
     * kategorier]] och issue 12. Bara relationen läggs till här; den krävs
     * av `scopeBindings()` i routes/api.php för att en tagg-ULID från en
     * annan container inte ska lösa upp under den här (issue 12 § Beslut
     * 1). API-ytan bor i App\Http\Controllers\Api\TagController.
     *
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * The container's items, see [[Items och organisation]] § item and
     * issue 13a — the table M2 (files), M3 (tasks), M6 (loans) and M8
     * (costs) all hang off. The relation is required by `scopeBindings()`
     * in routes/api.php: `{item}` is resolved through THIS relation, which
     * is the whole protection against an item ULID from container A
     * resolving under container B (issue 13a § Beslut 1). The CRUD surface
     * lives in App\Http\Controllers\Api\ItemController.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Containerns ICS-kalenderfeeds, se [[Notiser]] § ICS-kalenderfeed och
     * issue 36a. Bara relationen läggs till här — den krävs av
     * `scopeBindings()` i routes/api.php för att en feed-ULID från en annan
     * container inte ska lösa upp under den här (issue 36a § Beslut 3),
     * samma mönster som accesses()/invitations() ovan. API-ytan bor i
     * App\Http\Controllers\Api\CalendarFeedController; själva feeden (36b)
     * rör aldrig den här relationen — den slår upp på token_hash direkt.
     *
     * ALLA rader, oavsett `revoked_at` — listningen visar även återkallade
     * feeder, och en användare kan ha flera rader till samma container
     * (Beslut 2).
     *
     * @return HasMany<CalendarFeed, $this>
     */
    public function calendarFeeds(): HasMany
    {
        return $this->hasMany(CalendarFeed::class);
    }

    /**
     * Ägarbytena av containern — se [[Konton och åtkomst]] §
     * ownership_transfer och issue 39a. Bara relationen läggs till här; den
     * krävs av `scopeBindings()` i routes/api.php för att en transfer-ULID
     * från en annan container inte ska lösas upp under den här (Beslut 14),
     * samma mönster som invitations() ovan. ALLA rader, oavsett `status` —
     * avsändarlistan visar även tillbakadragna och utgångna, och
     * dubblettspärren filtrerar själv på `pending`. API-ytan bor i
     * App\Http\Controllers\Api\OwnershipTransferController.
     *
     * @return HasMany<OwnershipTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(OwnershipTransfer::class);
    }

    /**
     * Containerns exporter — beställda fullständiga uttag av innehållet, se
     * [[Backlog]] M6 § 41 och App\Models\Export. Bara relationen läggs till
     * här; den krävs av `scopeBindings()` i routes/api.php för att en
     * export-ULID från en annan container inte ska lösa upp under den här
     * (issue 41 § Beslut 5), samma mönster som transfers() ovan. API-ytan
     * bor i App\Http\Controllers\Api\ExportController, själva bygget i
     * App\Jobs\BuildContainerExport.
     *
     * ALLA rader, oavsett `status` — listningen visar historiken, och
     * duplikatspärren (högst en `pending`/`running` i taget) filtrerar själv
     * på status.
     *
     * @return HasMany<Export, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    /**
     * Begränsar till containers $user når: medlem i ägarkontot (regel 1 i
     * [[Konton och åtkomst]] § Behörighetsregler) ELLER en giltig
     * `container_access` som träffar henne eller ett av hennes konton
     * (regel 2, via ContainerAccess::scopeValidFor()).
     *
     * Utbrutet ur App\Http\Controllers\Api\ContainerController::index() i
     * issue 15b § Beslut 4, som den här metoden anropas från — och från
     * fritextsökningen (App\Http\Controllers\Api\ItemSearchController), som
     * behöver exakt samma villkor. Formulera INTE "containers jag når" en
     * andra gång någon annanstans — två formuleringar av åtkomstvillkoret
     * kan glida isär, och en sökning som läcker mellan containers är en
     * allvarlig incident ([[ADR-0012 Sök]] § Konsekvenser). Samma slags
     * utbrytning, av samma skäl, som ContainerAccess::scopeValid() i issue
     * 9c § Beslut 5.
     *
     * `$accountIds` ska vara löpnumren (inte ULID:erna) för de konton
     * $user är medlem i — anroparen hämtar dem med
     * `$user->accounts->pluck('id')`. SoftDeletes' globala scope gäller
     * här: en mjukraderad container matchar aldrig, eftersom scopet läggs
     * på samma byggare (issue 15b § Att se upp med).
     *
     * @param  Builder<Container>  $query
     * @param  list<int>  $accountIds
     * @return Builder<Container>
     */
    public function scopeAccessibleBy(Builder $query, User $user, array $accountIds): Builder
    {
        return $query->where(function (Builder $query) use ($user, $accountIds) {
            $query->whereHas('account.users', function (Builder $query) use ($user) {
                $query->whereKey($user->id);
            })->orWhereHas('accesses', function (Builder $query) use ($user, $accountIds) {
                /** @var Builder<ContainerAccess> $query */
                $query->validFor($user, $accountIds);
            });
        });
    }
}
