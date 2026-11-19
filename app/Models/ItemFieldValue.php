<?php

namespace App\Models;

use Database\Factories\ItemFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ett items värde i ett fält, se [[ADR-0089 Egna fält på items]] § Riktning
 * 3 och 9 och [[Items och organisation]] § item_field_value.
 *
 * En rad per item och fält, och ett tomt värde lagras inte alls: att tömma
 * ett fält tar bort raden. Värdet ligger i EN av `value_text`,
 * `value_number` och `value_date`, vald av fältets typ. Ett tal är alltid
 * `DECIMAL(20,6)`, aldrig ett flyttal — rubriken bär enheten.
 *
 * **Ingen `ulid` och ingen `deleted_at`.** Värdet adresseras med item och
 * fält, inte av klienten, och ett tömt värde är bara borta — versionshistoriken
 * fångar det ([[ADR-0088 Versionshistorik för items]]). Värdena i ett fält
 * som ligger i papperskorgen syns inte medan definitionen är raderad, kommer
 * tillbaka med den och gallras med den.
 *
 * Tabellen skapades i issue 417 men skrivs först i 418.
 */
#[Fillable(['value_text', 'value_number', 'value_date'])]
class ItemFieldValue extends Model
{
    /** @use HasFactory<ItemFieldValueFactory> */
    use HasFactory;

    /**
     * Tabellen heter `item_field_value`, inte Eloquents standardplural.
     */
    protected $table = 'item_field_value';

    /**
     * Ett tal läses som en decimal med sex värdesiffror, ett datum som ett
     * datum — aldrig som en sträng med tidszon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:6',
            'value_date' => 'date',
        ];
    }

    /**
     * Itemet värdet hör till.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Fältdefinitionen värdet är skrivet i.
     *
     * @return BelongsTo<ItemField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(ItemField::class, 'item_field_id');
    }
}
