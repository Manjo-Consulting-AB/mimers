<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En kategori i containerns hierarki, se [[Items och organisation]] §
 * category och [[ADR-0004 Fria taggar och kategorier]]. Ett item tillhör
 * högst en kategori — det är hela skillnaden mot taggar, se issue 11.
 *
 * `container_id` och `parent_id` är medvetet UTESLUTNA ur `#[Fillable]`,
 * samma resonemang som `App\Models\Container#account_id`:
 * `App\Http\Controllers\Api\CategoryController` slår upp föräldern (om
 * någon) via ULID och sätter båda fälten explicit på modellinstansen,
 * efter att `App\Http\Requests\Category\StoreCategoryRequest`/
 * `UpdateCategoryRequest` bevisat att förälder-ULID:en existerar INOM
 * containern (issue 11 § Beslut 10). Ingendera får sättas via
 * massildelning.
 *
 * `MAX_DEPTH`: roten ligger på nivå 1, en femte nivå är den sista som får
 * skapas — se issue 11 § Beslut 4. Djup och cykler räknas i minnet av
 * App\Actions\Category\MoveCategory, aldrig med rekursiv SQL, se § Beslut
 * 8.
 */
#[Fillable(['name', 'position'])]
#[RouteKey('ulid')]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * Djupgränsen, se issue 11 § Beslut 4. En kategori utan förälder ligger
     * på nivå 1.
     */
    public const MAX_DEPTH = 5;

    /**
     * Tabellen heter `category`, inte Eloquents standardplural `categories`.
     */
    protected $table = 'category';

    /**
     * Containern kategorin hör till. En kategori bor i exakt en container,
     * aldrig delad mellan containers, se issue 11 § Beslut 9 punkt 1.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Föräldern i hierarkin, eller null för en rotkategori.
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Direkta barn, icke-mjukraderade (Eloquents globala SoftDeletes-scope
     * filtrerar automatiskt). Används av
     * App\Http\Controllers\Api\CategoryController::destroy() för
     * `category.has_children`, se issue 11 § Beslut 7 — inte av
     * App\Actions\Category\MoveCategory, som läser hela trädet i en fråga
     * i stället (§ Beslut 8).
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Items pointing at this category, none of them soft-deleted (Eloquent's
     * global SoftDeletes scope filters automatically). Used by
     * App\Http\Controllers\Api\CategoryController::destroy() for the
     * `category.has_items` check, see issue 13a § Beslut 9 — a category
     * that classifies at least one item cannot be deleted.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
