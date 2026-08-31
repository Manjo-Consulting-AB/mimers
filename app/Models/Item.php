<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The fundamental unit of the product — everything the user wants to
 * remember is an item, see [[Items och organisation]] § item. This issue
 * builds the table, the model and the CRUD surface; tags (`item_tag`) and
 * item-to-item links are later issues (13b and 14).
 *
 * `container_id`, `category_id`, `created_by_user_id` and
 * `created_by_account_id` are deliberately EXCLUDED from `#[Fillable]`:
 * the controller sets them explicitly (from the route, from a validated
 * `category` ULID, and from the token/body respectively), never via mass
 * assignment — same reasoning as `Container::$account_id`. `created_at`/
 * `updated_at` timestamps are set by Eloquent.
 *
 * `model` is a column name here — `$item->model` is a product designation,
 * not an Eloquent model. It is the document's name and does not change.
 */
#[Fillable(['name', 'description', 'manufacturer', 'model', 'serial_number', 'purchased_at', 'warranty_until', 'position_note'])]
#[RouteKey('ulid')]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * The table is called `item`, not Eloquent's default plural `items`.
     */
    protected $table = 'item';

    /**
     * Get the attributes that should be cast.
     *
     * `purchased_at` and `warranty_until` are DATE columns, cast to date
     * and serialized with `toDateString()` ("2024-05-17") — never
     * `toIso8601String()`, a purchase date has no timezone, see issue 13a
     * § Beslut 5.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'warranty_until' => 'date',
        ];
    }

    /**
     * The container the item belongs to. Every item lives in exactly one
     * container; the route nests `{item}` under `{container}` and resolves
     * it through App\Models\Container::items() (issue 13a § Beslut 1).
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * The category the item belongs to, at most one ([[ADR-0004 Fria taggar
     * och kategorier]]) — or null for an uncategorised item. Referenced by
     * ULID in the API, resolved within the container by the request
     * validation, see issue 13a § Beslut 7.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The account the item is attributed to — the yard, not the employee
     * (issue 13a § Beslut 6). Read by App\Http\Resources\ItemResource as
     * `created_by_account`; `created_by_user` is deliberately not exposed
     * in this issue.
     *
     * @return BelongsTo<Account, $this>
     */
    public function createdByAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_id');
    }

    /**
     * Itemets taggar — flera, till skillnad från kategorin som det finns
     * högst en av, se [[ADR-0004 Fria taggar och kategorier]] och issue 13b.
     * Kopplingen bor i `item_tag` och sätts med itemet, aldrig via egna
     * rutter (issue 13b § Beslut 2).
     *
     * `->withTimestamps()` fyller `created_at`/`updated_at` på pivotraden —
     * kolumnerna finns i migrationen och ska fyllas (issue 13b § Att se upp
     * med). Uppsättningen sätts i klump med samma ersätt-semantik som
     * `sync()` men konstant frågeantal, se
     * App\Http\Controllers\Api\ItemController::replaceTags.
     *
     * SoftDeletes' globala scope gäller genom relationen: en mjukraderad
     * tagg försvinner ur itemets svar medan pivotraden ligger kvar, så en
     * återupplivad tagg kommer tillbaka på sina items (issue 13b § Beslut 9).
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'item_tag')
            ->withTimestamps();
    }
}
