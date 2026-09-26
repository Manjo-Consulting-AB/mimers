<?php

namespace App\Http\Controllers;

use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Actions\Category\ListCategories;
use App\Actions\Item\CreateItem;
use App\Actions\Item\DeleteItem;
use App\Actions\Item\ListItemLinks;
use App\Actions\Item\ListItems;
use App\Actions\Item\ResolveItemCover;
use App\Actions\Item\ResolveItemPaths;
use App\Actions\Item\ResolveItemTree;
use App\Actions\Item\UpdateItem;
use App\Actions\Tag\ListTags;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ItemLinkResource;
use App\Http\Resources\ItemResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\ScheduleOccurrenceResource;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\TagResource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\Tag;
use App\Models\User;
use App\Support\Files\FileOrigin;
use App\Support\Frontend\ActiveContainer;
use App\Support\Item\ItemStatus;
use App\Support\Item\ItemTree;
use App\Support\Item\ItemTreeNode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens itemytor — listan och detaljvyn (issue 57a § Beslut 1, 2, 4, 5 och
 * 6), skapandet, redigeringen och raderingen (issue 57b § Beslut 1–9).
 *
 * **Containerns förstasida var itemlistan, till och med issue 88.** Sedan
 * issue 89 · [[ADR-0039 Containerns översikt]] ligger listan på
 * `GET /containers/{container}/items` och containerns egen URL svarar med
 * översikten, som bärs av App\Http\Controllers\ContainerController::show().
 * Flytten är hela ärendet för den issuen: containern hade fått kostnader,
 * bilagor, scheman, delning, export och en historik, och ingenting av det
 * syntes på den sida användaren mötte först.
 *
 * **Ingenting av `/api` görs om.** `ItemResource`, `ContainerResource`,
 * `StoreItemRequest` och `UpdateItemRequest` delas rakt av, och läsningen går
 * genom App\Actions\Item\ListItems — samma Action som
 * App\Http\Controllers\Api\ItemController::index() anropar (57a § Beslut 3).
 *
 * **Skrivningarna är grindar plus några rader** (57b). Skapandet och
 * ändringen ligger här och inte i en Action: den utbrytningen gjordes där den
 * behövdes (57a § Beslut 3), och en ny Action för webbens räkning hade varit
 * en andra väg till samma skrivning. `replaceTags()` är därför en medveten
 * andra kopia av `Api\ItemController`s — se PR:ens Frågor och antaganden.
 *
 * **Tre grindar, en per metod** (57b § Beslut 2). `store()` frågar
 * `ContainerPolicy::createItem()` på CONTAINERN — ett toppnivå-item har ingen
 * förälder att auktorisera mot. `update()` och `destroy()` frågar ITEMETS
 * `update` respektive `delete`, två skilda pinnar på laddern
 * ([[ADR-0028 Åtkomst på itemnivå]] § Beslut): en `write`-mottagare ändrar
 * itemet men tar inte bort det.
 *
 * **Kategorinamnet läggs BREDVID resursen** (57a Beslut 1 och 6, samma linje
 * som issue 54 § Beslut 9 och 56a § Beslut 6). `ItemResource` bär kategorins
 * ULID och ingenting mer, och `/api` har inte bett om namnet — så uppslaget
 * skickas som en egen prop, byggd ur de redan eager-laddade
 * `category`-relationerna. Noll extra frågor, och inget nytt fält inuti
 * resursen.
 *
 * **`can` är presentation.** Flaggorna räknas med `Gate::forUser()->allows()`
 * och styr om ytorna ritas; varje skrivande rutt auktoriserar ändå med
 * `Gate::authorize()` oavsett vad sidan visade. Detaljvyns tre flaggor ställs
 * mot ITEMET (Beslut 6), listans mot containern. Kostnaden är noll extra frågor
 * per rad — App\Actions\Access\ResolveItemScope är registrerad `scoped` och
 * memoiserar per `{user}:{container}`, se dess docblock.
 *
 * **Radens status är härledd och ligger BREDVID resursen** (issue 92 ·
 * [[ADR-0040 Underträdets summor]]), samma linje som kategorinamnet. `OK`
 * betyder noll förfallna förekomster i itemets underträd, och uppslaget
 * byggs av App\Support\Item\ItemStatus på ett konstant antal frågor — se
 * `statuses` i index().
 *
 * **Omslagsbilden ligger BREDVID resursen** (issue 93 · [[ADR-0041 Itemets
 * vy]] § Beslut), samma linje igen: `ItemResource` är `/api`:s format och har
 * inte bett om fältet — varken pekaren eller den upplösta bilden. Redigeringsvyn
 * får i stället `images`, itemets bilder i upplösningens egen ordning, och
 * `cover`, den VALDA bildens ULID uppslagen ur just den listan så att en pekare
 * på en mjukraderad bilaga blir null. Urvalet av vad som är en bild och i vilken
 * ordning de kommer bor i App\Actions\Item\ResolveItemCover och skrivs inte
 * om här; vyn varken väljer bland bilagorna eller sorterar dem.
 *
 * **Förekomsterna ligger BREDVID resursen** (issue 95 · [[ADR-0041 Itemets
 * vy]] § Beslut), samma linje en gång till: `paths` är alla vägar från en rot
 * ned till itemet, med den aktuella utmärkt, och `ItemResource` har fortfarande
 * inte bett om fältet. Ingen kolumn pekar ut en huvudplats — vägen står i
 * querysträngen (`?path=…`), som filtret i issue 59a § Beslut 1 och av samma
 * skäl: det gör vyn till en delbar länk. Upplösningen bor i
 * App\Actions\Item\ResolveItemPaths, och vyn varken vandrar eller sorterar.
 *
 * **Ingen behörighetslogik bor här.** Ett nekat svar kastar
 * `AuthorizationException`, som bootstrap/app.php renderar som felsidan för
 * 403 på webben. Det enda undantaget är medlemsprövningen i store(), se den
 * metodens docblock.
 *
 * **Relationerna är en sektion till på detaljvyn** (issue 58 § Beslut 2, 3,
 * 5 och 7). Listan kommer ur App\Actions\Item\ListItemLinks — samma Action
 * som `Api\ItemLinkController::index()` anropar — och motpartsväljaren ur
 * `ListItems` plus en `update`-grind per kandidat. Varken listan eller
 * väljaren formulerar ett eget omfångsfilter: den som ligger utanför
 * mottagarens omfång finns inte i någon av dem, och vyn lägger ingenting
 * ovanpå (issue 73 § Beslut 7). Skrivningarna bor i
 * App\Http\Controllers\ItemLinkController; barn-itemet är den här
 * kontrollerns `parent`-gren.
 */
class ItemController extends Controller
{
    /**
     * GET /containers/{container}/items — containerns itemlista, sorterad på
     * namn, med filtren ur querysträngen (issue 59a § Beslut 1–7).
     *
     * **Rutten flyttade hit i issue 89** · [[ADR-0039 Containerns översikt]].
     * Ingenting i metoden ändrades av flytten: urvalet, filtren och propformerna
     * är 59a:s och 57a:s, och den enda skillnaden är att `{container}` numera
     * följs av `/items`. Querysträngen följer med oförändrad, precis som
     * [[ADR-0039 Containerns översikt]] § Beslut kräver — `q`, `tags[]` och
     * `category` betyder samma sak på den nya URL:en, och en filtrerad länk från
     * före flytten pekar därför fortfarande på rätt sida så snart sökvägen är
     * rättad.
     *
     * **Filtret är querysträng på den här sidan** (Beslut 1). Samma rutt,
     * samma sida, samma ruttnamn i `containers.items`-familjen; ett filtrerat
     * läge är en LÄNK som går att spara, dela och backa ur. Ingen egen söksida
     * och ingen ny rutt.
     *
     * **Servern gör hela jobbet** (Beslut 2). `tags[]` kräver VARJE angiven
     * tagg, `category` betyder kategorin och hela dess underträd, `q` är
     * fritext — och allt tre kombineras med OCH ovanpå omfånget. Den här
     * metoden räknar inte en rad själv: urvalet är `ListItems`, och vyn
     * filtrerar ingenting.
     *
     * **Webben är inte en valideringssida** (Beslut 3). I `/api` är ett okänt
     * filtervärde 422 (issue 15a § Beslut 7), och det kontraktet står orört —
     * `Api\ItemController::index()` anropar `IndexItemRequest` som förut. Här
     * är samma värde i stället en GAMMAL LÄNK: taggen är raderad sedan
     * bokmärket sparades, eller kategorin flyttad till en annan container. En
     * 422-sida hade varit fel svar på ett bokmärke, och en redirect tillbaka
     * till samma querysträng en oändlig sådan. `filter()` nedan löser därför
     * upp ULID:na mot de listor sidan ÄNDÅ hämtar — `ListTags` och
     * `ListCategories`, båda omfångsfiltrerade — och skickar bara det som
     * fanns kvar vidare. Det är en presentationsregel om en gammal URL och
     * ingen andra filtreringsregel: vilka RADER som får synas avgör
     * fortfarande bara `ListItems` (Beslut 2 och 3).
     *
     * **Omfånget filtrerar raderna, precis som i `/api`** (Beslut 5, issue 73
     * § Beslut 2). Den som når containern når inte nödvändigtvis allt i den: en
     * mottagare med en grant på motorn ser motorn och dess ättlingar, ingenting
     * annat — och filterraden listar bara de taggar och kategorier hon når,
     * eftersom de kommer ur samma två omfångsfiltrerade Actions. Att filtrera
     * på en tagg hon inte ser är inte ett fel hon kan göra.
     *
     * **Sidan får visa antalet rader den ritar** och ingenting mer (Beslut 4).
     * Ingen totalsumma, ingen "av N", ingen rad om att något dolts: ingenting
     * i svaret får bära ett tal som avslöjar hur många rader som filtrerats
     * bort (issue 73 § Beslut 6). Är listan tom UTAN filter säger sidan att
     * containern är tom; är den tom MED filter räknar vyn upp de filter
     * användaren själv satt — och en omfångsbegränsad mottagares tomma
     * träfflista är ordagrant identisk med en ägares, för meningen vet
     * ingenting om omfånget.
     *
     * **Två frågor, oavsett filter** (Beslut 7): `ListTags` och
     * `ListCategories`. `ListItems` är konstant sedan issue 15a § Beslut 9, och
     * inget filtervärde lägger till en fråga — uppslagen sker i minnet mot de
     * redan hämtade listorna.
     *
     * `can.create` är `ContainerPolicy::createItem()` — samma grind som
     * `Api\ItemController::store()` prövar för ett toppnivå-item, och bara en
     * presentationsflagga för 57b:s knapp. En omfångsbegränsad mottagare får
     * `false`: hon skapar barn-items under det hon nått, och den ytan hör till
     * detaljvyn.
     *
     * **Att öppna containern gör den till sessionens kontext** (issue 83).
     * `ActiveContainer::set()` har fem anropare, och den här är en av dem: de
     * tre andra är de tillfällen användaren just FÅTT en container, och den
     * femte är översikten — ContainerController::show(), som svarar på
     * containerns egen URL sedan issue 89. Båda sidorna sätter nyckeln med
     * flit: containerns sidor öppnar containern, vilken av dem hon än landar
     * på, och containerlistans namnlänk går till LISTAN ([[ADR-0039
     * Containerns översikt]] § Konsekvenser) — utan anropet här hade den som
     * öppnar en container ur listan fått en kontext som stod kvar på den
     * förra.
     *
     * Anropet ligger efter `Gate::authorize()` och det är bindande — `set()`
     * glömmer nyckeln när åtkomsten saknas, så ett nekat anrop får aldrig nå
     * hit: 403:an lämnar en kontext användaren redan hade orörd. Det finns
     * ingen rutt och ingen knapp som sätter kontexten för hand;
     * `PUT /containers/{container}/active` togs bort i issue 83.
     */
    public function index(
        Request $request,
        Container $container,
        ListItems $listItems,
        ListCategories $listCategories,
        ListTags $listTags,
        ActiveContainer $activeContainer,
        ItemStatus $itemStatus,
    ): Response {
        Gate::authorize('view', $container);

        $container->loadMissing('account');

        $user = $request->user();

        $activeContainer->set($user, $container);

        // Listorna hämtas FÖRE filtren: de är både filterradens innehåll och
        // det de inskickade ULID:na löses upp mot (Beslut 3 och 5). Två
        // frågor, oavsett hur många filtervärden som skickas.
        $tags = $listTags->handle($user, $container);
        $categories = $listCategories->handle($user, $container);

        [$filter, $dropped] = $this->filter($request, $tags, $categories);

        $items = $listItems->handle($user, $container, [
            'tags' => $filter['tags'],
            'category' => $filter['category'],
            'q' => $filter['q'],
        ]);

        return Inertia::render('Containers/Items/Index', [
            'container' => ContainerResource::make($container)->resolve($request),
            'items' => ItemResource::collection($items)->resolve($request),
            // `categories` är ULID → namn för RADERNA (57a § Beslut 1 och 6) —
            // ett annat uppslag än `categoryTree` nedan, som är filterradens
            // väljare och bär hela trädet.
            'categories' => $this->categoryNames($items),

            // Statusen är HÄRLEDD och ligger BREDVID resursen (issue 92 ·
            // [[ADR-0040 Underträdets summor]]), samma linje som
            // `categoryNames()` ovan: `ItemResource` delas med `/api`, som
            // inte har bett om fältet, och `app/Http/Resources/**` rörs inte.
            // `item` har ingen `status`-kolumn och får ingen — det som går att
            // räkna fram lagras inte.
            //
            // Två frågor per lista, oavsett antal rader: kanterna och
            // förekomsterna hämtas en gång och slutningen sker i minnet, se
            // App\Support\Item\ItemStatus. En vandring per rad vore den N+1
            // hela åtkomstlösningen byggdes för att undvika.
            'statuses' => $itemStatus->forItems($container, $user, $items),
            'tags' => TagResource::collection($tags)->resolve($request),
            'categoryTree' => CategoryResource::collection($categories)->resolve($request),
            'filter' => [
                'q' => $filter['q'],
                'tags' => $filter['tags'],
                'category' => $filter['category'],
                'dropped' => $dropped,
            ],
            'can' => [
                'create' => Gate::forUser($user)->allows('createItem', $container),
            ],
        ]);
    }

    /**
     * GET /containers/{container}/items/{item} — detaljvyn.
     *
     * `{item}` binds på ULID via `#[RouteKey('ulid')]` på App\Models\Item och
     * löses genom `scopeBindings()` mot containerns `items()`-relation — en
     * item-ULID från en annan container blir 404, och en mjukraderad rad löser
     * aldrig upp.
     *
     * **Grinden är ITEMETS `view`, inte containerns** (Beslut 5). Ett item som
     * finns i containern men ligger utanför anroparens omfång ger **403**, inte
     * 404: "känd men utanför omfånget" har en kod över tio kontrollrar
     * (issue 73 § Beslut 3), och webben uppfinner inte en elfte regel.
     *
     * **Itemets egna fält, kategorin, taggarna, relationerna, bilagorna,
     * schemana och utlåningen** (Beslut 4 och 8, issue 58, issue 60
     * § Beslut 2). Bilagorna kommer med props — se `attachments` nedan — och
     * har ingen egen rutt. Schemana gjorde detsamma i issue 63a, se
     * `schedules` och `openOccurrences` nedan, och utlåningen i issue 67a, se
     * `openLoan`, `loanHistory` och `openLoanOverdue`. Kostnaderna 45–47 har
     * fortfarande ingen yta här.
     *
     * `categories` bär kategorins NAMN bredvid resursen — se klassens
     * docblock. Ett item utan kategori får en tom uppslagstabell och vyn
     * utelämnar raden; den hittar aldrig på ett värde (Beslut 8).
     *
     * **Relationssektionen får tre propar** (issue 58 § Beslut 2, 3 och 5).
     * `links` är App\Actions\Item\ListItemLinks svar, grupperat i de tre
     * riktningarna och varje grupp sorterad som Actionen levererar den. `can`
     * bär redan `update`, som ritar formuläret och upp-knytningen, och
     * `create`, som ritar länken till barn-itemet. Att gruppera här och inte i
     * vyn är samma linje som `categoryNames()`: formatering, inte logik, och
     * gruppnycklarna är relationens tre värden.
     *
     * Motpartsväljaren bär bara `{ulid, name}` — samma form som
     * delningssidans omfångsväljare (issue 55b § Beslut 5), och den ritas bara
     * för `can.update`.
     *
     * **Bilagesektionen får en prop och ingen rutt** (issue 60 § Beslut 2).
     * `attachments` är itemets bilagor genom `AttachmentResource`, med samma
     * relationer och samma sortering som `Api\AttachmentController::index()`
     * ger — nyast först — och ett konstant antal frågor oavsett antal rader
     * (Beslut 10). Vyn ritar dem, `can.create` och `can.delete` styr ytorna,
     * och skrivningarna ligger i App\Http\Controllers\AttachmentController.
     *
     * **Förekomsterna i strukturen får en prop och en querysträng** (issue 95
     * · [[ADR-0041 Itemets vy]] § Beslut). `paths` bär alla vägar från en rot
     * ned till itemet — ett item med två föräldrar har två — och exakt en av
     * dem är märkt `current`. Vilken det är avgörs av `?path=`, en punktlista
     * av ULID:ar från roten ned till itemet, och en väg som inte längre finns
     * ignoreras till förmån för den första i ordningen: aldrig 404, aldrig
     * 422, ingen rad om varför (se `itemPaths()`).
     *
     * **ItemResource har inget nytt fält.** Vägarna bär ULID och namn och
     * ligger BREDVID resursen, samma linje som `categories`, `variants` och
     * `statuses`: `/api` har inte bett om dem, och upplösningen sker på
     * servern — en vy som själv vandrade i grafen vore en andra regel som
     * glider ifrån den första (samma resonemang som omslagsbilden i issue 93).
     *
     * **Strukturen får en prop ur samma upplösning** (issue 103 ·
     * [[M17 Designsystemet]] § 103). `structure` är trädet ur
     * App\Actions\Item\ResolveItemTree — containerns items som anroparen når,
     * med sina föräldrakanter och i namnets ordning — och det är vänsterpanelen
     * i trepanelslayouten. Panelen ställer ingen egen fråga: upplösningen är
     * issue 94:s, rotregeln är `paths` egen, och en panel som byggde en andra
     * vore den andra regeln [[ADR-0041 Itemets vy]] byggde en gemensam rot för
     * att slippa. Ingen räknare följer med, och ingen markering av vad som
     * filtrerats bort (issue 73 § Beslut 6).
     *
     * **`max_upload_bytes` är det TEKNISKA taket och en prop** (issue 60b
     * § Beslut 5). Det är samma tal som `StoreAttachmentRequest` prövar med
     * `max:` — vyn avvisar en för stor fil innan bytena lämnar webbläsaren,
     * så en 200 MB-fil inte reser över en mobil uppkoppling för att få 413 i
     * andra änden. Plangränserna (`max_file_bytes`, `storage_bytes`) skickas
     * INTE: de hör till det valda kontot, kontot går att byta i formuläret,
     * och en siffra i vyn hade varit fel så fort väljaren rördes. De prövas
     * där de hör hemma — på servern — och kommer tillbaka som fältfelet på
     * `file`.
     */
    public function show(Request $request, Container $container, Item $item, ListItemLinks $listItemLinks, ListItems $listItems, ResolveItemPaths $resolveItemPaths, ResolveItemTree $resolveItemTree, ListAuditEvents $listAuditEvents, PresentAuditEvents $presentAuditEvents): Response
    {
        Gate::authorize('view', $item);

        $container->loadMissing('account');

        // En enda rad, men ladda relationerna uttryckligen ändå så resursen
        // aldrig kör en oplanerad lazy-load — samma resonemang som
        // Api\ItemController::show().
        $item->loadMissing(['category', 'createdByAccount', 'tags']);

        $user = $request->user();

        $links = $listItemLinks->handle($user, $container, $item);

        // Förekomsterna i strukturen (issue 95 · [[ADR-0041 Itemets vy]]
        // § Beslut): alla vägar från en rot ned till itemet, i serverns
        // ordning. Upplösningen bor i App\Actions\Item\ResolveItemPaths och
        // läses här — vyn vandrar aldrig själv, och den sorterar aldrig om
        // något (issue 57a § Beslut 8). Två frågor, oavsett antalet vägar.
        $paths = $resolveItemPaths->handle($user, $container, $item);

        // Strukturen (issue 103 · [[ADR-0042 Designsystemet]] § Beslut):
        // containerns items som användaren når, med sina föräldrakanter —
        // trädet i trepanelslayoutens vänsterpanel. Upplösningen är issue 94:s
        // och byggs inte på nytt: App\Actions\Item\ResolveItemTree, samma
        // rotregel som `paths` ovan vandrar efter, så panelen och
        // förekomstlistan inte kan säga olika saker om samma graf.
        //
        // Anropet ligger EFTER `paths` med flit: båda löser upp omfånget, och
        // ResolveItemScope är memoiserad per användare och container — den
        // andra frågan är alltså gratis, och trädet kostar sina egna två
        // (itemen, kanterna) oavsett trädets djup och bredd.
        $tree = $resolveItemTree->handle($user, $container);

        // Bilagorna kommer med detaljvyns props och aldrig ur ett eget anrop
        // (issue 60 § Beslut 2). Relationerna och sorteringen är
        // App\Http\Controllers\Api\AttachmentController::index()s egna —
        // nyast först, `created_at` fallande med `id` fallande, så två
        // bilagor uppladdade samma sekund ändå får en stabil ordning.
        // Mjukraderade bilagor kommer aldrig med; SoftDeletes' globala scope
        // sköter det.
        //
        // `storedFile` och `billedAccount` laddas eager eftersom
        // AttachmentResource läser `mime_type`/`byte_size` respektive
        // `billed_account` därifrån: listan kostar ett konstant antal frågor
        // oavsett antalet bilagor, aldrig en fråga per rad (Beslut 10).
        // `storedFile.derivatives` kom med issue 61b av samma skäl: vilka
        // varianter som finns är `variants`-propen nedan, och den får inte
        // kosta en fråga per rad.
        $attachments = $item->attachments()
            ->with(['storedFile.derivatives', 'billedAccount'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        // Schemana kommer med detaljvyns props och aldrig ur ett eget anrop
        // (issue 63a § Beslut 1 och 9). Sorteringen är `title` stigande — den
        // `Api\ScheduleController::index()` använder — och `openOccurrence`
        // laddas eager, så tio scheman kostar samma antal frågor som noll:
        // nästa förfall bor på den öppna förekomsten och aldrig på schemat
        // (issue 21 § Beslut 8). Mjukraderade scheman faller ut genom
        // SoftDeletes' globala scope.
        //
        // Sedan issue 63b bär sektionen hela den öppna förekomsten och inte
        // bara dess datum: avbockningen ska kunna ske från itemet på en
        // knapptryckning, och den behöver förekomstens ULID, `overdue` och
        // `visible_from` — inte bara `due_at`. Se `openOccurrences()`.
        $schedules = $item->schedules()
            ->with('openOccurrence')
            ->orderBy('title')
            ->get();

        // Utlåningen kommer med detaljvyns props och aldrig ur ett eget anrop
        // (issue 67a § Beslut 1). Sorteringen är `Api\LoanController::index()`s
        // egna — `lent_at` fallande med `id` fallande, så det som lånades ut
        // senast står först och två lån med samma datum ändå får en stabil
        // ordning — och listan delas i den ÖPPNA och historiken här, inte i
        // vyn: `returned_at IS NULL` är den öppna (Beslut 2), och vilken rad
        // som är det är en domänfråga.
        //
        // Ett konstant antal frågor oavsett antal lån (Beslut 1):
        // LoanResource läser bara kolumner på raden själv, inga relationer att
        // ladda i förväg. Mjukraderade lån faller ut genom SoftDeletes'
        // globala scope.
        $loans = $item->loans()
            ->orderByDesc('lent_at')
            ->orderByDesc('id')
            ->get();

        $openLoan = $loans->first(fn (Loan $loan): bool => $loan->returned_at === null);
        $history = $loans->reject(fn (Loan $loan): bool => $loan->returned_at === null)->values();

        // Historikfliken (issue 116 · [[ADR-0043 Tre loggar]]
        // § Händelseloggen). Raderna läses genom App\Actions\Audit\
        // ListAuditEvents — samma läsregel som containerns historiksida och
        // `GET /api/containers/{container}/audit-log` (issue 108), så en gäst
        // ser sina egna rader och ägaren allas. Formen kommer ur
        // App\Actions\Audit\PresentAuditEvents: `audit_log` bär identifierare
        // och inte namn, så namnen slås upp när raden läses och en gallrad
        // item- eller användarrad får sin neutrala ersättare i vyn.
        //
        // **Bara när fliken är aktiv.** Raderna kostar en egen fråga mot
        // `audit_log`, och den som öppnar itemet för att se bilagorna ska inte
        // betala för historiken. `?tab=history` är flikens egen adress (issue
        // 100 och 102), och utan den lämnar kontrollern proppen helt: nyckeln
        // finns inte i svaret och ingen fråga ställs.
        //
        // De hundra senaste, och ingen paginering: gränsen står i
        // ListAuditEvents och är API:ets egen (issue 40 § Beslut 8). Behövs mer
        // är det ett eget beslut.
        $auditRows = $request->query('tab') === 'history'
            ? $presentAuditEvents->handle($listAuditEvents->forItem($user, $item))
            : null;

        return Inertia::render('Containers/Items/Show', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => (new ItemResource($item))->resolve($request),
            'categories' => $this->categoryNames([$item]),

            // Förekomsterna (issue 95 · [[ADR-0041 Itemets vy]] § Beslut):
            // varje väg som sina led, och exakt en av dem märkt som den
            // AKTUELLA. Vägen står i querysträngen — hela ledet från roten,
            // som `?path=<båtens ulid>.<motorns ulid>.<impellerns ulid>` — och
            // den ligger BREDVID resursen, inte i den: `ItemResource` är
            // `/api`:s format och har inte bett om fältet, samma linje som
            // `categories` ovan och `variants` i issue 61b.
            'paths' => $this->itemPaths($paths, $request),

            // Strukturen (issue 103): trädet ur samma upplösning som issue 94
            // byggde, som `{ulid, name, children}` — det panelen ritar och
            // navigerar med och ingenting mer. Ingen räknare och ingen
            // markering om att en gren är avklippt: svaret får inte avslöja hur
            // många items som filtrerats bort (issue 73 § Beslut 6), och en
            // omfångsbegränsad mottagares träd ska vara ordagrant det hon hade
            // sett om resten inte fanns.
            //
            // Ligger BREDVID resursen och inte i den, samma linje som `paths`
            // och `categories`: `ItemResource` är `/api`:s format och har inte
            // bett om fältet.
            'structure' => $this->structure($tree),

            'attachments' => AttachmentResource::collection($attachments)->resolve($request),
            'maxUploadBytes' => (int) config('files.max_upload_bytes'),

            // Bilagans ULID → de varianter som faktiskt finns (issue 61b
            // § Beslut 1). Ligger BREDVID resursen och inte i den: vilka
            // varianter som finns är vyens fråga, och AttachmentResource är
            // `/api`:s format som den här issuen inte rör.
            'variants' => $this->variants($attachments),

            // Schemana ur App\Http\Resources\ScheduleResource — samma format
            // och samma sortering som `Api\ScheduleController::index()` — och
            // den öppna förekomsten BREDVID resursen (`openOccurrences`
            // nedan), samma linje som `categoryNames()` och `variants()`:
            // resursen är `/api`:s format, och förekomsten är vyens fråga
            // (issue 63a § Beslut 1).
            //
            // 63a skickade bara den öppna förekomstens DATUM här. 63b ersätter
            // den med förekomsten själv: avbockningen från itemet behöver
            // ULID:n att posta mot, `overdue` att märka raden med och
            // `visible_from` att visa glappet med. Två propar ur samma
            // relation hade varit två sanningar om samma rad.
            'schedules' => ScheduleResource::collection($schedules)->resolve($request),
            'openOccurrences' => $this->openOccurrences($schedules, $request),

            // Utlåningen (issue 67a § Beslut 1 och 2). `openLoan` är den rad
            // som `returned_at IS NULL` — itemets enda status en annan medlem
            // behöver se på en sekund — och `loanHistory` är de stängda
            // raderna, i samma ordning som `/api` ger dem. Båda ur
            // App\Http\Resources\LoanResource, samma format som
            // `Api\LoanController::index()` svarar med.
            'openLoan' => $openLoan === null
                ? null
                : (new LoanResource($openLoan))->resolve($request),
            'loanHistory' => LoanResource::collection($history)->resolve($request),

            // Försenad är härledd och räknas här, aldrig i vyn (Beslut 5,
            // samma regel som `overdue` i issue 63b § Beslut 3): en klient med
            // fel klocka ska inte kunna färga en utlåning röd, och en kolumn
            // hade krävt ett jobb som förr eller senare missar en körning
            // ([[ADR-0005 Schema och förekomst]]). Flaggan ligger BREDVID
            // resursen och inte i den: `app/Http/Resources/**` är `/api`:s
            // format och rörs inte av den här issuen — samma linje som
            // `variants()` och `openOccurrences()` ovan.
            'openLoanOverdue' => $openLoan !== null && $this->isOverdue($openLoan, $user),

            // Användarens datum, av samma skäl: "Tillbaka idag" (Beslut 3)
            // sätter `returned_at` till dagens datum, och vilken dag det är
            // får komma ur samma klocka som avgör vad som är försenat — hennes
            // egen, inte serverns ([[ADR-0044 Användarens dag]] § Beslut 1).
            // Annars kunde en klient med fel datum registrera en återlämning
            // före utlåningen och få ett fältfel hon inte förstår, och mellan
            // midnatt och klockan två svensk tid vore "idag" gårdagen.
            'today' => $user->today()->toDateString(),

            // Sant när användarfiler levereras från en egen origin (Beslut 2).
            // Vyn ritar bildvisaren och PDF-ramen bara då; annars faller
            // leveransen tillbaka på `attachment` och en <img> eller <iframe>
            // mot samma URL vore i bästa fall tom. Flaggan räknas här och
            // läses aldrig ur window.location i klienten.
            'inlineEnabled' => FileOrigin::host() !== null,
            'links' => $this->groupLinks(ItemLinkResource::collection($links)->resolve($request)),
            'counterparts' => $this->counterparts($user, $container, $item, $links, $listItems),

            // Är itemet en av användarens favoriter? Se issue 105 och
            // [[ADR-0042 Designsystemet]] § Konsekvenser. Frågan är per
            // ANVÄNDARE och besvaras här: markeringen är en rad i `favorite`,
            // och `ItemResource` bär inget fält för den med flit — ett faktum
            // om relationen mellan en person och ett item är inte ett faktum
            // om itemet, och en flagga på itemet hade gjort en användares
            // markering till allas i en delad container. Proppen ligger
            // därför BREDVID resursen, som `categories` och `paths` ovan.
            //
            // EN fråga, och samma par som FavoriteController skriver och
            // raderar: EXISTS mot det unika `(user_id, item_id)`. Stjärnan
            // läser svaret för att välja POST eller DELETE, så utan den här
            // raden står `isFavorite` på sin standard och stjärnan kan märka
            // men aldrig avmarkera — vyn kan inte sluta sig till tillståndet
            // själv, och `ItemResource` får det inte bära.
            'isFavorite' => $item->favoritedBy()->where('user_id', $user->id)->exists(),

            'can' => [
                'update' => Gate::forUser($user)->allows('update', $item),
                'delete' => Gate::forUser($user)->allows('delete', $item),
                'create' => Gate::forUser($user)->allows('create', $item),
            ],

            // Historikfliken (issue 116). Proppen finns BARA när fliken är
            // aktiv — se `$auditRows` ovan — och vyn ritar sin tomma rad när
            // listan är tom. Ligger BREDVID resursen och inte i den, samma
            // linje som `categories`, `variants` och `paths`: `ItemResource` är
            // `/api`:s format och har inte bett om fältet.
            ...($auditRows === null ? [] : ['history' => $auditRows]),
        ]);
    }

    /**
     * GET /containers/{container}/items/create — formuläret, se issue 57b
     * § Beslut 1, 4 och 5, och issue 58 § Beslut 1 och 7.
     *
     * **Två vägar in, och grinden följer vägen.** Utan `?parent` är grinden
     * `ContainerPolicy::createItem()` på CONTAINERN — samma grind som `store()`
     * prövar och samma flagga listan ritar sin skapaknapp efter. Med
     * `?parent={ulid}` är grinden `ItemPolicy::create()` på FÖRÄLDERN, och
     * det är den enda väg en omfångsbegränsad mottagare har hit: hon når
     * ingen rot i containern och får 403 på den första vägen, men hon får lägga
     * in "impellerbyte 2026" under det hon redan nått ([[ADR-0028 Åtkomst på
     * itemnivå]] § Beslut: "create får skapa både inuti itemet och nya
     * barn-items"). Samma två grindar, i samma ordning, som `store()` prövar
     * — väljer någon olika svarar formuläret 200 och postningen 403.
     *
     * Föräldern slås upp INOM containern, precis som `store()` gör: en ULID ur en
     * annan container är 404, och en mjukraderad rad löser aldrig upp. Den skickas
     * som `{ulid, name}` och ritas som en rad text — föräldern kommer ur
     * länken och är inget val (§ Beslut 7).
     *
     * **Två väljare, två Actions** (§ Beslut 5). Kategorierna och taggarna
     * hämtas med `ListCategories` och `ListTags` — samma Actions som 56a:s
     * sidor anropar — och är därmed omfångsfiltrerade utan att den här
     * kontrollern formulerar ett filter. Båda skickas som samma resurser
     * deras egna sidor bär.
     *
     * **Kontolistan skickas INTE härifrån** (§ Beslut 4, samma linje som
     * issue 54 § Beslut 5). Den finns redan i den delade propen
     * `auth.accounts`, och en egen fråga för samma lista är en fråga för
     * mycket. Sidan förvalt containerns ägarkonto ur `container.account` när
     * användaren är medlem i det, annars hennes första konto.
     */
    public function create(Request $request, Container $container, ListCategories $listCategories, ListTags $listTags): Response
    {
        $parent = $this->parent($container, $this->parentUlid($request));

        if ($parent !== null) {
            Gate::authorize('create', $parent);
        } else {
            Gate::authorize('createItem', $container);
        }

        $container->loadMissing('account');

        $user = $request->user();

        return Inertia::render('Containers/Items/Create', [
            'container' => ContainerResource::make($container)->resolve($request),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))->resolve($request),
            'tags' => TagResource::collection($listTags->handle($user, $container))->resolve($request),
            'parent' => $parent === null ? null : ['ulid' => $parent->ulid, 'name' => $parent->name],
        ]);
    }

    /**
     * POST /containers/{container}/items — 302 till det nya itemets detaljvy.
     *
     * `StoreItemRequest` delas rakt av med `/api` och har redan bevisat att
     * `account` finns, att `category` (om någon) hör till DEN HÄR containern och
     * inte är mjukraderad, och samma sak för varje tagg-ULID och för
     * `parent`. `parent` filtreras bort ur `safe()` tillsammans med `account`,
     * `category` och `tags` — ingen av dem är en kolumn (App\Models\Item
     * § Fillable).
     *
     * **Två grindar, en per väg** (issue 58 § Beslut 7, samma par och samma
     * ordning som `Api\ItemController::store()` sedan issue 71 § Beslut 2).
     * Utan `parent` landar itemet på toppnivån och grinden är
     * `ContainerPolicy::createItem()` på CONTAINERN — ett toppnivå-item har ingen
     * förälder att auktorisera mot, och en omfångsbegränsad mottagare når
     * ingen rot. Med `parent` är grinden `ItemPolicy::create()` på
     * FÖRÄLDERN, och den som har `createItem` på containern men inte `create` på
     * föräldern får 403. Att blanda ihop dem ger en itemgrant rätt att lägga
     * en rot i containern.
     *
     * **Länkningen går genom `LinkItems`, i SAMMA transaktion som
     * skrivningen** (§ Beslut 7) — aldrig en handskriven `ItemLink`-rad, så
     * normaliseringen, dubbettspärren och cykelkontrollen från issue 14
     * gäller. Ingen egen cykelkontroll här: den finns på ett ställe.
     *
     * **Medlemsprövningen är inte en policyfråga** (§ Beslut 2 och 4). Att
     * användaren inte är medlem i det anropade kontot är 403 — samma prövning
     * och samma svar som `Api\ItemController::store()` ger. Den formuleras
     * INTE som `Gate::authorize('create', [Container::class, $account])`, som
     * handlar om att skapa containers.
     *
     * Itemet, taggknytningen och länken ligger i EN transaktion, precis som i
     * `Api\ItemController::store()`: ett item sparat med halv taggning — eller
     * utan sin förälder — är ett tillstånd användaren varken kan se eller
     * rätta.
     *
     * Sedan issue 109 bor den skrivningen i `App\Actions\Item\CreateItem`,
     * som båda ytorna använder — och som skriver händelseloggens rader i
     * samma transaktion. `parent` beskriver vad FÖRÄLDERN är för det nya
     * itemet, inte tvärtom: samma riktning som `Api\ItemController::store()`
     * och samma ord som `LinkItems::normalize()` förväntar sig.
     */
    public function store(StoreItemRequest $request, Container $container, CreateItem $createItem): RedirectResponse
    {
        $parent = $this->parent($container, $request->validated('parent'));

        if ($parent !== null) {
            Gate::authorize('create', $parent);
        } else {
            Gate::authorize('createItem', $container);
        }

        $account = Account::where('ulid', $request->validated('account'))->firstOrFail();

        if (! $account->users()->whereKey($request->user()->id)->exists()) {
            abort(403);
        }

        $category = $this->category($container, $request->validated('category'));

        // Taggarna slås upp EN gång: requesten har redan bevisat varje ULID,
        // så uppslaget är betrott — i klump, en fråga oavsett antal (issue
        // 13b § Beslut 6). Webben skickar alltid hela mängden, så en tom
        // lista betyder "inga taggar" och inte "rör dem inte" (§ Beslut 6).
        $tags = Tag::whereIn('ulid', $request->validated('tags') ?? [])->get();

        $item = new Item($request->safe()->except(['account', 'category', 'tags', 'parent']));

        $createItem->handle($container, $account, $request->user(), $item, $category, $tags, $parent);

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-created');
    }

    /**
     * GET /containers/{container}/items/{item}/edit — formuläret, se issue
     * 57b § Beslut 1, 4, 5 och 6.
     *
     * Grinden är ITEMETS `update`, inte containerns: en `create`-mottagare lägger
     * till, men rör aldrig något som redan står där (§ Beslut 2).
     *
     * **Samma två väljare som create(), och samma Actions.** Redigeringsytan
     * byter kategori och taggar, och listorna är containerns — hämtade, inte
     * omskrivna.
     *
     * **Inget `account` här** (§ Beslut 4). `UpdateItemRequest` tar inte emot
     * fältet och formuläret ritar det inte: vem som skapade raden är historik.
     */
    public function edit(Request $request, Container $container, Item $item, ListCategories $listCategories, ListTags $listTags, ResolveItemCover $resolveItemCover): Response
    {
        Gate::authorize('update', $item);

        $container->loadMissing('account');

        // En enda rad, men ladda relationerna uttryckligen ändå så resursen
        // aldrig kör en oplanerad lazy-load — samma resonemang som show().
        $item->loadMissing(['category', 'createdByAccount', 'tags']);

        $user = $request->user();

        // Itemets bilder, i upplösningens egen ordning — äldst först (issue
        // 93). EN fråga, och urvalet (`kind = 'image'`, mjukraderade bort)
        // bor i Actionen: kontrollern letar inte själv bland bilagorna, för en
        // andra regel om vad som är en bild glider ifrån den första. Listan är
        // både väljarens alternativ och uppslaget till `cover` nedan.
        $images = $resolveItemCover->images($item);

        return Inertia::render('Containers/Items/Edit', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => (new ItemResource($item))->resolve($request),
            'categories' => CategoryResource::collection($listCategories->handle($user, $container))->resolve($request),
            'tags' => TagResource::collection($listTags->handle($user, $container))->resolve($request),

            // Väljarens alternativ: ULID och filnamn, i Actionens ordning.
            // Filnamnet är det enda som skiljer två bilder åt för användaren,
            // och ordningen är upplösningens egen — vyn sorterar den aldrig om
            // (samma regel som itemlistan, issue 57a § Beslut 8): två ordningar
            // av samma lista hade gjort "den äldsta" till två olika bilder.
            'images' => $this->imageOptions($images),

            // Det VALDA omslaget — pekaren och inte den upplösta bilden.
            // Skillnaden är hela "rensa": väljer användaren ingenting är
            // pekaren null och upplösningen gäller, och vyn ska då inte få det
            // foto regeln råkade peka ut (annars gick valet aldrig att ta
            // tillbaka).
            //
            // Pekaren kan stå kvar på en bilaga som mjukraderats; hon finns
            // inte i listan, och `firstWhere` svarar då null. Det är rätt svar:
            // valet är borta, och upplösningens steg 2 gäller.
            'cover' => $images->firstWhere('id', $item->cover_attachment_id)?->ulid,
        ]);
    }

    /**
     * PATCH /containers/{container}/items/{item} — 302 till detaljvyn.
     *
     * **Grinden är `update` på ITEMET** (§ Beslut 2), aldrig `create`: en
     * `create`-mottagare får 403 här, och det gäller hela kroppen — det finns
     * ingen fältvis grind, för ingen del av ett befintligt item är något en
     * `create`-mottagare får ändra (issue 71 § Beslut 3).
     *
     * **Webben skickar ALLTID `category` och `tags`** (§ Beslut 6), och
     * därför finns ingen `has()`-gren här. `Api\ItemController::update()`
     * skiljer på ett UTELÄMNAT fält ("rör det inte") och ett uttryckligt
     * `null`/`[]` ("töm det") med `$request->has()`, aldrig `filled()` — en
     * skillnad som finns för en partiell PATCH från en API-klient. Formuläret
     * har alltid båda fälten med ett valt värde, så den grenen skulle aldrig
     * tas, och en gren som aldrig tas är en gren ingen testar (56a § Beslut 5).
     * API:et bär skillnaden; webben behöver den inte.
     *
     * **`cover` är undantaget** (issue 93): väljaren ritas bara när itemet har
     * bilder, så fältet kan saknas i kroppen helt — och då betyder det "rör
     * inte", se raden i metoden.
     *
     * `account` finns inte i `UpdateItemRequest` och inte i formuläret: vem
     * som skapade raden är historik (§ Beslut 4).
     *
     * **Omslagsbilden sätts, byts och RENSAS i samma PATCH** (issue 93 ·
     * [[ADR-0041 Itemets vy]] § Beslut). Fältet `cover` bär bilagans ULID,
     * `cover: null` tömmer valet och låter upplösningens steg 2 gälla, och
     * `UpdateItemRequest` har redan prövat att bilagan finns, hör till DET HÄR
     * itemet, är en bild och lever. Uppslaget till löpnummer går genom
     * App\Actions\Item\ResolveItemCover::images() — samma urval som
     * upplösningen själv använder — så en bilaga som hinner mjukraderas mellan
     * valideringen och skrivningen blir `null` i stället för en pekare till en
     * bild ingen kan visa (samma resonemang som kategorin: valideringen äger
     * regeln, kontrollern gör ett uppslag).
     *
     * Sedan issue 109 bor skrivningen i `App\Actions\Item\UpdateItem`, som
     * båda ytorna använder — och som loggar vilka fält som ändrades, eller
     * ingenting alls när formuläret skickades oförändrat.
     */
    public function update(UpdateItemRequest $request, Container $container, Item $item, ResolveItemCover $resolveItemCover, UpdateItem $updateItem): RedirectResponse
    {
        Gate::authorize('update', $item);

        $item->fill($request->safe()->except(['category', 'tags', 'cover']));
        $item->category_id = $this->category($container, $request->validated('category'))?->id;

        // Omslagsbilden har en `has()`-gren, till skillnad från `category` och
        // `tags` ovanför: ett UTELÄMNAT `cover` lämnar pekaren orörd medan ett
        // uttryckligen skickat `cover: null` rensar den (UpdateItemRequest
        // § docblock). Formuläret ritar ingen väljare när itemet saknar bilder
        // — ett item vars enda bild ligger i papperskorgen är just det fallet —
        // och skickar då inte fältet alls. Utan grenen hade ett namnbyte där
        // rensat ett val som papperskorgen lovar ska gå att återställa.
        if ($request->has('cover')) {
            $cover = $request->validated('cover');
            $item->cover_attachment_id = $cover === null
                ? null
                : $resolveItemCover->images($item)->firstWhere('ulid', $cover)?->id;
        }

        // Taggmängden synkas ALLTID — webben skickar alltid hela mängden, så
        // `tags: []` betyder "töm" och inte "rör inte" (§ Beslut 6).
        // Actionen skriver itemet, taggarna och loggraderna i EN transaktion
        // (issue 13b § Beslut 7, issue 109).
        $updateItem->handle(
            $item,
            $request->user(),
            true,
            Tag::whereIn('ulid', $request->validated('tags') ?? [])->get(),
        );

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'item-updated');
    }

    /**
     * DELETE /containers/{container}/items/{item} — 302 till containerns
     * förstasida, alltså ÖVERSIKTEN (issue 89 · [[ADR-0039 Containerns
     * översikt]]).
     *
     * `containers.show` pekar på containerns egen URL och gjorde det före
     * flytten också; det som bytte plats är vad som svarar där. Att raderingen
     * landar på översikten och inte i listan är issuens "Klart när" och inte
     * en slump: itemet är borta, och den som just raderat något möts av
     * containern snarare än av listan hon kom ifrån.
     *
     * **Grinden är ITEMETS `delete`, en egen pinne** (§ Beslut 2): en
     * `write`-mottagare ändrar itemet men tar inte bort det, och `can.delete`
     * var falskt för henne redan i 57a — det är den här grinden som faktiskt
     * gäller.
     *
     * Raderingen är MJUK. App\Models\Item använder SoftDeletes, så `delete()`
     * sätter `deleted_at` och ingenting annat ([[ADR-0008 Soft delete och
     * papperskorg]]); ingen fysisk gallring öppnas här och ingen kaskad —
     * itemets beroenden följer itemet. Papperskorgen som listar och
     * återställer är issue 62.
     *
     * Sedan issue 109 bor skrivningen i `App\Actions\Item\DeleteItem`, som
     * båda ytorna använder — och som skriver händelseloggen i samma
     * transaktion.
     */
    public function destroy(Request $request, Container $container, Item $item, DeleteItem $deleteItem): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $deleteItem->handle($item, $request->user());

        return redirect()
            ->route('containers.show', $container)
            ->with('status', 'item-deleted');
    }

    /**
     * Kategorin ur en ULID, uppslagen INOM containern — samma uppslag som `/api`
     * gör. `scopeBindings()` skyddar `{item}`, men `category` kommer ur
     * kroppen och StoreItemRequest/UpdateItemRequest har redan bevisat att
     * ULID:en finns i DEN HÄR containern och inte är mjukraderad.
     */
    private function category(Container $container, ?string $categoryUlid): ?Category
    {
        return $categoryUlid === null
            ? null
            : $container->categories()->where('ulid', $categoryUlid)->firstOrFail();
    }

    /**
     * Föräldern ur en ULID, uppslagen INOM containern — samma uppslag och samma
     * skäl som `category()` ovan, och samma som `Api\ItemController::store()`
     * gör. `StoreItemRequest` har redan bevisat att ULID:en finns i DEN HÄR
     * containern och inte är mjukraderad; uppslaget här är vad som ger 404 i
     * stället för 422 om raden hinner försvinna mellan de två.
     */
    private function parent(Container $container, ?string $parentUlid): ?Item
    {
        return $parentUlid === null
            ? null
            : $container->items()->where('ulid', $parentUlid)->firstOrFail();
    }

    /**
     * `parent` ur query-strängen på skapandeformuläret (§ Beslut 7).
     *
     * Värdet är användarinput och kan komma som en lista (`?parent[]=…`) —
     * allt annat än en sträng läses som "ingen förälder". Formuläret är en
     * GET utan FormRequest (§ Beslut 7 i issue 58: ingen ny FormRequest), så
     * den enda gränsen mot en array finns här; `store()` får samma fält
     * färdigvaliderat av StoreItemRequest.
     */
    private function parentUlid(Request $request): ?string
    {
        $parent = $request->query('parent');

        return is_string($parent) ? $parent : null;
    }

    /**
     * Filtren ur querysträngen, lösta mot containerns EGNA taggar och kategorier —
     * issue 59a § Beslut 3.
     *
     * **Ingen FormRequest och ingen ny regel.** `IndexItemRequest` äger
     * reglerna och `/api` behåller dem orörda; här lånas bara FORMEN: `q` är
     * en sträng, `tags` är en lista av ULID och `category` ett enskilt. Allt
     * annat — `?q[]=…`, `?tags=x`, `?category[]=…` — läses som "inget
     * filter", samma linje som `parentUlid()` ovan.
     *
     * **Trädet är `ListCategories`:s, alltså det omfångsfiltrerade.** En
     * kategori mottagaren inte når finns inte i listan och blir därmed ett
     * bortfallet filter, precis som en raderad tagg. Att lösa upp mot
     * `container->categories()` i stället hade gett henne ett filter hon inte
     * kan se — och därmed ett svar som ser ut som en bugg.
     *
     * **Ett värde som inte finns kvar är ett BORTFALLET filter, inte ett
     * fel** (Beslut 3). Den som skickade länken är inte här, och svaret hon
     * får är listan UTAN det filtret plus en rad om att ett föll bort. `q`
     * trimmas som `IndexItemRequest::prepareForValidation()` gör, och en
     * blank `q` är samma sak som ingen `q`; detsamma gäller en blank
     * `category`, medan ett tomt taggvärde räknas som ett värde som inte
     * finns. Längdregeln på `q` (255) upprätthålls INTE här: en för lång
     * sökterm i ett gammalt bokmärke ska svara "inga träffar", inte en
     * felsida.
     *
     * Ordningen på de behållna taggarna är den inskickade — vyn ritar dem i
     * länkens ordning och inte i tagglistans.
     *
     * @param  Collection<int, Tag>  $tags  containerns taggar inom omfånget
     * @param  Collection<int, Category>  $categories  containerns kategoriträd inom omfånget
     * @return array{0: array{q: string|null, tags: list<string>, category: string|null}, 1: bool} filtret och huruvida något föll bort
     */
    private function filter(Request $request, Collection $tags, Collection $categories): array
    {
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : null;

        if ($q === '') {
            $q = null;
        }

        $requestedTags = $request->query('tags');
        $requestedTags = is_array($requestedTags)
            ? array_values(array_filter($requestedTags, fn ($ulid) => is_string($ulid) && $ulid !== ''))
            : [];

        $visibleTags = $tags->pluck('ulid')->all();
        $keptTags = array_values(array_intersect($requestedTags, $visibleTags));

        $requestedCategory = $request->query('category');
        $requestedCategory = is_string($requestedCategory) && $requestedCategory !== ''
            ? $requestedCategory
            : null;

        $keptCategory = $requestedCategory !== null && $categories->contains('ulid', $requestedCategory)
            ? $requestedCategory
            : null;

        $dropped = $keptTags !== $requestedTags
            || ($requestedCategory !== null && $keptCategory === null);

        return [
            ['q' => $q, 'tags' => $keptTags, 'category' => $keptCategory],
            $dropped,
        ];
    }

    /**
     * Vägarna från App\Actions\Item\ResolveItemPaths som vyens prop — varje
     * väg som sina led, och exakt en av dem märkt som den AKTUELLA
     * (issue 95 · [[ADR-0041 Itemets vy]] § Beslut).
     *
     * **Querysträngen väljer förekomsten, och det är samma regel som filtret i
     * issue 59a § Beslut 1:** samma rutt, samma sida, inget eget läge — ett
     * delat läge är en LÄNK som går att spara, dela och backa ur. Markeringen
     * räknas här och inte i vyn, av samma skäl som `filter()` löser upp sina
     * ULID:ar här: vyn ska inte bära en andra upplaga av en serverregel.
     *
     * **En väg som inte längre finns ignoreras, och den första i ordningen
     * används i stället. Aldrig ett fel.** Trädet har byggts om, ett led har
     * raderats, någon har delat en gammal länk: svaret är vyn med den första
     * förekomsten, utan en rad om varför. En delad länk som slutar fungera
     * för att någon flyttat ett item är en fälla, inte ett fel — och en 404
     * eller en 422 hade varit precis den fällan. Utan `?path` gäller samma
     * sak: den första i ordningen är den aktuella.
     *
     * **Ett okänt ULID, skräp och en lista behandlas likadant** — ingen av dem
     * matchar en väg, alltså gäller den första. Ingen validering, ingen
     * felkod: den som klickade på länken är inte här, och svaret hon får är
     * sidan hon bad om.
     *
     * MARKERINGEN LÄCKER INGENTING. Antalet vägar i svaret är antalet vägar
     * hon ser, och `current` säger vilken av DEM som är den aktuella —
     * ingenting om hur många som föll bort (issue 73 § Beslut 6).
     *
     * @param  list<list<array{ulid: string, name: string}>>  $paths
     * @return list<array{current: bool, nodes: list<array{ulid: string, name: string}>}>
     */
    private function itemPaths(array $paths, Request $request): array
    {
        $requested = $this->requestedPath($request);

        // Den första i ordningen är utgångsläget: den gäller utan `?path`, och
        // den gäller när den begärda vägen inte finns bland de upplösta.
        $current = 0;

        foreach ($paths as $index => $path) {
            if ($this->isRequestedPath($path, $requested)) {
                $current = $index;

                break;
            }
        }

        $occurrences = [];

        foreach ($paths as $index => $path) {
            $occurrences[] = ['current' => $index === $current, 'nodes' => $path];
        }

        return $occurrences;
    }

    /**
     * Vägen ur querysträngen, som en lista ULID:ar (issue 95).
     *
     * Värdet är användarinput och kan komma som en lista (`?path[]=…`) — allt
     * annat än en sträng läses som "ingen väg", samma gräns och samma form som
     * `parentUlid()` och `filter()`. Ingen FormRequest och ingen ny regel:
     * formen lånas, precis som i 59a § Beslut 3.
     *
     * Ingen tolkning av innehållet sker här. Ett skräpvärde ger en lista som
     * inte matchar någon väg, och det är hela behandlingen.
     *
     * @return list<string>
     */
    private function requestedPath(Request $request): array
    {
        $path = $request->query('path');

        if (! is_string($path) || $path === '') {
            return [];
        }

        return explode('.', $path);
    }

    /**
     * Är $requested den här vägen? Led för led, i ordning och i samma antal.
     *
     * Ingen prefixmatchning och ingen delmängd: en väg är hela ledet från
     * roten ned till itemet, och en kortare sträng är en annan väg — eller
     * ingen alls. Två led med samma ULID kan inte följa på varandra (en
     * cykel avbryts i upplösningen), så en jämförelse led för led är
     * tillräcklig.
     *
     * @param  list<array{ulid: string, name: string}>  $path
     * @param  list<string>  $requested
     */
    private function isRequestedPath(array $path, array $requested): bool
    {
        if (count($path) !== count($requested)) {
            return false;
        }

        foreach ($path as $index => $node) {
            if ($node['ulid'] !== $requested[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Relationerna i sina tre grupper (§ Beslut 9), i ordningen
     * överordnade–underordnade–relaterade. Nycklarna är relationens tre värden,
     * alltså samma ord som `ItemLinkResource` bär i `relation`, och
     * ordningen INOM varje grupp är den Actionen levererade (motpartens
     * namn) — grupperingen sorterar inte om något.
     *
     * @param  list<array{item: array{ulid: string, name: string}, relation: string}>  $links
     * @return array{parent: list<array<string, mixed>>, child: list<array<string, mixed>>, related: list<array<string, mixed>>}
     */
    private function groupLinks(array $links): array
    {
        $groups = ['parent' => [], 'child' => [], 'related' => []];

        foreach ($links as $link) {
            $groups[$link['relation']][] = $link;
        }

        return $groups;
    }

    /**
     * Motparterna som går att knyta det här itemet till (§ Beslut 5).
     * Kandidaterna är containerns items INOM användarens omfång — ListItems äger
     * det filtret och den här kontrollern formulerar inget eget — minus
     * itemet självt och de som redan är kopplade. Varje kandidat prövas
     * dessutom med `update`, för en relation kräver `write` i BÅDA ändar
     * (issue 71 § Beslut 4, issue 14 § Beslut 4).
     *
     * **Filtret är artighet och inte skydd.** Grinden i
     * App\Http\Controllers\ItemLinkController::store() är den som gäller,
     * och den prövar samma sak igen — en kandidat som slinker igenom här
     * nekas där.
     *
     * **Noll extra frågor per kandidat.** `container` sätts ur den redan
     * hämtade containern, så ItemPolicy slipper slå upp den per rad, och
     * App\Actions\Access\ResolveItemScope är memoiserad per
     * `{user}:{container}` — samma resonemang som `can`-flaggorna i klassen.
     *
     * @param  Collection<int, ItemLink>  $links
     * @return list<array{ulid: string, name: string}>
     */
    private function counterparts(?User $user, Container $container, Item $item, Collection $links, ListItems $listItems): array
    {
        // $user är nollbar därför att Request::user() är det; rutten ligger
        // bakom `auth`, så i drift är svaret aldrig tomt av den anledningen.
        if ($user === null) {
            return [];
        }

        $linked = $links->pluck('counterpart_ulid')->all();

        return $listItems->handle($user, $container)
            ->reject(fn (Item $candidate): bool => $candidate->id === $item->id
                || in_array($candidate->ulid, $linked, true))
            ->each(fn (Item $candidate) => $candidate->setRelation('container', $container))
            ->filter(fn (Item $candidate): bool => Gate::forUser($user)->allows('update', $candidate))
            ->map(fn (Item $candidate): array => ['ulid' => $candidate->ulid, 'name' => $candidate->name])
            ->values()
            ->all();
    }

    /**
     * Kategorins ULID → namn, för de kategorier raderna faktiskt pekar på.
     *
     * Byggd ur de eager-laddade relationerna: kategorin på en rad i listan är
     * per definition en kategori användaren ser (det är hennes items kategori,
     * och itemets beroenden följer itemet, [[ADR-0028 Åtkomst på itemnivå]]
     * § Beslut), så uppslaget läcker ingenting och kostar noll frågor.
     *
     * En tom tabell är rätt svar när ingen rad har en kategori — vyn ritar
     * då ingen kategorirad.
     *
     * @param  iterable<Item>  $items
     * @return array<string, string>
     */
    private function categoryNames(iterable $items): array
    {
        $names = [];

        foreach ($items as $item) {
            $category = $item->category;

            if ($category instanceof Category) {
                $names[$category->ulid] = $category->name;
            }
        }

        return $names;
    }

    /**
     * Är utlåningen försenad? Sant när `due_at` ligger i det förflutna och
     * raden fortfarande är öppen (issue 67a § Beslut 5).
     *
     * **Beräknad, aldrig lagrad** — samma regel som `overdue` i
     * App\Http\Resources\ScheduleOccurrenceResource: ett tillstånd klockan
     * ändrar kräver annars ett jobb som förr eller senare missar en körning.
     * Jämförelsen görs mot användarens kalenderdag, `$user->today()`
     * ([[ADR-0044 Användarens dag]] § Beslut 1), och aldrig mot klientens —
     * en telefon med fel datum ska inte kunna färga en utlåning röd. Serverns
     * datum vore fel mellan midnatt och klockan två svensk tid, då det ännu
     * är gårdagen i UTC.
     *
     * En öppen utlåning utan `due_at` är inte försenad: ingen har sagt när
     * den skulle tillbaka.
     */
    private function isOverdue(Loan $loan, User $user): bool
    {
        return $loan->due_at !== null && $loan->due_at->lessThan($user->today());
    }

    /**
     * Itemets bilder som väljarens alternativ — ULID och filnamn (issue 93 ·
     * [[ADR-0041 Itemets vy]] § Beslut).
     *
     * **Två fält och inte `AttachmentResource`.** Väljaren ritar ett filnamn
     * per rad, och resursen bär `mime_type`, `byte_size` och
     * `billed_account` — tre relationer att eager-ladda för en lista som inte
     * visar dem. Formen är `categoryNames()`s och `counterparts()`s: ett
     * uppslag BREDVID resursen, utan att resursen rörs
     * (`app/Http/Resources/**` är `/api`:s format).
     *
     * Filnamnet är det enda som skiljer två bilder åt för användaren, så det
     * är etiketten. Ordningen är Actionens egen — äldst först — och vyn
     * sorterar den aldrig om (samma regel som itemlistan, issue 57a
     * § Beslut 8): det är ordningen upplösningens steg 2 väljer ur, och två
     * ordningar av samma lista hade gjort "den äldsta" till två olika bilder.
     *
     * @param  Collection<int, Attachment>  $images
     * @return list<array{ulid: string, filename: string}>
     */
    private function imageOptions(Collection $images): array
    {
        return $images
            ->map(fn (Attachment $image): array => [
                'ulid' => $image->ulid,
                'filename' => $image->filename,
            ])
            ->values()
            ->all();
    }

    /**
     * Bilagans ULID → de derivatvarianter som finns för dess stored_file,
     * så som vyn behöver dem för att rita en miniatyr (issue 61b § Beslut 1).
     *
     * **Uppslaget är det som gör en `<img>` ärlig.** `?variant=thumb` mot en
     * bilaga utan derivat svarar 404 (issue 19a § Beslut 5), och en `<img>`
     * som pekar på en 404 är en trasig bild i vyn. Derivaten genereras
     * dessutom i ett köat jobb (issue 18), så en bild som just laddats upp
     * har ännu ingen miniatyr. Vyn ritar därför en miniatyr bara när `thumb`
     * står i listan och en neutral filikon annars — ingen `onerror`-reparation
     * i komponenten.
     *
     * Byggd ur de eager-laddade relationerna, precis som `categoryNames()`:
     * noll extra frågor per rad, och uppslaget läcker ingenting — en bilaga
     * användaren ser är hennes att se.
     *
     * En bilaga utan derivat får en tom lista och inte en utelämnad nyckel:
     * vyns uppslag är detsamma för alla rader, och en nyckel som saknas hade
     * tvingat fram en andra gren i komponenten.
     *
     * @param  iterable<Attachment>  $attachments
     * @return array<string, list<string>>
     */
    private function variants(iterable $attachments): array
    {
        $variants = [];

        foreach ($attachments as $attachment) {
            // Sorterad: relationen har ingen egen ordning, och en prop vars
            // innehåll byter plats mellan två anrop mot samma rad är brus i
            // varje svar Inertia skickar.
            $variants[$attachment->ulid] = $attachment->storedFile->derivatives
                ->pluck('variant')
                ->sort()
                ->values()
                ->all();
        }

        return $variants;
    }

    /**
     * Trädet som panelen ritar: `{ulid, name, children}` per nod (issue 103 ·
     * [[M17 Designsystemet]] § 103).
     *
     * **Formen är nodens eget svar och ingenting mer.** ItemTreeNode bär ULID,
     * namn och barn — löpnumret stannar i upplösningen, och panelen får inte
     * fler fält än den behöver för att rita och navigera: ett `depth` eller en
     * föräldra-ULID hade frestat vyn att bygga en andra väg vid sidan av
     * `?path=`, och den vägen är issue 95:s.
     *
     * **Ett item med två föräldrar förekommer två gånger**, som två noder
     * (App\Support\Item\ItemTreeNode): panelen ritar dem båda, precis som
     * mockupen gör, och den som klickar på den ena landar på den förekomsten.
     *
     * Ett tomt träd är ett giltigt svar och serialiseras som `[]` — hon når
     * ingenting, eller containern är tom. De två går inte att skilja åt, med
     * flit (issue 73 § Beslut 6).
     *
     * @return list<array{ulid: string, name: string, children: list<array<string, mixed>>}>
     */
    private function structure(ItemTree $tree): array
    {
        return $this->structureNodes($tree->roots());
    }

    /**
     * @param  list<ItemTreeNode>  $nodes
     * @return list<array{ulid: string, name: string, children: list<array<string, mixed>>}>
     */
    private function structureNodes(array $nodes): array
    {
        return array_map(
            fn (ItemTreeNode $node): array => [
                'ulid' => $node->ulid(),
                'name' => $node->name(),
                'children' => $this->structureNodes($node->children()),
            ],
            $nodes,
        );
    }

    /**
     * Schemats ULID → den öppna förekomsten, eller `null` för ett schema som
     * inte har någon (issue 63a § Beslut 1, issue 63b § Beslut 2, 3 och 8).
     *
     * **Nästa förfall bor på förekomsten och aldrig på schemat.** Den öppna
     * raden är systemets bokföring av vad schemat faktiskt väntar på — för
     * `fixed` det framflyttade kalenderdatumet, för en pausad rad det datum
     * som står kvar tills den stängs. Att räkna om det här hade varit en
     * andra upplaga av App\Actions\Schedule\OpenNextOccurrence::dueAt(), och
     * de två hade glidit isär ([[ADR-0005 Schema och förekomst]]).
     *
     * **Förekomsten och inte datumet** (63b § Beslut 2, 3 och 8). Sektionen
     * bockar av från itemet, och den behöver ULID:n att posta mot, `overdue`
     * att märka raden med och `visible_from` att visa glappet med. Alla tre
     * är fält i App\Http\Resources\ScheduleOccurrenceResource, och `overdue`
     * kommer därifrån och räknas aldrig i vyn: en klient med fel datum ska
     * inte kunna färga en uppgift röd (Beslut 3).
     *
     * Ett schema som skapats pausat, eller en `none`-uppgift vars enda
     * förekomst är stängd, har ingen öppen rad — nyckeln finns ändå, med
     * `null`, så vyns uppslag är detsamma för alla rader och slipper en andra
     * gren (samma regel som `variants()`). `completedByAccount` behövs inte:
     * en öppen förekomst har inget konto, och resursen läser ändå inte
     * relationen förrän den är satt (jfr `Api\ScheduleOccurrenceController::
     * close()`, som sätter den för hand av samma skäl).
     *
     * Byggd ur den eager-laddade `openOccurrence`-relationen: noll extra
     * frågor per rad (Beslut 9).
     *
     * @param  iterable<Schedule>  $schedules
     * @return array<string, array<string, mixed>|null>
     */
    private function openOccurrences(iterable $schedules, Request $request): array
    {
        $occurrences = [];

        foreach ($schedules as $schedule) {
            $occurrence = $schedule->openOccurrence;

            if ($occurrence === null) {
                $occurrences[$schedule->ulid] = null;

                continue;
            }

            // En öppen förekomst har inget konto, och relationen sätts för
            // hand av samma skäl som `Api\ScheduleOccurrenceController::
            // close()` gör det: resursen läser `completedByAccount`, och en
            // relation som inte är satt är en lazy-load i väntan på att
            // inträffa. Här är den alltid null, och nu står det i koden.
            $occurrence->setRelation('completedByAccount', null);

            $occurrences[$schedule->ulid] = (new ScheduleOccurrenceResource($occurrence))->resolve($request);
        }

        return $occurrences;
    }
}
