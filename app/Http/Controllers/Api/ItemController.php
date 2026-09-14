<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\LinkItems;
use App\Actions\Item\ListItems;
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
 * `Gate::authorize()`. Since issue 71 the gates are the item's own, on
 * App\Policies\ItemPolicy, and the ladder decides: `view` reads, `create`
 * adds, `update` changes what is already there, `delete` soft-deletes. Only
 * index() still asks the container (`view`) — that gate decides whether the
 * caller may enter at all, and issue 73 § Beslut 2 filters the ROWS per
 * scope on top of it. A denied gate throws `AuthorizationException`, which
 * bootstrap/app.php maps to `auth.forbidden` (403).
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
     * 10). The query string carries the issue 15a filters and 15b's `q`,
     * all optional and combined with AND; a filter that matches nothing is
     * still 200 with `{"data": []}`. IndexItemRequest has already proved
     * every ULID exists in THIS container and is not soft-deleted — an
     * unknown value is 422 `validation.failed`, never an empty result
     * (issue 15a § Beslut 7).
     *
     * The body moved to App\Actions\Item\ListItems in issue 57a § Beslut 3 —
     * the scope (issue 73 § Beslut 2), the tag lookup and
     * ResolveCategoryDescendants, the Scout branch when `q` is present and
     * the plain Eloquent branch otherwise, the eager loads that keep the
     * list a constant number of queries (issue 13b § Beslut 10) and the
     * absence of a counter (§ Beslut 6) all live there now, and the web
     * item list draws from the same Action. This method is the gate plus
     * the call, so the two surfaces can never diverge.
     */
    public function index(IndexItemRequest $request, Container $container, ListItems $listItems): JsonResponse
    {
        Gate::authorize('view', $container);

        $items = $listItems->handle($request->user(), $container, [
            'tags' => $request->validated('tags'),
            'category' => $request->validated('category'),
            'q' => $request->validated('q'),
        ]);

        return ItemResource::collection($items)->response();
    }

    /**
     * POST /api/containers/{container}/items — 201. The body carries the
     * attribution account explicitly (`account`, an account-ULID), see
     * issue 13a § Beslut 6: there is no server-side "active account" and a
     * user can be a member of several.
     *
     * StoreItemRequest has already proved that `account` EXISTS (otherwise
     * 422 validation.failed), that `category` (if any) belongs to THIS
     * container and is not soft-deleted, and the same for `parent`. The
     * membership check below — `$account->users()->whereKey(...)->exists()`
     * — decides whether THIS user may write in that account's name; a
     * non-membership is 403 `auth.forbidden`, deliberately NOT
     * `Gate::authorize('create', [Container::class, $account])`, which is
     * about creating containers.
     *
     * TWO gates, one per path (issue 71 § Beslut 2). Without `parent` the
     * item lands at the top level and the gate is
     * ContainerPolicy::createItem() — an owner-account member or a
     * CONTAINER-WIDE `create` holder passes, a scope-limited recipient gets
     * 403 (an item grant reaches no root). With `parent` the gate is
     * ItemPolicy::create() against the parent, and the new item is linked
     * as its child in the SAME transaction that already wraps the write:
     * the recipient then extends her own scope, which is exactly what
     * [[ADR-0028 Åtkomst på itemnivå]] § Beslut says `create` may do. The
     * link goes through App\Actions\Item\LinkItems — never a hand-written
     * ItemLink row — so normalization and the cycle check apply.
     *
     * `container_id`, `category_id`, `created_by_user_id` and
     * `created_by_account_id` are set explicitly on the model instance —
     * all deliberately excluded from App\Models\Item#[Fillable], see that
     * class's docblock. `created_by_user_id` always comes from the token,
     * never from the body (§ Beslut 6).
     */
    public function store(StoreItemRequest $request, Container $container, LinkItems $linkItems): JsonResponse
    {
        $parentUlid = $request->validated('parent');

        $parent = $parentUlid !== null
            ? $container->items()->where('ulid', $parentUlid)->firstOrFail()
            : null;

        if ($parent !== null) {
            Gate::authorize('create', $parent);
        } else {
            Gate::authorize('createItem', $container);
        }

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

        $item = new Item($request->safe()->except(['account', 'category', 'tags', 'parent']));

        // The item write and the tag sync share one transaction (issue 13b
        // § Beslut 7): an item saved with half its tagging is a state the
        // user can neither see nor fix. replaceTags() has sync()'s replace
        // semantics but keeps the query count constant, see that method. A
        // new item starts with no tags, so an empty `tags` list needs no
        // sync call.
        DB::transaction(function () use ($item, $container, $category, $account, $request, $tags, $parent, $linkItems) {
            $item->container_id = $container->id;
            $item->category_id = $category?->id;
            $item->created_by_user_id = $request->user()->id;
            $item->created_by_account_id = $account->id;
            $item->save();

            if ($tags->isNotEmpty()) {
                $this->replaceTags($item, $tags);
            }

            if ($parent !== null) {
                $linkItems->handle($parent, $item, 'parent');
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
     *
     * Since issue 71 the gate is the ITEM's `view`, not the container's. An
     * item inside the container but outside the caller's scope gives 403
     * here — issue 73 § Beslut 3 settled it: one code for "known but out of
     * scope" across ten controllers, never a `firstOrFail()` detour to 404,
     * which would only reveal the difference to a caller who already holds
     * the 26-character ULID. The container parameter stays in the signature
     * because `{item}`'s scoped binding is resolved against it
     * (ImplicitRouteBinding).
     */
    public function show(Container $container, Item $item): ItemResource
    {
        Gate::authorize('view', $item);

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
     *
     * Issue 71 § Beslut 3: the gate is `update` on the ITEM, never `create`.
     * A `create` recipient may add, never touch what is already there, and
     * that holds for the WHOLE body — there is deliberately no field-by-field
     * gate: no field on an existing item is one a `create` recipient may
     * change.
     */
    public function update(UpdateItemRequest $request, Container $container, Item $item): ItemResource
    {
        Gate::authorize('update', $item);

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
     *
     * Issue 71 § Beslut 1 and 6: the gate is the item's `delete`, a step of
     * its own on the ladder — a `write` recipient changes an item but does
     * not remove it. `delete` is soft deletion and nothing else; physical
     * pruning stays the owner account's, see [[ADR-0008 Soft delete och
     * papperskorg]], and no new path to forceDelete() is opened here.
     */
    public function destroy(Container $container, Item $item): Response
    {
        Gate::authorize('delete', $item);

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
