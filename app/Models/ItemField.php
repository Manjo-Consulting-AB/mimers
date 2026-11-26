<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use App\Models\Concerns\RecordsAgentDeletion;
use Database\Factories\ItemFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En fältdefinition som användaren har skapat: en rubrik och en typ, se
 * [[ADR-0089 Egna fält på items]] § Riktning 1–4 och
 * [[Items och organisation]] § item_field.
 *
 * `container_id` är alltid satt — också för ett items egna fält — och
 * `item_id` är NULL för containerns fält och satt för ett items egna. Det är
 * hela skillnaden mellan de två sorterna, och den bor i `item_id`, inte i en
 * egen tabell.
 *
 * **Typen är systemets, rubriken är användarens.** `text`, `number` och
 * `date` bestämmer vad som får skrivas in och gör att tal och datum går att
 * sortera och filtrera. Rubriken betyder ingenting för servern — systemet
 * vet inte vad *Liter* eller *Årgång* är ([[ADR-0004 Fria taggar och
 * kategorier]] § Konsekvenser).
 *
 * `container_id`, `item_id` och `billed_account_id` står UTESLUTNA ur
 * `#[Fillable]` och sätts explicit av App\Actions\Field\CreateField, samma
 * regel som för Category.
 *
 * Gränserna och rubrikkrocken prövas i applikationen
 * (App\Actions\Field\FieldRules), aldrig av ett unikt index: rubriken ska
 * vara unik bland de fält som SYNS på ett item, och den mängden går inte att
 * uttrycka som en kolumnunikhet.
 */
#[Fillable(['label', 'type', 'position'])]
#[RouteKey('ulid')]
class ItemField extends Model
{
    /** @use HasFactory<ItemFieldFactory> */
    use HasFactory, HasUlid, RecordsAgentDeletion, SoftDeletes;

    /**
     * Tabellen heter `item_field`, inte Eloquents standardplural.
     */
    protected $table = 'item_field';

    /**
     * Typerna ett fält kan ha, se [[ADR-0089 Egna fält på items]] § Riktning
     * 1. Samma lista som CHECK-villkoret i migrationen och som
     * valideringsregeln i App\Http\Requests\Field\StoreFieldRequest.
     *
     * @var list<string>
     */
    public const TYPES = ['text', 'number', 'date', 'boolean'];

    /**
     * Containern fältet hör till — satt även för ett items egna fält.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Itemet fältet hör till, eller null för containerns fält.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Fältets värden, en rad per item. Värdena skrivs först i issue 418.
     *
     * @return HasMany<ItemFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ItemFieldValue::class);
    }
}
