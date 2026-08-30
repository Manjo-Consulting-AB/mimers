<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ContainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
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
}
