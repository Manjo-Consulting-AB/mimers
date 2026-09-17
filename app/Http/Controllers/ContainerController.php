<?php

namespace App\Http\Controllers;

use App\Actions\Container\CreateContainer;
use App\Actions\Container\TrashContainer;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Container\StoreContainerRequest;
use App\Http\Requests\Container\UpdateContainerRequest;
use App\Http\Resources\ContainerResource;
use App\Models\Account;
use App\Models\Container;
use App\Support\Frontend\ActiveContainer;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens containeryta — listan, skapandet och redigeringen, se issue 54
 * § Beslut 1, 2, 3 och 9.
 *
 * **Den första webbytan mot en domänresurs `/api` redan äger.** Ingenting av
 * det API:et byggde görs om här: `StoreContainerRequest`,
 * `UpdateContainerRequest` och `ContainerResource` delas rakt av
 * ([[ADR-0021 Frontendteknik]] § Beslut), och skrivningen går genom
 * App\Actions\Container\CreateContainer — samma action som
 * App\Http\Controllers\Api\ContainerController::store() anropar (issue 54
 * § Beslut 3).
 *
 * **Ingen behörighetslogik bor här.** Varje metod som rör en specifik
 * container eller ett specifikt konto anropar bara `Gate::authorize()`
 * respektive `Gate::allows()` och litar på App\Policies\ContainerPolicy,
 * precis som API-kontrollern gör. Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som felsidan för
 * 403 på webben.
 *
 * **Ingen `show()`.** Containerns egen sida är itemlistan och den är issue 57 —
 * se issue 54 § Beslut 2. En tom detaljvy nu blir en sida 57 skriver om
 * ändå, och två sidor som slåss om samma URL är dyrare än en URL som ännu
 * inte finns.
 *
 * **`destroy()` kom med issue 62b**, tillsammans med papperskorgen som
 * återställer raden — en raderingsknapp utan en väg tillbaka är en fälla, och
 * vägen tillbaka byggs därför i samma issue som knappen (62b § Beslut 4, 5
 * och 6). Skrivningen går genom App\Actions\Container\TrashContainer, samma
 * action som `Api\ContainerController::destroy()` anropar, och den enda
 * skillnaden mot `/api` är sessionen: den här kontrollern rensar den aktiva
 * containern när den som raderas är den som ligger i sessionen.
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login av middlewaren och når aldrig de här metoderna.
 */
class ContainerController extends Controller
{
    /**
     * GET /containers — alla containers användaren når, sorterade på namn.
     *
     * Urvalet är `Container::scopeAccessibleBy()` — exakt samma villkor som
     * App\Http\Controllers\Api\ContainerController::index() ställer och som
     * App\Support\Frontend\ActiveContainer::forUser() prövar. Formulera det
     * aldrig en andra gång här.
     *
     * **`can.update` räknas med en policyfråga per rad** (Beslut 9).
     * [[ADR-0021 Frontendteknik]] tillåter uttryckligen ett sådant tillägg i
     * kontrollern — det läggs BREDVID `ContainerResource`, aldrig inuti den,
     * för ett fält som bara webben behöver hör inte i `/api`. Ingen egen
     * samlingsfråga som härmar policyn: två formuleringar av samma villkor
     * glider isär, och det är precis vad App\Policies\ContainerPolicy
     * docblock varnar för.
     *
     * Kostnaden är en fråga per container, och det är accepterat: listan är inte
     * paginerad och antalet containers är taket i kontots plan (`containers`,
     * issue 26a — gratiskontot har en). **Pagineras listan en dag ska det
     * här talet räknas om** — policyfrågan är då inte längre begränsad av
     * plantaket.
     *
     * Flaggan är presentation; grinden är policyn. `edit` och `update`
     * auktoriserar med `Gate::authorize()` oavsett vad listan visade.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            // ContainerResource::toArray() läser $this->account->ulid för
            // varje rad — utan eager loading blir listan N+1, samma
            // resonemang som API-kontrollerns index().
            ->with('account')
            ->orderBy('name')
            ->get();

        return Inertia::render('Containers/Index', [
            'containers' => $containers->map(fn (Container $container): array => [
                ...ContainerResource::make($container)->resolve($request),
                'can' => [
                    'update' => Gate::forUser($user)->allows('update', $container),
                ],
            ])->all(),
        ]);
    }

    /**
     * GET /containers/create — formuläret.
     *
     * `kinds` skickas som PROP, samma teknik som 53c:s tidszonslista och av
     * samma skäl (Beslut 8): listan finns i `Container::KINDS` och ska inte
     * skrivas av i JavaScript. Två listor blir två sanningar.
     *
     * Kontolistan skickas INTE härifrån — den finns redan i den delade propen
     * `auth.accounts`, och en egen fråga för samma lista är en fråga för
     * mycket (Beslut 5).
     */
    public function create(): Response
    {
        return Inertia::render('Containers/Create', [
            'kinds' => Container::KINDS,
        ]);
    }

    /**
     * POST /containers — skapar containern och gör den aktiv, 302 till
     * kategoriytan för den nya containern (issue 56b § Beslut 5).
     *
     * Ägarkontot kommer ur kroppen (`account`, ett konto-ULID), för servern
     * har inget begrepp "aktivt konto" (issue 8 § Beslut 8). Kontot måste
     * finnas — annars är det ett valideringsfel som `StoreContainerRequest`
     * redan fångat — men att användaren inte är MEDLEM i det är ett
     * behörighetsfel (403) som avgörs här av policyn.
     *
     * **Behörighet först, kvot sedan** (issue 27 § Beslut 3): en användare
     * som inte får skapa åt kontot ska få 403, inte veta hur många containers
     * kontot har. Ordningen ligger kvar hos anroparen även sedan skrivningen
     * flyttat in i App\Actions\Container\CreateContainer.
     *
     * **`ApiException` får aldrig nå webbläsaren som JSON** (Beslut 4).
     * Containertaket kastar `quota.containers_exceeded`, och
     * App\Support\Frontend\ApiErrorTranslator gör koden till en mening på
     * användarens språk. Nyckeln är `quota` och inte ett fältnamn: felet
     * handlar inte om vad användaren skrev, och vyn renderar `errors.quota`
     * som en ruta ovanför formuläret.
     *
     * Den nya containern blir aktiv — den som just skapat en container vill arbeta i
     * den (Beslut 6). Det är en av de tre platser som sätter den aktiva
     * containern; `ActiveContainer` äger sessionen.
     */
    public function store(
        StoreContainerRequest $request,
        CreateContainer $createContainer,
        ApiErrorTranslator $translator,
        ActiveContainer $activeContainer,
    ): RedirectResponse {
        $user = $request->user();
        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        Gate::authorize('create', [Container::class, $account]);

        try {
            $container = $createContainer->handle(
                $user,
                $account,
                $request->validated('name'),
                $request->validated('kind'),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['quota' => $translator->message($e)]);
        }

        $activeContainer->set($user, $container);

        // Issue 56b § Beslut 5: den som just skapat en container möts av
        // kategoriytan — och där, på en tom container, av den färdiga
        // uppsättningen. Att landa i listan hon nyss stod i är att be henne
        // leta upp containern igen.
        return redirect()
            ->route('containers.categories', $container)
            ->with('status', 'container-created');
    }

    /**
     * GET /containers/{container}/edit — containerns inställningar.
     *
     * `{container}` binds på ULID via `#[RouteKey('ulid')]` på
     * App\Models\Container, som överallt annars. En mjukraderad container löser
     * aldrig upp (SoftDeletes' globala scope) och en okänd ULID blir
     * felsidan för 404.
     *
     * Auktoriseringen är första raden, före varje läsning av raden som
     * betyder något: en `read`-innehavare kommer hit och får 403.
     * App\Policies\ContainerPolicy::update() kräver en CONTAINER-BRED grant
     * på minst `write` — en itemåtkomst räcker alltså inte, se issue 70.
     *
     * Sidpropen `container` ur `ContainerResource` är kontraktet varje sida
     * under ContainerLayout uppfyller, se resources/js/layouts/ContainerLayout.vue.
     *
     * **`can.delete` räknas med en policyfråga** (62b § Beslut 4), samma
     * mönster som itemets tre flaggor (57a § Beslut 6): raderingsknappen ritas
     * bara för den som får radera, och flaggan läggs BREDVID
     * `ContainerResource` — den är presentation, och rutten auktoriserar ändå
     * med `Gate::authorize()`. En `read`- eller `write`-deltagare kommer inte
     * ens hit (grinden ovan är `update()`), men en `write`-deltagare som
     * postar förbi vyn får 403 på `containers.destroy` — flaggan är ingen
     * grind.
     */
    public function edit(Request $request, Container $container): Response
    {
        Gate::authorize('update', $container);

        // En enda rad, men ladda ägarkontot uttryckligen ändå så resursen
        // aldrig kör en oplanerad lazy-load — samma resonemang som
        // API-kontrollern. Policyn läser samma relation.
        $container->loadMissing('account');

        return Inertia::render('Containers/Edit', [
            'container' => ContainerResource::make($container)->resolve($request),
            'kinds' => Container::KINDS,
            'can' => [
                'delete' => Gate::forUser($request->user())->allows('delete', $container),
            ],
        ]);
    }

    /**
     * PATCH /containers/{container} — skriver `name` och `kind`, 302 till
     * redigeringssidan.
     *
     * `UpdateContainerRequest` delas med `/api` och tar bara emot `name` och
     * `kind`, båda valfria. `account`/`account_id` finns inte i dess regler
     * och är därför aldrig med i `validated()` — ett klientskickat sådant
     * fält ändrar aldrig ägaren (ägarbyte är issue 39).
     *
     * `Gate::authorize()` FRÅGAS oavsett vad listan visade: flaggan i listan
     * är presentation, grinden är policyn (Beslut 9).
     */
    public function update(UpdateContainerRequest $request, Container $container): RedirectResponse
    {
        Gate::authorize('update', $container);

        $container->fill($request->validated());
        $container->save();

        return redirect()
            ->route('containers.edit', $container)
            ->with('status', 'container-updated');
    }

    /**
     * DELETE /containers/{container} — raderar containern, 302 till containerlistan
     * med flashkoden `container-trashed` (62b § Beslut 4, 5 och 6).
     *
     * Kroppen kommer från `Edit.vue`s bekräftade knapp — ingen egen
     * bekräftelseruta på servern, och ingen POST-vägran: `window.confirm` är
     * klientens svar på "är du säker", och en klient som hoppar över den
     * raderar sin egen container. Det är en destruktiv handling för användaren, men
     * den går att ångra i papperskorgen i 30 dagar — det är därför knappen
     * får finnas nu och inte i issue 54.
     *
     * Grinden är `delete()`, samma metod som `Api\ContainerController::
     * destroy()` prövar: bara ägarkontots egna medlemmar, och aldrig ett fryst
     * konto (regel 1 + regel 4). En delegerad `container_access` — även på
     * `delete`-nivå — får 403 här.
     *
     * **Sessionen rensas när den raderade containern var den aktiva** (Beslut 6).
     * Containern löses inte längre upp av `Container::scopeAccessibleBy()`, så
     * varje efterföljande sida hade annars visat en aktiv container som inte finns.
     * `App\Support\Frontend\ActiveContainer` är den enda som rör nyckeln, och
     * den anropas HÄR och inte i actionen: en `/api`-radering har ingen
     * session att röra. Jämförelsen görs FÖRE raderingen — `forUser()` svarar
     * null för en redan raderad container, och efteråt hade svaret alltid varit
     * falskt.
     *
     * Återställningen sätter INTE tillbaka containern som aktiv: att välja container är
     * användarens handling (Beslut 6).
     */
    public function destroy(
        Request $request,
        Container $container,
        TrashContainer $trashContainer,
        ActiveContainer $activeContainer,
    ): RedirectResponse {
        Gate::authorize('delete', $container);

        $varAktiv = $activeContainer->forUser($request->user()) === $container->ulid;

        $trashContainer->handle($container);

        if ($varAktiv) {
            $activeContainer->forget();
        }

        return redirect()
            ->route('containers.index')
            ->with('status', 'container-trashed');
    }
}
