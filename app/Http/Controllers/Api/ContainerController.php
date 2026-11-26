<?php

namespace App\Http\Controllers\Api;

use App\Actions\Container\CreateContainer;
use App\Actions\Container\TrashContainer;
use App\Actions\Container\UpdateContainer;
use App\Actions\Template\CreateContainerFromTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Container\StoreContainerRequest;
use App\Http\Requests\Container\UpdateContainerRequest;
use App\Http\Resources\ContainerResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\User;
use App\Support\Template\MimersTemplates;
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
     *
     * Sedan issue 9a § Beslut 8 tas ÄVEN containers med en giltig
     * delegerad `container_access` med — en FRÅGA om vilka containers som
     * finns, inte en behörighetsfråga, så den hör hemma här (eller i ett
     * scope på modellen) och aldrig i App\Policies\ContainerPolicy. Samma
     * villkor (ContainerAccess::scopeValidFor()) som policyns
     * hasContainerAccess() prövar för view(), så de två frågorna aldrig
     * kan glida isär.
     *
     * Åtkomstvillkoret är utbrutet till App\Models\Container::scopeAccessibleBy()
     * (issue 15b § Beslut 4) och delas med fritextsökningen — formulera det
     * inte om här. Det här är en ren utbrytning: beteendet är identiskt,
     * bevisat av ContainerCrudTest och ContainerAtkomstApiTest.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            // Uppföljning på granskningen av PR #43: ContainerResource::toArray()
            // läser $this->account->ulid för varje rad. Utan eager loading
            // gör en lista med N containers N+1 frågor — en extra fråga
            // per rad för ägarkontot. Låst av
            // ContainerCrudTest::it('listningen laddar ägarkontot i förväg').
            //
            // Bilden (issue 159) är samma sak en gång till: `cover` läser
            // `coverAttachment.storedFile.derivatives`, och utan den här
            // raden hade varje container MED bild kostat två frågor till.
            // Containrar utan bild kostar ingenting — en nullbar främmande
            // nyckel slår inte upp något.
            ->with(['account', 'coverAttachment.storedFile.derivatives', 'allowedCurrencies'])
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
     *
     * Sedan issue 54 § Beslut 3 är skrivningen utbruten till
     * App\Actions\Container\CreateContainer, som webben anropar på samma
     * sätt. Det här är en REN utbrytning — samma 201, samma resurs, samma
     * felkoder, samma antal frågor och samma ordning behörighet-före-kvot
     * (issue 27 § Beslut 3). `Gate::authorize()` står kvar här, utanför
     * actionen, så att API:ets 403 och webbens 403 kommer från samma ställe.
     *
     * **En valfri mall fyller containern** (issue 421 § 5): skickas `template`
     * (en mall-ULID) eller `mimers_template` (nyckeln för en av Mimers mallar)
     * skapas containern genom App\Actions\Template\CreateContainerFromTemplate,
     * som anropar samma CreateContainer och därefter lägger in kategorierna och
     * fälten i samma transaktion. `template` prövas mot användarens konton
     * redan i StoreContainerRequest; det får ingen annan läsa.
     */
    public function store(
        StoreContainerRequest $request,
        CreateContainer $createContainer,
        CreateContainerFromTemplate $createContainerFromTemplate,
    ): JsonResponse {
        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        Gate::authorize('create', [Container::class, $account]);

        $ulid = $request->validated('template');
        $mimersKey = $request->validated('mimers_template');

        $template = $ulid !== null
            ? $this->templateForUser($request->user(), $ulid)
            : ($mimersKey === null ? null : app(MimersTemplates::class)->find($mimersKey));

        // En sparad mall eller en av Mimers fyller den nya containern med en
        // kopia av kategorier och fält ([[ADR-0089 Egna fält på items]]
        // § Riktning 6, [[ADR-0101 Mallarna]]). Utan någondera är vägen
        // oförändrad — samma CreateContainer, samma 201, samma resurs.
        $container = $template !== null
            ? $createContainerFromTemplate->handle(
                $request->user(),
                $account,
                $request->validated('name'),
                $request->validated('kind'),
                $request->validated('description'),
                $request->validated('icon'),
                $request->validated('icon_color'),
                $template,
            )
            : $createContainer->handle(
                $request->user(),
                $account,
                $request->validated('name'),
                $request->validated('kind'),
                $request->validated('description'),
                // Ikonen är ett val vid skapandet när klienten skickar den
                // (issue 314 · [[ADR-0064 Containerns ikon]] § 2).
                $request->validated('icon'),
                $request->validated('icon_color'),
            );

        return (new ContainerResource($container))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Mallen i kroppen, prövad mot användarens konton — samma fråga som
     * routebindningen ställer för `/api/templates/{template}`. StoreContainerRequest
     * har redan bevisat att ULID:en finns och tillhör henne; `firstOrFail()`
     * här är det försvar som gör att ett ändrat svar aldrig kan läsa någon
     * annans mall.
     */
    private function templateForUser(User $user, string $ulid): ContainerTemplate
    {
        return ContainerTemplate::query()
            ->memberOf($user)
            ->where('ulid', $ulid)
            ->firstOrFail();
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

        // En enda rad — inte N+1, men laddar ägarkontot och bilden
        // uttryckligen ändå så ContainerResource aldrig kör en oplanerad
        // lazy-load-fråga, samma resonemang som index() (PR #43-uppföljningen
        // och issue 159 — `cover` läses ovillkorligt av resursen).
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        return new ContainerResource($container);
    }

    /**
     * PATCH /api/containers/{container} — 200. Bara `name` och `kind`,
     * båda valfria — se App\Http\Requests\Container\UpdateContainerRequest
     * och issue 8 § Beslut 9. `account`/`account_id` finns inte i den
     * requestens regler och är därför aldrig med i `validated()`.
     */
    public function update(UpdateContainerRequest $request, Container $container, UpdateContainer $updateContainer): ContainerResource
    {
        Gate::authorize('update', $container);

        $updateContainer->handle($container, $request->user(), $request->validated());

        // Se show() ovan — samma resonemang, en enda rad.
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        return new ContainerResource($container);
    }

    /**
     * DELETE /api/containers/{container} — 204, ingen kropp. Mjuk
     * radering: App\Models\Container använder SoftDeletes, så `delete()`
     * sätter bara `deleted_at`. Papperskorg, återställning och gallring är
     * issue 20, se issue 8 § Beslut 10.
     *
     * Sedan issue 62b § Beslut 2 är skrivningen utbruten till
     * App\Actions\Container\TrashContainer, som webbens raderingsknapp
     * anropar på samma sätt. Det här är en REN utbrytning — samma 204, samma
     * antal frågor och samma radlås, och samma ordning grind-före-skrivning.
     * `Gate::authorize()` står kvar här, utanför actionen, så att API:ets 403
     * och webbens 403 kommer från samma ställe.
     */
    public function destroy(Request $request, Container $container, TrashContainer $trashContainer): Response
    {
        Gate::authorize('delete', $container);

        $trashContainer->handle($container, $request->user());

        return response()->noContent();
    }
}
