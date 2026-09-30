<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Actions\Container\CreateContainer;
use App\Actions\Container\TrashContainer;
use App\Actions\Container\UpdateContainer;
use App\Actions\Item\ListItems;
use App\Actions\Schedule\ListTodo;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Container\StoreContainerRequest;
use App\Http\Requests\Container\UpdateContainerRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ItemResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use App\Support\Cost\CostReport;
use App\Support\Frontend\ActiveContainer;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Frontend\CreateTarget;
use App\Support\Tips;
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
 * **`show()` kom med issue 89** · [[ADR-0039 Containerns översikt]]. Issue 54
 * § Beslut 2 lämnade med flit URL:en öppen — en tom detaljvy då hade blivit en
 * sida 57 skrev om ändå — och itemlistan flyttade in. Sedan dess har
 * containern fått kostnader, bilagor, scheman, delning, export och en
 * historik, och ingenting av det syntes på förstasidan. Översikten tar
 * containerns egen URL, itemlistan flyttar till `…/items`, och ruttnamnet
 * `containers.show` följer med översikten: det är containerns sida, och det
 * var det hela tiden.
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
     * Antalet uppgiftsrader översiktens panel visar (issue 172).
     *
     * Fem, samma tak som dashboardens uppgiftspanel och av samma skäl:
     * panelen är en glimt av listan och inte listan själv — den finns på
     * `/tasks` och, från issue 174, i containerns egen uppgiftsflik. Gränsen
     * går in i anropet till App\Actions\Schedule\ListTodo::forContainer();
     * urvalet har inget eget femtal, och en yta som vill se fler säger det i
     * sin egen ände.
     */
    public const TASK_LIMIT = 5;

    /**
     * Antalet rader aktivitetspanelen visar (issue 172).
     *
     * Fem, samma tak och samma skäl som ovan: panelen är en glimt av
     * händelseloggen. Gränsen går in i anropet till
     * App\Actions\Audit\ListAuditEvents::forContainer(), vars läsregel inte
     * har något eget femtal (issue 172 § Beslut 2).
     */
    public const ACTIVITY_LIMIT = 5;

    /**
     * Antalet itemrader översiktens panel visar (issue 172).
     *
     * Sex enligt `docs/Design/container.jpeg`, som ritar panelen i två
     * kolumner om tre. Listan själv är opaginerad och bor på
     * `/containers/{ulid}/items`; panelen klipper i sin egen ände av samma
     * App\Actions\Item\ListItems-svar som itembrickan räknar — en egen fråga
     * hade varit en andra sanning om vilka items användaren når.
     */
    public const ITEM_LIMIT = 6;

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
    public function index(Request $request, CreateTarget $createTarget): Response
    {
        $user = $request->user();
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            // ContainerResource::toArray() läser $this->account->ulid och
            // containerns bild för varje rad — utan eager loading blir listan
            // N+1, samma resonemang som API-kontrollerns index(). Bilden
            // kostar två led: bilagan och dess derivat (issue 159).
            ->with(['account', 'coverAttachment.storedFile.derivatives'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Containers/Index', [
            'containers' => $containers->map(fn (Container $container): array => [
                ...ContainerResource::make($container)->resolve($request),
                'can' => [
                    'update' => Gate::forUser($user)->allows('update', $container),
                ],
            ])->all(),
            // Plusknappens mål (issue 152 · [[ADR-0048 Mobilen och
            // plusknappen]] § 2): i en containerlista skapar den en container,
            // precis som raden i huvudet redan gör. Formuleringen bor i
            // App\Support\Frontend\CreateTarget — samma anrop som
            // DashboardController::index() gör, så de två ytorna inte kan
            // svara olika på samma fråga.
            'create' => $createTarget->forContainers($user),
        ]);
    }

    /**
     * GET /containers/{container} — containerns översikt, se issue 89 ·
     * [[ADR-0039 Containerns översikt]].
     *
     * **URL:en är containerns egen sida och har varit det hela tiden** — fram
     * till issue 89 svarade itemlistan på den. Översikten bar då huvudet och
     * de två räknande brickorna; panelerna kom med issue 172
     * ([[ADR-0050 Desktopdesignen]] § 7), och *Senaste bilder* väntar på
     * issue 173. Flikraden kom med designsystemet ([[M15 Containerns
     * översikt]]).
     *
     * **Varje tal räknar det användaren SJÄLV når** ([[ADR-0028 Åtkomst på
     * itemnivå]] § Konsekvenser, issue 73 § Beslut 6): ingen totalsumma, ingen
     * *av N*, ingen rad om att något dolts.
     *
     * **Itembrickan är `ListItems`** — samma Action som itemsidan ritar sin
     * lista ur — och är därför lika lång som listan per konstruktion. En egen
     * `count()`-fråga hade varit en andra formulering av samma urval, och två
     * formuleringar av omfånget glider isär; det är precis den drift
     * [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser varnar för. Priset är att
     * raderna hämtas, vilket itemsidan gör ändå — brickan sitter på den sida
     * som ersätter ett besök där.
     *
     * **Uppgiftsbrickan och uppgiftspanelen är samma urval.**
     * `ScheduleOccurrence::scopeTodoFor()` avgränsat till containern och
     * ingenting annat — `schedule` har inget fält som skiljer en uppgift från
     * ett underhåll, och det ska den inte få: skillnaden är domänen
     * ([[ADR-0033 Produktens omfång]]), så mockupens två brickor är en
     * teckning och inte ett krav. Avgränsningen är
     * `schedule.item.container_id` — samma väg till containern som
     * `todoFor()` själv går — och den formuleras sedan issue 172 på ett ställe:
     * App\Actions\Schedule\ListTodo::forContainer() svarar på både talet och
     * raderna, så brickan och panelen inte kan glida isär. Talet är detsamma
     * som förut: `count` räknar utan växeln för framtida uppgifter, precis som
     * dashboardens brickor (issue 134).
     *
     * **Kostnaden räknas inte här.** `costs`-proppen kom med issue 172, men
     * den bär samma App\Support\Cost\CostReport::summary() som
     * `/api/containers/{container}/costs/summary` svarar med — kontrollern
     * formulerar ingen `SUM` och ingen egen radmängd.
     *
     * **Att öppna containern gör den till sessionens kontext** (issue 83).
     * Anropet ligger efter `Gate::authorize()` och det är bindande: ett nekat
     * anrop får aldrig nå hit, så 403:an lämnar en kontext användaren redan
     * hade orörd. ItemController::index() sätter samma nyckel för den som
     * kommer in via en bokmärkt itemlista — containerns sidor öppnar containern,
     * vilken av dem hon än landar på.
     *
     * Grinden är `view` på CONTAINERN — samma grind som itemlistan ställde när
     * den låg här, så en `read`-mottagare når översikten precis som förut, och
     * en främling får 403.
     *
     * **Informationsytan kom med issue 128 · [[ADR-0039 Containerns
     * översikt]] § Konsekvenser**, som sin egen propp: `tips` bär samma lista
     * som dashboarden bär, ur samma App\Support\Tips — komponenten är den
     * samma (`InfoPanel.vue`), och två sidor som byggde var sin lista hade
     * kunnat visa olika första tips. Den enda skillnaden mellan sidorna är
     * vilken adress krysset postar tillbaka till, och det äger `back()`.
     *
     * **Panelerna kom med issue 172 · [[ADR-0050 Desktopdesignen]] § 7**, som
     * fem egna proppar — en per panel, samma form som M19 gav dashboarden
     * (issue 122) och av samma skäl: en ny panel krockar om en rad här och en
     * rad i vyn, inte om varandras innehåll.
     *
     * - `tasks` är containerns todo-urval, högst `TASK_LIMIT` rader, ur
     *   App\Actions\Schedule\ListTodo::forContainer() — samma urval, samma
     *   ordning och samma rad som `/tasks`, avgränsat till containern.
     * - `costs` är den fasta summeringen och nedbrytningen per item, ur
     *   App\Support\Cost\CostReport::summary(). Ingen plangrind: en fast
     *   summering är fri ([[ADR-0038 Gränsen för Pro i kostnaderna]]).
     * - `items` är samma App\Actions\Item\ListItems-svar som `counts.items`
     *   räknar, klippt till `ITEM_LIMIT` rader. Listan hämtas EN gång och
     *   svarar på båda frågorna.
     * - `events` är containerns händelselogg genom läsregeln, högst
     *   `ACTIVITY_LIMIT` rader ([[ADR-0043 Tre loggar]]).
     * - `details` är art, valuta, ägarkontots namn och skapandedatum.
     *
     * **Kontrollern räknar fortfarande ingenting själv.** Uppgiftstalet kommer
     * ur samma anrop som raderna (`count`), itemtalet ur samma lista som
     * panelen — och den gamla egenformulerade `todoFor()`-räkningen är borta,
     * eftersom `forContainer()` svarar på samma fråga med samma villkor
     * (issue 172 § Beslut 1). Talen är oförändrade: `count` räknar samma mängd
     * som förut, oavsett växeln för framtida uppgifter.
     *
     * **Kostnadsproppen är ingen ny väg till ett tal.** Den går genom exakt
     * den Action som `/api/containers/{container}/costs/summary` använder
     * (App\Http\Controllers\Api\CostSummaryController) och med samma
     * omfångsupplösning — en färsk `ResolveItemScope` per request, så
     * frågekostnaden inte beror på vad processen råkade lösa upp tidigare.
     * Panelen ritas bara när containern har minst en kostnadsrad, och det
     * avgör vyn ur `costs.totals`.
     */
    public function show(
        Request $request,
        Container $container,
        ListItems $listItems,
        ListTodo $listTodo,
        ListAuditEvents $listAuditEvents,
        PresentAuditEvents $presentAuditEvents,
        CostReport $report,
        ActiveContainer $activeContainer,
        CreateTarget $createTarget,
    ): Response {
        Gate::authorize('view', $container);

        // Ägarkontot OCH bilden: skalets topprad ritar miniatyren ur `cover`,
        // som resursen läser genom `coverAttachment` (issue 159), och
        // `details.account` läser ägarkontots namn. Utan den här raden hade
        // båda blivit oplanerade lazy-loads per sidladdning.
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();

        $activeContainer->set($user, $container);

        // EN hämtning, två svar: itembrickan räknar listan och panelen visar
        // de sex första av samma lista. Att fråga två gånger hade varit två
        // sanningar om vilka items användaren når — samma skäl som gjorde
        // brickan till en `ListItems`-räkning från början (issue 89).
        $items = $listItems->handle($user, $container);

        $todo = $listTodo->forContainer($user, $request, $container, self::TASK_LIMIT);

        // Omfånget löses upp på en FÄRSK instans, i ETT anrop — samma grepp
        // och samma skäl som CostSummaryController och monthCosts() i
        // DashboardController: memon på den `scoped`-bundna instansen finns
        // för ItemPolicy, och för en summering skulle den bara göra
        // frågekostnaden beroende av vad processen löst upp tidigare.
        $scope = app()->build(ResolveItemScope::class)->handle($user, $container);

        return Inertia::render('Containers/Overview', [
            'container' => ContainerResource::make($container)->resolve($request),
            // `can.update` räknas med en policyfråga, samma mönster som
            // index() och edit() (issue 54 § Beslut 9): flaggan ritar pennan
            // på containerns bild (issue 159 · [[ADR-0047 Containerns bild]]
            // § Beslut, "Vem som får göra vad") och läggs BREDVID resursen.
            // Den är presentation — ruttens `update` prövas ändå.
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $container),
            ],
            'counts' => [
                'items' => $items->count(),
                'todos' => $todo['count'],
            ],
            // De fem panelernas innehåll (issue 172), i issuens ordning: en
            // propp per panel, och vyn monterar dem — samma form som M19 gav
            // dashboarden (issue 122), så en sjätte panel krockar om en rad
            // här och en rad i vyn och inte om varandras innehåll.
            'tasks' => $todo['rows'],
            'costs' => $report->summary($container, $scope),
            'items' => ItemResource::collection($items->take(self::ITEM_LIMIT))->resolve($request),
            'events' => $presentAuditEvents->handle(
                $listAuditEvents->forContainer($user, $container, self::ACTIVITY_LIMIT),
            ),
            // Containerns egna fakta. `kind` skrivs ut ordagrant av vyn —
            // fältet är fritt och har ingen översättningsnyckel
            // ([[ADR-0036 Containerns art]]).
            'details' => [
                'kind' => $container->kind,
                'currency' => $container->effectiveCurrency(),
                'account' => $container->account->name,
                'created_at' => $container->created_at->toIso8601String(),
            ],
            // Tipsen användaren inte dolt, i Tips ordning — samma propp och
            // samma lista som dashboarden bär (issue 128).
            'tips' => app(Tips::class)->visibleFor($user),
            // Plusknappens mål (issue 152): på översikten skapar den ett item
            // i containern, med samma grind som itemlistans skapaknapp.
            // `$parent` är null — ingen sida står på en nod ännu; trädet och
            // kartan som gör det är 154, 156 och 157.
            'create' => $createTarget->forContainer($user, $container),
        ]);
    }

    /**
     * GET /containers/create — formuläret.
     *
     * `kinds` skickas som PROP, samma teknik som 53c:s tidszonslista och av
     * samma skäl (Beslut 8): listan ska inte skrivas av i JavaScript. Två
     * listor blir två sanningar.
     *
     * Sedan issue 84 · [[ADR-0036 Containerns art]] bär propen **de arter
     * användaren redan använt** och inte en fast mängd — fältet är fritt, och
     * listan är autocomplete, samma mönster och samma motivering som
     * leverantörsfältet i [[ADR-0016 Kostnadsregistrering]]: det som går
     * sönder är stavningsvarianter, och de löses vid inmatningen.
     *
     * Vilka containers listan spänner över står i `kindsUsedBy()` — och det är
     * användarens, inte kontots, av skälet som står där.
     *
     * Kontolistan skickas INTE härifrån — den finns redan i den delade propen
     * `auth.accounts`, och en egen fråga för samma lista är en fråga för
     * mycket (Beslut 5).
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Containers/Create', [
            'kinds' => $this->kindsUsedBy($request->user()),
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
     * `kind` är frivilligt (issue 84): `validated('kind')` är `null` när
     * fältet utelämnats eller tömts, och `null` är vad containern sparas med —
     * kolumnen är nullbar och [[ADR-0004 Fria taggar och kategorier]] vill
     * ingen tom sträng som sentinel.
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
     * den (Beslut 6). Mekanismen har fyra anropare sedan issue 83: skapandet
     * här, en antagen inbjudan, ett mottaget ägarbyte — och att ÖPPNA en
     * container (App\Http\Controllers\ItemController::index()), som är den väg
     * kontexten sätts på i vardagen. `ActiveContainer` äger sessionen.
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
                $request->validated('description'),
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
     * **Grinden är `view` sedan issue 101, och det är med flit en annan grind
     * än skrivningens.** Sidan bär två slags innehåll: sektionslistan är
     * `view`-innehåll — varje länk i den är `view`-grindad — och formuläret är
     * `update`-innehåll. Fram till issue 101 låg sektionsmenyn i layouten och
     * syntes för var och en som nådde containern; nu är den här sidan enda
     * vägen till de sju sektionerna, och låg hubben kvar bakom `update` hade en
     * delegerad `read`-mottagare tappat sex ytor hon når i dag (kategorier,
     * taggar, delning, kalendern, exporten och papperskorgen är alla
     * `view`-grindade, och DelningsvyTest slår fast att mottagaren finns).
     * [[ADR-0039 Containerns översikt]] § Beslut lägger exporten "under
     * containerns inställningar", och då är det grinden som ger vika — inte
     * placeringen, och inte App\Policies\ContainerPolicy::update().
     *
     * Formuläret ritas ur `can.update` nedan, och PATCH prövar `update()` som
     * förut: en CONTAINER-BRED grant på minst `write` — en itemåtkomst räcker
     * alltså inte, se issue 70. Grinden här auktoriserar alltså LÄSNINGEN av
     * sidan; skrivningen auktoriseras av `update()` på `containers.update`.
     *
     * Sidpropen `container` ur `ContainerResource` är kontraktet varje sida
     * under ContainerLayout uppfyller, se resources/js/layouts/ContainerLayout.vue.
     *
     * **`can.delete` och `can.update` räknas med en policyfråga** (62b
     * § Beslut 4, issue 101), samma mönster som itemets tre flaggor (57a
     * § Beslut 6): formuläret och raderingsknappen ritas bara för den som får
     * skriva respektive radera, och båda flaggorna läggs BREDVID
     * `ContainerResource` — de är presentation, och rutterna auktoriserar ändå
     * med `Gate::authorize()`. Att en `read`-mottagare — eller en mottagare
     * med åtkomst bara på ett enskilt item — når hit är hela poängen med
     * `view`-grinden ovan: hon ser de sju sektionerna och inget formulär, och
     * en `write`-deltagare som postar förbi vyn får 403 på `containers.destroy`
     * — flaggan är ingen grind.
     *
     * **Valutan kommer som TVÅ propar bredvid resursen** (issue 85 ·
     * [[ADR-0037 Valutans arv]]): `currency` är containerns EGEN, och `null`
     * betyder att den ärver, medan `accountCurrency` är ägarkontots värde och
     * det vyn visar som "ärver kontots valuta (SEK)". Proparna läggs bredvid
     * `ContainerResource` och inte inuti den, av samma skäl som `can.delete`
     * ovan: `ContainerResource` är `/api`:ets format, och de här två värdena
     * är en upplysning bara den här vyn behöver. Arvsregeln skrivs INTE av
     * här; vyn får båda värdena och frågar
     * `App\Models\Container::effectiveCurrency()` om den behöver svaret.
     *
     * **`/api` kan i dag SKRIVA valutan men inte LÄSA den.** Den här rutten
     * och `PATCH /api/containers/{container}` delar `UpdateContainerRequest`,
     * så en API-klient kan sätta containerns `currency` — men varken
     * `ContainerResource` eller `AccountResource` exponerar någon valuta, så
     * svaret går inte att läsa tillbaka. Skriv- och läspariteten hör till en
     * egen issue: båda resurserna ligger utanför issue 85:s ruta, och att
     * bredda `/api`:ets format är ett beslut om kontraktet, inte om arvet.
     */
    public function edit(Request $request, Container $container): Response
    {
        Gate::authorize('view', $container);

        // En enda rad, men ladda ägarkontot och bilden uttryckligen ändå så
        // resursen aldrig kör en oplanerad lazy-load — samma resonemang som
        // API-kontrollern. Policyn läser samma relation, och skalets topprad
        // ritar miniatyren ur `cover` (issue 159).
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        return Inertia::render('Containers/Edit', [
            'container' => ContainerResource::make($container)->resolve($request),
            // Samma lista som skapavyn får — en metod, en prop (issue 84).
            'kinds' => $this->kindsUsedBy($request->user()),
            // Containerns egen valuta, och ägarkontots att falla tillbaka på
            // när den är tom (issue 85). `null` är ett giltigt värde för
            // `currency` och betyder "ärver".
            'currency' => $container->currency,
            'accountCurrency' => $container->account->currency,
            'can' => [
                // Formuläret ritar sig ur den här; se docblocken ovan om varför
                // grinden och flaggan inte är samma fråga.
                'update' => Gate::forUser($request->user())->allows('update', $container),
                'delete' => Gate::forUser($request->user())->allows('delete', $container),
            ],
        ]);
    }

    /**
     * PATCH /containers/{container} — skriver `name`, `kind` och `currency`,
     * 302 till redigeringssidan.
     *
     * `UpdateContainerRequest` delas med `/api` och tar bara emot `name`,
     * `kind` och — sedan issue 85 · [[ADR-0037 Valutans arv]] — `currency`,
     * alla valfria. `account`/`account_id` finns inte i dess regler och är
     * därför aldrig med i `validated()` — ett klientskickat sådant fält
     * ändrar aldrig ägaren (ägarbyte är issue 39).
     *
     * **Valutan som skrivs här märker aldrig om en skriven kostnadsrad.**
     * `cost_entry.currency` rörs inte av den här metoden, av requesten eller
     * av någon migration i issue 85: det som står i en rad är vad som
     * betalades ([[ADR-0037 Valutans arv]] § Beslut). En tömd ruta lagrar
     * `null`, och containern ÄRVER då kontots valuta igen
     * (App\Models\Container::effectiveCurrency()) — det nya värdet gäller
     * bara rader som skrivs härefter.
     *
     * `Gate::authorize()` FRÅGAS oavsett vad listan visade: flaggan i listan
     * är presentation, grinden är policyn (Beslut 9).
     */
    public function update(
        UpdateContainerRequest $request,
        Container $container,
        UpdateContainer $updateContainer,
    ): RedirectResponse {
        Gate::authorize('update', $container);

        $updateContainer->handle($container, $request->user(), $request->validated());

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
     * Återställningen sätter INTE tillbaka containern som aktiv: att öppna en
     * container är användarens handling (Beslut 6, issue 83).
     */
    public function destroy(
        Request $request,
        Container $container,
        TrashContainer $trashContainer,
        ActiveContainer $activeContainer,
    ): RedirectResponse {
        Gate::authorize('delete', $container);

        $varAktiv = $activeContainer->forUser($request->user()) === $container->ulid;

        $trashContainer->handle($container, $request->user());

        if ($varAktiv) {
            $activeContainer->forget();
        }

        return redirect()
            ->route('containers.index')
            ->with('status', 'container-trashed');
    }

    /**
     * De arter $user redan använt, utan dubbletter och sorterade — underlaget
     * för autocomplete i skapa- och redigeringsvyn (issue 84 · [[ADR-0036
     * Containerns art]]).
     *
     * **Mängden är användarens containers, inte hennes konton.** Urvalet är
     * `Container::scopeAccessibleBy()` — samma villkor som listan, sökningen
     * och den aktiva containern ställer — och det av ett skäl som är
     * sakligt och inte symmetriskt: `container_access` ger åtkomst per
     * container ([[Konton och åtkomst]] § container_access), så en container
     * som delats direkt med henne ligger utanför "konton hon är med i" men
     * innanför hennes containerlista. Föreslog vi ur en snävare mängd än den
     * navigeringen grupperar över ([[ADR-0036 Containerns art]]) skulle
     * autocomplete själv producera de stavningsvarianter den finns för att
     * förhindra. Skapavyn har inget aktivt konto att gå på — den behöver
     * heller inget; scopet är användaren.
     *
     * Det här är den ENDA formuleringen av frågan, och BÅDA vyerna får sin
     * lista härifrån. Skriv den inte en andra gång i en action eller i en
     * scopes-metod: två listor blir två sanningar, samma skäl som
     * `Container::scopeAccessibleBy()` bär.
     *
     * Containrar utan art (fältet är frivilligt) hoppas över — `null` är
     * inget förslag, och en tom sträng från äldre data är det inte heller —
     * och mjukraderade likaså: listan beskriver vad användaren HAR, inte vad
     * hon har haft. Sorteringen är på värdet, för den som skriver i fältet
     * möter en bokstavsordning och inte en tidslinje.
     *
     * @return list<string>
     */
    private function kindsUsedBy(User $user): array
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        return Container::query()
            ->accessibleBy($user, $accountIds)
            ->whereNotNull('kind')
            ->where('kind', '!=', '')
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind')
            ->all();
    }
}
