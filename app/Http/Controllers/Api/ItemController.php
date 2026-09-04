<?php

namespace App\Http\Controllers\Api;

use App\Actions\Category\ResolveCategoryDescendants;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\IndexItemRequest;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The CRUD surface for item, see issue 13a § Beslut 1 and 2. The five
 * routes are nested under `{container}` in routes/api.php and use the
 * group's `scopeBindings()` — `{item}` is resolved through
 * App\Models\Container::items(), the whole protection against an item ULID
 * from another container resolving here (§ Beslut 1).
 *
 * NO authorization logic lives here — every method only calls
 * `Gate::authorize()` against the EXISTING gates `view` (index, show) and
 * `update` (store, update, destroy) on App\Policies\ContainerPolicy. Not
 * `delete` — that means "may delete the container" and would lock out
 * every `write` participant, see § Beslut 2. A denied gate throws
 * `AuthorizationException`, which bootstrap/app.php maps to
 * `auth.forbidden` (403).
 *
 * The one non-policy check is the membership test in store(): whether the
 * user may act in the name of the account given in the body (§ Beslut 6).
 * That is not an authorization rule about the container but a check that
 * the user is allowed to trade under the stated account's name.
 */
class ItemController extends Controller
{
    /**
     * GET /api/containers/{container}/items — 200. Lists the container's
     * items, sorted by `name` ascending, no pagination (issue 13a § Beslut
     * 10). The query string may carry the issue 15a filters, both optional
     * and combined with AND: `tags[]` (every tag required, § Beslut 2) and
     * `category` (the category and its whole subtree, § Beslut 4). Since
     * issue 15b § Beslut 5 it also carries `q` (optional, `sometimes` in
     * IndexItemRequest), a free-text search over the five searchable
     * columns that combines with the tag/category filters with AND.
     * IndexItemRequest has already proved every ULID exists in THIS
     * container and is not soft-deleted — an unknown value is 422
     * `validation.failed`, never an empty result (§ Beslut 7). A filter
     * that matches nothing is still 200 with `{"data": []}`.
     *
     * When `q` is present the search goes through Scout's database driver
     * (issue 15b § Beslut 3) with the container and the 15a filters applied
     * in the `query()` callback; the plain Eloquent path below is unchanged
     * when it is not. `category`, `createdByAccount` and `tags` are
     * eager-loaded so ItemResource never triggers an unplanned lazy-load
     * per row — the list stays a constant number of queries regardless of
     * item count (issue 13b § Beslut 10) and of the number of filter values
     * or the depth of the category tree (issue 15a § Beslut 9).
     */
    public function index(IndexItemRequest $request, Container $container, ResolveCategoryDescendants $resolveCategoryDescendants): JsonResponse
    {
        Gate::authorize('view', $container);

        $tagUlids = $request->validated('tags');
        $tagIds = [];

        if ($tagUlids !== null && $tagUlids !== []) {
            // IndexItemRequest has already proved each ULID exists in THIS
            // container and is not soft-deleted; the container-scoped
            // lookup below is what keeps the query correct even without
            // that gate (issue 15a § Att se upp med).
            $tagIds = Tag::whereIn('ulid', $tagUlids)
                ->where('container_id', $container->id)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
        }

        $categoryUlid = $request->validated('category');
        $categoryIds = null;

        if ($categoryUlid !== null) {
            $category = $container->categories()->where('ulid', $categoryUlid)->firstOrFail();
            $categoryIds = $resolveCategoryDescendants->handle($category);
        }

        $q = $request->validated('q');

        if ($q !== null && $q !== '') {
            $items = Item::search($q)
                ->query(function (Builder $query) use ($container, $tagIds, $categoryIds) {
                    /** @var Builder<Item> $query */
                    $query->where('container_id', $container->id)
                        ->with(['category', 'createdByAccount', 'tags']);

                    if ($tagIds !== []) {
                        $query->withAllTags($tagIds);
                    }

                    if ($categoryIds !== null) {
                        $query->inCategoryTree($categoryIds);
                    }
                })
                ->orderBy('name')
                ->get();
        } else {
            $query = $container->items()->with(['category', 'createdByAccount', 'tags']);

            if ($tagIds !== []) {
                $query->withAllTags($tagIds);
            }

            if ($categoryIds !== null) {
                $query->inCategoryTree($categoryIds);
            }

            $items = $query->orderBy('name')->get();
        }

        return ItemResource::collection($items)->response();
    }

    /**
     * POST /api/containers/{container}/items — 201. The body carries the
     * attribution account explicitly (`account`, an account-ULID), see
     * issue 13a § Beslut 6: there is no server-side "active account" and a
     * user can be a member of several.
     *
     * StoreItemRequest has already proved that `account` EXISTS (otherwise
     * 422 validation.failed) and that `category` (if any) belongs to THIS
     * container and is not soft-deleted. The membership check below —
     * `$account->users()->whereKey(...)->exists()` — decides whether THIS
     * user may write in that account's name; a non-membership is 403
     * `auth.forbidden`, deliberately NOT `Gate::authorize('create',
     * [Container::class, $account])`, which is about creating containers.
     *
     * `container_id`, `category_id`, `created_by_user_id` and
     * `created_by_account_id` are set explicitly on the model instance —
     * all deliberately excluded from App\Models\Item#[Fillable], see that
     * class's docblock. `created_by_user_id` always comes from the token,
     * never from the body (§ Beslut 6).
     */
    public function store(StoreItemRequest $request, Container $container): JsonResponse
    {
        Gate::authorize('update', $container);

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            throw ApiException::make('auth.forbidden', [], 403);
        }

        $categoryUlid = $request->validated('category');
        $category = $categoryUlid !== null
            ? $container->categories()->where('ulid', $categoryUlid)->firstOrFail()
            : null;

        // The tags are looked up ONCE, before the transaction: the request
        // has already proven each ULID exists in THIS container and is not
        // soft-deleted (StoreItemRequest § Beslut 5), so the lookup is
        // trusted. In bulk — one query no matter how many tags, same rule
        // as 9c § Beslut 8, see issue 13b § Beslut 6.
        $tags = $request->has('tags')
            ? Tag::whereIn('ulid', $request->validated('tags'))->get()
            : collect();

        $item = new Item($request->safe()->except(['account', 'category', 'tags']));

        // The item write and the tag sync share one transaction (issue 13b
        // § Beslut 7): an item saved with half its tagging is a state the
        // user can neither see nor fix. replaceTags() has sync()'s replace
        // semantics but keeps the query count constant, see that method. A
        // new item starts with no tags, so an empty `tags` list needs no
        // sync call.
        DB::transaction(function () use ($item, $container, $category, $account, $request, $tags) {
            $item->container_id = $container->id;
            $item->category_id = $category?->id;
            $item->created_by_user_id = $request->user()->id;
            $item->created_by_account_id = $account->id;
            $item->save();

            if ($tags->isNotEmpty()) {
                $this->replaceTags($item, $tags);
            }
        });

        // $account, $category and $tags are already in hand above — set the
        // relations directly instead of letting ItemResource trigger new
        // queries for the same rows, see index() on N+1.
        $item->setRelation('category', $category);
        $item->setRelation('createdByAccount', $account);
        $item->setRelation('tags', $tags);

        return (new ItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/containers/{container}/items/{item} — 200. `{item}` binds
     * against `ulid` (App\Models\Item#[RouteKey('ulid')]) and is scoped to
     * the container by `scopeBindings()`; a soft-deleted row never resolves
     * (SoftDeletes' global scope) — either gives 404 `resource.not_found`.
     */
    public function show(Container $container, Item $item): ItemResource
    {
        Gate::authorize('view', $container);

        // A single row, but eager-load explicitly so ItemResource never
        // runs an unplanned lazy-load query, same reasoning as index().
        $item->loadMissing(['category', 'createdByAccount', 'tags']);

        return new ItemResource($item);
    }

    /**
     * PATCH /api/containers/{container}/items/{item} — 200. Only the
     * documented fields, all optional. `category` changes only when the KEY
     * is present in the body (`$request->has()`, never `filled()`): an
     * omitted `category` leaves it untouched, `category: null` clears it (§
     * Beslut 7). `tags` replaces the whole set the same way (issue 13b §
     * Beslut 4): `has('tags')`, never `filled()`, so `tags: []` really
     * clears — see replaceTags() below. `created_by_*` is never in the
     * request's rules, so it can not be changed here (§ Beslut 6).
     */
    public function update(UpdateItemRequest $request, Container $container, Item $item): ItemResource
    {
        Gate::authorize('update', $container);

        $item->fill($request->safe()->except(['category', 'tags']));

        if ($request->has('category')) {
            $categoryUlid = $request->validated('category');
            $category = $categoryUlid !== null
                ? $container->categories()->where('ulid', $categoryUlid)->firstOrFail()
                : null;

            $item->category_id = $category?->id;
        }

        // Item write and tag sync in one transaction, same reasoning as
        // store() (issue 13b § Beslut 7). The tag ULID → id lookup is in
        // bulk, one query regardless of count (§ Beslut 6). replaceTags()
        // runs whenever the KEY is present — including `tags: []`, which
        // clears.
        DB::transaction(function () use ($item, $request) {
            $item->save();

            if ($request->has('tags')) {
                $this->replaceTags($item, Tag::whereIn('ulid', $request->validated('tags'))->get());
            }
        });

        // See show() above — same reasoning, a single row. `tags` is loaded
        // AFTER the sync so the response reflects the new set.
        $item->loadMissing(['category', 'createdByAccount', 'tags']);

        return new ItemResource($item);
    }

    /**
     * DELETE /api/containers/{container}/items/{item} — 204, no body. Soft
     * deletion: App\Models\Item uses SoftDeletes, so `delete()` only sets
     * `deleted_at`. The trash, restore and pruning are issue 20, see issue
     * 13a § Beslut 9.
     */
    public function destroy(Container $container, Item $item): Response
    {
        Gate::authorize('update', $container);

        $item->delete();

        return response()->noContent();
    }

    /**
     * Replaces the item's tag set with $tags, keeping sync()'s replace
     * semantics (an omitted id is detached, a new one attached) but a
     * CONSTANT number of queries no matter how many tags (issue 13b §
     * Beslut 6). BelongsToMany::sync() attaches one pivot row per query —
     * an INSERT per new tag. This diffs against the current pivot rows and
     * then attach()/detach() the whole side at once: Eloquent batches the
     * list into a single multi-row INSERT / DELETE regardless of count.
     *
     * The current set is read straight from the pivot table, NOT through
     * the `tags()` relation — the relation applies SoftDeletes' global
     * scope and would hide the pivot rows of soft-deleted tags that sync()
     * still sees (issue 13b § Beslut 3).
     */
    private function replaceTags(Item $item, Collection $tags): void
    {
        $desired = $tags->pluck('id')->all();
        $current = DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all();

        $toAttach = array_values(array_diff($desired, $current));
        $toDetach = array_values(array_diff($current, $desired));

        if ($toAttach !== []) {
            $item->tags()->attach($toAttach);
        }

        if ($toDetach !== []) {
            $item->tags()->detach($toDetach);
        }
    }
}
