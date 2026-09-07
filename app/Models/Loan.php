<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En utlåning — markera ett item som utlånat och hålla reda på vem som har
 * det och när det skulle vara tillbaka, se [[Items och organisation]] § loan
 * och issue 76.
 *
 * `item_id` är medvetet UTESLUTEN ur `#[Fillable]` — sätts explicit på
 * modellinstansen i App\Http\Controllers\Api\LoanController efter att itemet
 * lästs från rutten, aldrig via massildelning. Samma mönster som Schedule gör
 * (issue 21).
 *
 * `borrower_email` finns för att utlånaren ska ha adressen framme när hen
 * själv tar kontakt — den läses ALDRIG som mottagaradress för utskick, vare
 * sig här eller senare (issue 76 § Beslut 8, [[ADR-0017 Missbruksvektorer]]
 * § 7). Fältet får inte "fixas" till att mejla låntagaren.
 */
#[Fillable(['borrower_name', 'borrower_email', 'lent_at', 'due_at', 'returned_at', 'note'])]
#[RouteKey('ulid')]
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * Tabellen heter `loan`, inte Eloquents standardplural `loans`.
     */
    protected $table = 'loan';

    /**
     * Get the attributes that should be cast.
     *
     * `lent_at`, `due_at` och `returned_at` är DATE-kolumner, castade till
     * datum och serialiserade med `toDateString()` ("2026-09-07") — aldrig
     * `toIso8601String()`, en utlåningsdag har ingen tidszon (issue 76 §
     * Beslut 1).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lent_at' => 'date',
            'due_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    /**
     * Itemet lånet gäller — exakt ett, sätts alltid via
     * App\Models\Item::loans() (eller direkt på item_id) och byter aldrig
     * item. Itemet i sin tur byter aldrig container (issue 21 § Beslut 3),
     * så containern härleds alltid ur itemet.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
