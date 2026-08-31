<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
     * 10). `category` and `createdByAccount` are eager-loaded so
     * ItemResource never triggers an unplanned lazy-load per row — the
     * list stays a constant number of queries regardless of item count.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('view', $container);

        $items = $container->items()
            ->with(['category', 'createdByAccount'])
            ->orderBy('name')
            ->get();

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

        $item = new Item($request->safe()->except(['account', 'category']));
        $item->container_id = $container->id;
        $item->category_id = $category?->id;
        $item->created_by_user_id = $request->user()->id;
        $item->created_by_account_id = $account->id;
        $item->save();

        // $account and $category are already in hand above — set the
        // relations directly instead of letting ItemResource trigger new
        // queries for the same rows, see index() on N+1.
        $item->setRelation('category', $category);
        $item->setRelation('createdByAccount', $account);

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
        $item->loadMissing(['category', 'createdByAccount']);

        return new ItemResource($item);
    }

    /**
     * PATCH /api/containers/{container}/items/{item} — 200. Only the
     * documented fields, all optional. `category` changes only when the KEY
     * is present in the body (`$request->has()`, never `filled()`): an
     * omitted `category` leaves it untouched, `category: null` clears it (§
     * Beslut 7). `created_by_*` is never in the request's rules, so it can
     * not be changed here (§ Beslut 6).
     */
    public function update(UpdateItemRequest $request, Container $container, Item $item): ItemResource
    {
        Gate::authorize('update', $container);

        $item->fill($request->safe()->except(['category']));

        if ($request->has('category')) {
            $categoryUlid = $request->validated('category');
            $category = $categoryUlid !== null
                ? $container->categories()->where('ulid', $categoryUlid)->firstOrFail()
                : null;

            $item->category_id = $category?->id;
        }

        $item->save();

        // See show() above — same reasoning, a single row.
        $item->loadMissing(['category', 'createdByAccount']);

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
}
