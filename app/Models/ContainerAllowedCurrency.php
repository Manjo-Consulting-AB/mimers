<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En valuta som får användas när en kostnad registreras i containern —
 * issue 1254 · [[ADR-0078 Valutor ur ISO 4217]] § 4 och 5.
 *
 * **Raden är en inställning, inte innehåll.** Den har ingen `deleted_at`:
 * att ta bort en valuta ur urvalet raderar raden, och det finns ingenting
 * att ångra i en papperskorg. Den har heller ingen ULID — urvalet följer
 * med containern i `ContainerResource`, och en rad har ingen egen adress
 * utåt. Tabellen syns aldrig som en egen resurs.
 *
 * `container_id` står medvetet UTANFÖR `#[Fillable]`: raden skapas genom
 * relationen (`Container::allowedCurrencies()->create()`), och en klient
 * ska aldrig kunna flytta en rad till en annan container via massildelning.
 * Att urvalet ersätts som en helhet bor i
 * App\Actions\Container\SetAllowedCurrencies.
 */
#[Fillable(['currency'])]
class ContainerAllowedCurrency extends Model
{
    /**
     * Tabellen heter `container_allowed_currency`, inte Eloquents
     * standardplural.
     */
    protected $table = 'container_allowed_currency';

    /**
     * Containern valutan hör till.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
