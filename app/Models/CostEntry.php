<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\CostEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En kostnadsrad på ett item — vad en sak har kostat, se
 * [[Items och organisation]] § cost_entry, [[ADR-0016 Kostnadsregistrering]]
 * och issue 45a.
 *
 * `amount` är medvetet UTESLUTEN ur `#[Fillable]` (§ Beslut 3): det som
 * kommer in är en STRÄNG i huvudenhet ("1200,50"), det som lagras är ett
 * heltal i minsta enhet (120050), och de två får aldrig råka bli samma
 * tilldelning. Kontrollern sätter `amount` explicit med resultatet från
 * App\Support\Cost\MinorUnits::parse().
 *
 * `container_id`, `item_id` och `created_by_*` är också utanför
 * `#[Fillable]` — de sätts explicit av kontrollern (itemets container och
 * den inloggade användaren), aldrig ur kroppen (§ Beslut 2). Ett item byter
 * aldrig container (issue 21 § Beslut 3), så `container_id` kan
 * denormaliseras från itemet vid sparning.
 *
 * Tabellen heter `cost_entry`, inte Eloquents standardplural `cost_entries`.
 */
#[Fillable(['kind', 'quantity', 'incurred_on', 'currency', 'description', 'counterparty'])]
#[RouteKey('ulid')]
class CostEntry extends Model
{
    /** @use HasFactory<CostEntryFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * Tabellen heter `cost_entry`, inte Eloquents standardplural `cost_entries`.
     */
    protected $table = 'cost_entry';

    /** Riktningen: en utgift (förvalet) eller en intäkt, ADR-0102. */
    public const KIND_EXPENSE = 'expense';

    public const KIND_INCOME = 'income';

    /**
     * Get the attributes that should be cast.
     *
     * `incurred_on` är en DATE-kolumn, castad till datum och serialiserad med
     * `toDateString()` ("2026-04-12") — aldrig `toIso8601String()`, en
     * kostnads dag har ingen tidszon (§ Beslut 13). `amount` är redan
     * heltalet i minsta enhet och castas inte.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'incurred_on' => 'date',
            'quantity' => 'decimal:6',
        ];
    }

    /**
     * Bara utgifter (ADR-0102). Samma form som ContainerAccess::scopeValid().
     *
     * @param  Builder<CostEntry>  $query
     * @return Builder<CostEntry>
     */
    public function scopeExpenses(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.kind', self::KIND_EXPENSE);
    }

    /**
     * Bara intäkter (ADR-0102).
     *
     * @param  Builder<CostEntry>  $query
     * @return Builder<CostEntry>
     */
    public function scopeIncomes(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.kind', self::KIND_INCOME);
    }

    /**
     * Itemet kostnaden gäller — exakt ett, sätts alltid via
     * App\Models\Item::costs() (eller direkt på item_id) och byter aldrig
     * item.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Kontot posten tillskrivs — varvet, inte den anställde, samma regel som
     * Item::createdByAccount(). Läses av CostEntryResource som
     * `created_by_account`; `created_by_user` exponeras aldrig.
     *
     * @return BelongsTo<Account, $this>
     */
    public function createdByAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_id');
    }
}
