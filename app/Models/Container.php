<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ContainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
