<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En platt tagg, per container — se [[Items och organisation]] § tag och
 * [[ADR-0004 Fria taggar och kategorier]]. Ingen förälder, inget djup:
 * kategorin är var saken hör hemma, taggen är allt annat man vill kunna
 * filtrera på. Skriv aldrig en trädlogik ovanpå den här modellen.
 *
 * `container_id` är medvetet UTESLUTEN ur `#[Fillable]` — sätts explicit på
 * modellinstansen i App\Http\Controllers\Api\TagController efter att
 * containern lästs från rutten, aldrig via massildelning.
 *
 * Ingen `items()`-relation här — kopplingen till item (`item_tag`) hör till
 * issue 13b, se issue 12 § Omfång.
 */
#[Fillable(['name', 'color'])]
#[RouteKey('ulid')]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * Tabellen heter `tag`, inte Eloquents standardplural `tags`.
     */
    protected $table = 'tag';

    /**
     * Containern taggen hör till. Exakt en, se [[ADR-0004 Fria taggar och
     * kategorier]].
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Taggens items — omvänd riktning på Item::tags(), samma tabell
     * `item_tag`, se issue 13b. Kopplingarna ligger kvar när taggen
     * mjukraderas, så en återupplivad tagg kommer tillbaka på sina items
     * (issue 13b § Beslut 3 och 9). SoftDeletes' globala scope gäller även
     * här: bara icke-mjukraderade items räknas med.
     *
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_tag')
            ->withTimestamps();
    }
}
