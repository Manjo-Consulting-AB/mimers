<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ItemVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En ögonblicksbild av ett items egna fält FÖRE en ändring, se [[ADR-0088
 * Versionshistorik för items]] och [[Items och organisation]] § item_version.
 *
 * Raden skrivs av `RecordItemVersion` (issue 412) och läses av itemets
 * historik (issue 413). Den här issuen (411) bygger bara tabellen, modellen
 * och relationen — ingen rad skapas ännu.
 *
 * **Filer versioneras inte.** `cover_attachment_id` pekar på bilagan och
 * `tag_ids` bär taggarnas löpnummer; själva innehållet kopieras aldrig
 * (ADR-0088 Riktning 2). `tag_ids` sorteras när raden skrivs.
 *
 * **`field_values` bär itemets egna fältvärden, nycklade på fältets id**
 * ([[ADR-0089 Egna fält på items]] § Riktning 9): de sex kolumnerna
 * `manufacturer`, `model`, `serial_number`, `position_note`, `purchased_at`
 * och `warranty_until` flyttar dit och versioneras som en klump.
 *
 * Ingen SoftDeletes: en version tas bort för gott (Riktning 6).
 */
#[Fillable([
    'item_id', 'billed_account_id', 'name', 'description', 'notes',
    'field_values', 'category_id', 'cover_attachment_id', 'icon',
    'icon_color', 'tag_ids', 'replaced_by_user_id', 'replaced_by_agent_id',
    'replaced_at',
])]
class ItemVersion extends Model
{
    /** @use HasFactory<ItemVersionFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `item_version`, inte Eloquents standardplural.
     */
    protected $table = 'item_version';

    /**
     * `field_values` och `tag_ids` är JSON-kolumner och läses som arrayer.
     * `replaced_at` är en tidsstämpel som kan jämföras med `created_at`.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_values' => 'array',
            'tag_ids' => 'array',
            'replaced_at' => 'datetime',
        ];
    }

    /**
     * Itemet versionen hör till.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Kontot som betalar för versionen — containerns ägare när versionen
     * skapades (ADR-0088 Riktning 3), inte den som skrev ändringen.
     *
     * @return BelongsTo<Account, $this>
     */
    public function billedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'billed_account_id');
    }

    /**
     * Användaren som skrev ändringen som ersatte versionen.
     *
     * @return BelongsTo<User, $this>
     */
    public function replacedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by_user_id');
    }
}
