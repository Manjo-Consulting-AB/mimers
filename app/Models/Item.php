<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use App\Support\Access\ItemScope;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * The fundamental unit of the product — everything the user wants to
 * remember is an item, see [[Items och organisation]] § item. This issue
 * builds the table, the model and the CRUD surface; tags (`item_tag`) and
 * item-to-item links are later issues (13b and 14).
 *
 * `container_id`, `category_id`, `cover_attachment_id`, `created_by_user_id`
 * and `created_by_account_id` are deliberately EXCLUDED from `#[Fillable]`:
 * the controller sets them explicitly (from the route, from the validated
 * `category` ULID, from the validated `cover` ULID, and from the token/body
 * respectively), never via mass assignment — same reasoning as
 * `Container::$account_id`. `created_at`/`updated_at` timestamps are set by
 * Eloquent.
 *
 * `model` is a column name here — `$item->model` is a product designation,
 * not an Eloquent model. It is the document's name and does not change.
 */
#[Fillable(['name', 'description', 'notes', 'manufacturer', 'model', 'serial_number', 'purchased_at', 'warranty_until', 'position_note'])]
#[RouteKey('ulid')]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlid, Searchable, SoftDeletes;

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
     * The searchable columns, issue 15b § Beslut 3 — the document's six
     * columns, same as the FULLTEXT index: the five from 13a § Beslut 4 plus
     * `notes`, which issue 96 added to both lists in the same migration. The
     * database driver searches these via Scout's LIKE formulation; the keys
     * are the columns, the values are ignored by the engine.
     *
     * The two lists are documented mirrors and must not drift apart: a column
     * the index carries but this array does not is never searched, and a key
     * here without an index is a full scan. tests/Feature/Item/
     * AnteckningsfaltTest compares them column for column.
     *
     * No #[SearchUsingFullText] on purpose: that attribute makes Scout emit
     * `whereFullText(...)`, which MariaDB handles and SQLite (the in-memory
     * test database) does not — the suite would fall on every search
     * (Beslut 3). The FULLTEXT index therefore stays unused until CI runs
     * against MariaDB or Meilisearch arrives; turning it on is one line.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'notes' => $this->notes,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
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

    /**
     * Itemets bilagor — de uppladdade filerna, se [[Filer och lagring]] §
     * attachment och issue 16a/16b. Listningen i
     * App\Http\Controllers\Api\AttachmentController::index() går genom den
     * här relationen, och det är DEN som scopeBindings() löser `{attachment}`
     * inom `{item}` genom — en bilaga på ett annat item ger 404 (issue 16b §
     * Beslut 1). Mjukraderade bilagor filtreras bort av SoftDeletes globala
     * scope medan raden ligger kvar, redo för papperskorgen (issue 20a).
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Itemets scheman — reglerna för återkommande underhåll, noll eller
     * flera, se [[Scheman och uppgifter]] § schedule och [[ADR-0005 Schema
     * och förekomst]] (issue 21). Listningen i
     * App\Http\Controllers\Api\ScheduleController::index() går genom den här
     * relationen, och det är DEN som scopeBindings() löser `{schedule}`
     * inom `{item}` genom — ett schema på ett annat item ger 404 (issue 21 §
     * Beslut 1). Mjukraderade scheman filtreras bort av SoftDeletes globala
     * scope medan raden ligger kvar.
     *
     * Inga förekomster här: `schedule_occurrence` skapas i issue 22a.
     *
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Itemets utlåningar — historiken plus högst en öppen, se
     * [[Items och organisation]] § loan och issue 76. Listningen i
     * App\Http\Controllers\Api\LoanController::index() går genom den här
     * relationen, och det är DEN som scopeBindings() löser `{loan}` inom
     * `{item}` genom — ett lån på ett annat item ger 404 (issue 76 § Beslut
     * 5). Mjukraderade lån filtreras bort av SoftDeletes globala scope medan
     * raden ligger kvar.
     *
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * The item's cost entries — the history of what it has cost, see
     * [[Items och organisation]] § cost_entry, [[ADR-0016 Kostnadsregistrering]]
     * and issue 45a. The listing in
     * App\Http\Controllers\Api\CostEntryController::index() goes through this
     * relation, and it is THIS one that scopeBindings() resolves `{cost}`
     * within `{item}` through — a cost entry on another item gives 404
     * (issue 45a § Beslut 7). Soft-deleted entries are filtered out by
     * SoftDeletes' global scope while the row stays put.
     *
     * @return HasMany<CostEntry, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(CostEntry::class);
    }

    /**
     * Begränsar frågan till items som bär ALLA taggar i $tagIds — flera
     * taggar kombineras med OCH (issue 15a § Beslut 2). En join mot
     * `item_tag` med `whereIn('tag_id', $ids)`, grupperad på itemets nyckel
     * med `HAVING COUNT(DISTINCT tag_id) = <antal>` (Beslut 3) — inte en
     * `whereHas()` per tagg, som ger en underfråga per filtervärde och gör
     * indexet `(tag_id, item_id)` från 13b § Beslut 1 meningslös.
     *
     * `$tagIds` är LÖPNUMMER, inte ULID:er — kontrollern löser upp ULID:erna
     * inom containern först, en fråga oavsett antal (issue 15a § Beslut 9).
     *
     * @param  Builder<Item>  $query
     * @param  list<int>  $tagIds
     * @return Builder<Item>
     */
    public function scopeWithAllTags(Builder $query, array $tagIds): Builder
    {
        return $query
            ->select('item.*')
            ->join('item_tag', 'item_tag.item_id', '=', 'item.id')
            ->whereIn('item_tag.tag_id', $tagIds)
            ->groupBy('item.id')
            ->havingRaw('COUNT(DISTINCT item_tag.tag_id) = ?', [count($tagIds)]);
    }

    /**
     * Begränsar frågan till items i någon av kategorierna $categoryIds —
     * kategorin själv plus alla ättlingar, som
     * App\Actions\Category\ResolveCategoryDescendants har räknat ut (issue
     * 15a § Beslut 4). Ättlingsupplösningen ligger ALLTID i Actionen,
     * aldrig inlindad i ett scope (Beslut 6).
     *
     * @param  Builder<Item>  $query
     * @param  list<int>  $categoryIds
     * @return Builder<Item>
     */
    public function scopeInCategoryTree(Builder $query, array $categoryIds): Builder
    {
        return $query->whereIn('category_id', $categoryIds);
    }

    /**
     * Begränsar frågan till de items $scope når — issue 73 § Beslut 1. Ett
     * OMFATTANDE omfång (ägarkontots medlem, container-bred grant) lägger
     * inget villkor alls: `itemIds()` svarar `null` och "hela containern"
     * ska inte materialiseras till en `whereIn` med varje löpnummer.
     *
     * Formuleringen bor HÄR och skrivs inte för hand i en controller —
     * två formuleringar av "vad mottagaren når" glider isär precis som två
     * formuleringar av "giltig access" (issue 9a § Beslut 8).
     *
     * Kolumnen kvalificeras (`item.id`, inte `id`): scopet används både på
     * rena item-frågor och i Scouts `query()`-callback, och kostnadsrapporten
     * och exporten joinar mot `item` i issue 74. En otvetydig kolumn är
     * billigare än en join som plötsligt får en tvetydig `id`.
     *
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    public function scopeInScope(Builder $query, ItemScope $scope): Builder
    {
        return $scope->isUnrestricted()
            ? $query
            : $query->whereIn('item.id', $scope->itemIds());
    }

    /**
     * Markeringarna på det här itemet — vilka som har det som favorit, se
     * [[ADR-0042 Designsystemet]] § Konsekvenser och [[M17 Designsystemet]]
     * § 105. Spegeln av App\Models\User::favorites(), och den sida av paret
     * som det omvända indexet `(item_id, user_id)` bär.
     *
     * Relationen är "vilka har märkt itemet", aldrig "itemet är en favorit":
     * en favorit är ett faktum om relationen mellan en användare och ett
     * item, och det finns därför ingen flagga att läsa på itemet självt —
     * och inget fält i App\Http\Resources\ItemResource.
     *
     * @return HasMany<Favorite, $this>
     */
    public function favoritedBy(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Länkarna där det här itemet är från-sidan (`from_item_id`), se
     * [[Items och organisation]] § item_link och issue 14. Tillsammans med
     * linksTo() täcker de LÄSNINGEN i
     * App\Http\Controllers\Api\ItemLinkController::index() — de två
     * relationerna kombineras med union (issue 14 § Beslut 8), så listan
     * aldrig blir N+1. Inget mer: inga `parents()`/`children()`/`related()`-
     * hjälprelationer, och relationerna bäddas inte in i ItemResource (issue
     * 14 § Beslut 9).
     *
     * @return HasMany<ItemLink, $this>
     */
    public function linksFrom(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'from_item_id');
    }

    /**
     * Länkarna där det här itemet är till-sidan (`to_item_id`), se
     * linksFrom() ovan.
     *
     * @return HasMany<ItemLink, $this>
     */
    public function linksTo(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'to_item_id');
    }
}
