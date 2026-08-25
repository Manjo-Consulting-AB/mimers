<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Container\StoreContainerRequest;
use App\Http\Requests\Container\UpdateContainerRequest;
use App\Http\Resources\ContainerResource;
use App\Models\Account;
use App\Models\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för container, se issue 8 § Beslut 6 (rutterna, alla under
 * `auth:sanctum`, se routes/api.php) och § Beslut 7 (resursformatet, se
 * App\Http\Resources\ContainerResource).
 *
 * INGEN behörighetslogik bor här — varje metod som rör en specifik
 * container eller ett specifikt konto anropar bara `Gate::authorize()` och
 * litar på svaret från App\Policies\ContainerPolicy, se issue 8 § Beslut 2
 * och § Att se upp med. `Gate::authorize()` kastar
 * `AuthorizationException` vid nekat svar, vilket bootstrap/app.php mappar
 * till `auth.forbidden` (403) — se AGENTS.md § Felformat i API:et.
 *
 * Undantaget är listan (index): den begränsningen är en FRÅGA ("vilka
 * containers finns"), inte en BEHÖRIGHETSFRÅGA ("får den här användaren
 * röra den här containern") — se issue 8 § Att se upp med, som uttryckligen
 * säger att den hör hemma i en query här eller i ett scope på modellen,
 * inte i policyn.
 */
class ContainerController extends Controller
{
    /**
     * GET /api/containers — containers från ALLA konton den inloggade
     * användaren är medlem i, sorterade på `name` stigande. Ingen
     * paginering i den här issuen, se issue 8 § Beslut 6.
     */
    public function index(Request $request): JsonResponse
    {
        $containers = Container::query()
            ->whereHas('account.users', function ($query) use ($request) {
                $query->whereKey($request->user()->id);
            })
            ->orderBy('name')
            ->get();

        return ContainerResource::collection($containers)->response();
    }

    /**
     * POST /api/containers — 201. Kroppen bär ägarkontot explicit
     * (`account`, ett konto-ULID), se issue 8 § Beslut 8: det finns inget
     * serversidigt begrepp "aktivt konto".
     *
     * StoreContainerRequest har redan bevisat att `account`-ULID:en
     * EXISTERAR (annars 422 validation.failed, innan kontrollern ens
     * nås). Gate::authorize() nedan avgör om DEN HÄR användaren får skapa
     * åt DET HÄR kontot (403 auth.forbidden annars) — se issue 8 §
     * Beslut 8.
     *
     * `account_id` sätts explicit på modellinstansen i stället för att gå
     * via massildelning (`Container::create()`) — kolumnen är medvetet
     * utelämnad ur App\Models\Container#[Fillable], se den klassens
     * docblock.
     */
    public function store(StoreContainerRequest $request): JsonResponse
    {
        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        Gate::authorize('create', [Container::class, $account]);

        $container = new Container($request->safe()->only(['name', 'kind']));
        $container->account_id = $account->id;
        $container->save();

        return (new ContainerResource($container))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/containers/{container} — 200. `{container}` binds mot
     * `ulid` (App\Models\Container#[RouteKey('ulid')]) och SoftDeletes
     * globala scope gör att en mjukraderad rad aldrig löser upp här — en
     * sådan ULID ger `ModelNotFoundException`, som Laravel mappar till
     * `NotFoundHttpException` och bootstrap/app.php i sin tur mappar till
     * `resource.not_found` (404), se issue 8 § Beslut 10.
     */
    public function show(Container $container): ContainerResource
    {
        Gate::authorize('view', $container);

        return new ContainerResource($container);
    }

    /**
     * PATCH /api/containers/{container} — 200. Bara `name` och `kind`,
     * båda valfria — se App\Http\Requests\Container\UpdateContainerRequest
     * och issue 8 § Beslut 9. `account`/`account_id` finns inte i den
     * requestens regler och är därför aldrig med i `validated()`.
     */
    public function update(UpdateContainerRequest $request, Container $container): ContainerResource
    {
        Gate::authorize('update', $container);

        $container->fill($request->validated());
        $container->save();

        return new ContainerResource($container);
    }

    /**
     * DELETE /api/containers/{container} — 204, ingen kropp. Mjuk
     * radering: App\Models\Container använder SoftDeletes, så `delete()`
     * sätter bara `deleted_at`. Papperskorg, återställning och gallring är
     * issue 20, se issue 8 § Beslut 10.
     */
    public function destroy(Container $container): Response
    {
        Gate::authorize('delete', $container);

        $container->delete();

        return response()->noContent();
    }
}
