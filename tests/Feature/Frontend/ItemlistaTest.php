<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 57a · Containerns itemflik. Se
 * App\Http\Controllers\ItemController::index() och
 * resources/js/pages/Containers/Items/Index.vue.
 *
 * **Sidan svarar med trädet sedan issue 326** · [[ADR-0067 Itemfliken utan
 * listläge]] § 1, 2 och 8. Filen behåller proven som gäller fliken oavsett
 * läge — åtkomst, navigation, `can`-flaggorna och de tomma lägena — och läser
 * trädets rader ur `treeRows` där den tidigare läste listans `items`.
 *
 * Den viktigaste gränsen i filen är OMFÅNGET (Beslut 4, issue 73 § Beslut 2
 * och 6): en omfångsbegränsad mottagare ser bara de items hon når, och svaret
 * får aldrig bära ett tal om hur många rader som filtrerats bort. Sidan visar
 * antalet rader den ritar och ingenting mer. Samma regel bär översiktens
 * itembricka, och att de två talen är samma tal prövas i ContainervyerTest.
 *
 * Den andra är RUTTORDNINGEN (Beslut 1): `GET /containers/create` måste
 * registreras före de rutter som bär `{container}`, annars matchar `{container}`
 * strängen `create` och formuläret blir en 404.
 *
 * Att `/api/containers/{container}/items` svarar exakt som förut prövas av
 * tests/Feature/Item/** och tests/Feature/Omfang/ListningsfilterTest.php, som
 * är gröna utan en enda ändrad förväntan efter utbrytningen i Beslut 3. En ny
 * formulering av samma sak här hade bevisat noll.
 *
 * Trädets kolumner, dess frågekostnad och radens omslag prövas i
 * tests/Feature/Item/TradraderTest.php, som äger App\Actions\Item\
 * ResolveItemTreeRows. Statusarna hör till kartläget och prövas i
 * tests/Feature/Frontend/ContainerkartaTest.php.
 *
 * Hjälparna har prefixet `itemlista` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll, och en container ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemlistaKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item.
 */
function itemlistaItem(Container $container, string $namn, ?User $skapare = null): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot: en itemgrant när $item ges, en
 * container-bred grant annars.
 */
function itemlistaMottagare(Container $container, ?Item $item = null, string $nivå = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Index.vue med kommentarerna borttagna.
 *
 * Ordningskontrollerna nedan letar efter markup, och docblocken räknar upp
 * samma ord (`<h1>`, *New item*, `ItemFilterBar`) i prosa. Utan den här
 * städningen hade en kontroll kunnat stanna på en mening i stället för på
 * raden den gäller.
 */
function itemlistaKod(): string
{
    $kod = File::get(resource_path('js/pages/Containers/Items/Index.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Beslut 1: båda rutterna ligger bakom `auth`. En utloggad besökare skickas
 * till inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från itemfliken och detaljvyn', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $item = itemlistaItem($container, 'Motorn');

    get("/containers/{$container->ulid}")->assertRedirect('/login');
    get("/containers/{$container->ulid}/items")->assertRedirect('/login');
    get("/containers/{$container->ulid}/items/{$item->ulid}")->assertRedirect('/login');
});

/*
 * Klart när (issue 326 · [[ADR-0067 Itemfliken utan listläge]] § 1): itemfliken
 * renderar trädet för en medlem i ägarkontot, med rötterna i namnordning.
 *
 * Sorteringen är serverns — raderna skapas i omvänd bokstavsordning så att ett
 * träd som råkade behålla skapelseordningen faller.
 */
it('renderar trädet sorterat på namn för en medlem i ägarkontot', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    foreach (['Storseglet', 'Motorn', 'Impellern'] as $namn) {
        itemlistaItem($container, $namn, $anvandare);
    }

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->where('container.ulid', $container->ulid)
            ->where('view', 'tree')
            ->has('structure', 3)
            ->where('structure.0.name', 'Impellern')
            ->where('structure.1.name', 'Motorn')
            ->where('structure.2.name', 'Storseglet')
            ->has('treeRows', 3)
            // `can.create` är presentationsflaggan för 57b:s skapayta. Ägar-
            // kontots medlem får den; ytan ritas inte av den här issuen.
            ->where('can.create', true)
    );
});

/*
 * Klart när: `/containers/create` når fortfarande formuläret — ingen av
 * containerrutterna skuggar den.
 *
 * Det är hela skälet att containerns egen rutt har en plats i filen och inte
 * bara en rutt (Beslut 1), och skälet växer med antalet rutter under
 * `{container}`: `create` är ett giltigt värde för en ruttparameter.
 */
it('når fortfarande skapaformuläret på /containers/create', function () {
    withoutVite();

    [, $anvandare] = itemlistaKontext();

    actingAs($anvandare)->get('/containers/create')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Containers/Create')
    );
});

/*
 * Klart när: containernamnet i `/containers` länkar till ITEMLISTAN.
 *
 * Länken prövas mot den href ruttnamnen faktiskt ger — en vy som länkar till
 * en påhittad adress hade annars sett rätt ut i en strukturell kontroll. Det
 * är den kontrollen som fångar att länken hamnade på containerns översikt i
 * stället: `containers.show` pekar fortfarande på `/containers/{ulid}`, och
 * `containers.items.index` är det nya målet (issue 89 · [[ADR-0039
 * Containerns översikt]] § Konsekvenser).
 */
it('länkar containernamnet i containerlistan till itemfliken', function () {
    [, , $container] = itemlistaKontext();

    expect(route('containers.show', $container, false))->toBe("/containers/{$container->ulid}")
        ->and(route('containers.items.index', $container, false))->toBe("/containers/{$container->ulid}/items");

    $sida = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($sida)->toContain(':href="`/containers/${container.ulid}/items`"')
        ->toContain('{{ container.name }}');
});

/*
 * Klart när: `items` är den första raden i containerns undernavigering, och
 * listan har fortfarande TRETTON rader.
 *
 * Navigationen renderas ur containerSections, så raden är beviset — och
 * ordningen ligger i listan, inte i layouten. Raden pekar på itemlistan sedan
 * issue 89, och översikten fick ingen egen rad där: flikraden och
 * omfördelningen av sektionerna var designarbete, och den kom med issue 101.
 *
 * Antalet rader räknas och inte bara nämns: en rad fler vore en ny sida ingen
 * issue bad om, och en rad färre en yta ingen hittar.
 *
 * **Nio blev tio i issue 116.** Historikfliken är en ny SIDA och därmed en ny
 * rad i listan — containerns fjärde flik, på samma villkor som de andra.
 * Provet fångade den med flit: raden är tillagd av en issue som bad om den,
 * och räkningen är vad som skiljer det från en rad någon lagt till i smyg.
 *
 * **Tio blev elva i issue 174.** Uppgiftsfliken är samma sorts rad: en ny
 * sida, en ny rutt och en ny kontrollermetod, bokförd i
 * resources/js/layouts/containerSections.js på den plats ADR-0050 § 4 anger.
 *
 * **Elva blev tolv i issue 175.** Kostnadsfliken är samma sorts rad igen, och
 * av samma skäl: en ny sida, en ny rutt och en ny kontrollermetod.
 *
 * **Tolv blev tretton i issue 178.** Dokumentfliken är samma sorts rad en
 * fjärde gång — en ny sida, en ny rutt och en ny kontrollermetod — och den
 * sista av de fyra som M24 lade till i containerns flikrad.
 *
 * **Räkningen gäller `containerSections` och ingenting annat.** Sedan issue 101
 * bär filen också `containerTabs`, och översiktsraden där är en adress utan
 * sektion — den hör till flikraden och räknas inte hit. En räkning över hela
 * filen hade räknat den som en sektion.
 */
it('lägger itemfliken först i containerns navigation och behåller tretton rader', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("key: 'items'")
        ->toContain('/containers/${ulid}/items');

    expect(strpos($sektioner, "key: 'items'"))
        ->toBeLessThan(strpos($sektioner, "key: 'categories'"));

    preg_match('#export const containerSections = \[(.*?)\n\];#s', $sektioner, $träff);

    expect($träff[1] ?? '')->not->toBe('', 'containerSections finns inte i filen');
    expect(substr_count($träff[1], 'href: (ulid) =>'))->toBe(13);
});

/*
 * Klart när: en användare utan åtkomst till containern får 403 på listan.
 */
it('nekar en främling itemfliken med 403', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    itemlistaItem($container, 'Motorn');

    actingAs(User::factory()->create())->get("/containers/{$container->ulid}/items")->assertForbidden();
});

/*
 * Klart när: en omfångsbegränsad mottagare ser bara de items hon når, och
 * listan bär inget tal om hur många som filtrerats bort.
 *
 * Sidan får visa antalet rader den RITAR — det är längden på en lista
 * användaren ser. Ingen totalsumma, ingen "av N", ingen rad om att något
 * dolts: ett sådant tal är precis vad issue 73 § Beslut 6 förbjuder.
 */
it('visar bara items inom omfånget och bär inget tal om de dolda', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();

    $motorn = itemlistaItem($container, 'Motorn');

    // Tio dolda items med ett eget ord i namnet — namnet i sig är avslöjandet.
    foreach (range(1, 10) as $i) {
        itemlistaItem($container, "Hemlig $i");
    }

    $mottagare = itemlistaMottagare($container, $motorn);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}/items");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Items/Index')
        ->has('treeRows', 1)
        ->has('treeRows.'.$motorn->ulid)
        ->missing('total')
        ->missing('meta')
    );

    // Varken namnet, ULID:n eller ett räknande tal någonstans i svaret.
    expect($svar->getContent())->not->toContain('Hemlig');

    foreach (Item::query()->where('name', 'like', 'Hemlig%')->get() as $dold) {
        expect($svar->getContent())->not->toContain($dold->ulid);
    }
});

/*
 * Klart när: ett `read_only`-ägarkonto tillåter läsning men ger `can.create`
 * falskt.
 *
 * Flaggan är presentation; grinden är `ContainerPolicy::createItem()`, som
 * nekar allt skrivande när ägarkontot är fryst (regel 4).
 */
it('låter ett fryst ägarkonto se trädet men ger can.create falskt', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext(['status' => 'read_only']);
    $item = itemlistaItem($container, 'Motorn', $anvandare);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('treeRows.'.$item->ulid)
            ->where('can.create', false)
    );
});

/*
 * Klart när: en container-bred `create`-innehavare får `can.create` sant, och
 * en omfångsbegränsad mottagare falskt.
 *
 * En itemgrant ger aldrig en rot i containern — hon skapar barn-items under det
 * hon nått, och den ytan hör till detaljvyn (ContainerPolicy::createItem()).
 */
it('ger can.create efter container-bred create och aldrig efter en itemgrant', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $motorn = itemlistaItem($container, 'Motorn');

    $bred = itemlistaMottagare($container, null, 'create');
    $begränsad = itemlistaMottagare($container, $motorn, 'delete');

    actingAs($bred)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.create', true)
    );

    actingAs($begränsad)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.create', false)
    );
});

/*
 * Plusknappens mål på itemlistan, se issue 152 · [[ADR-0048 Mobilen och
 * plusknappen]] § 2. Målet är sidans och kommer ur
 * App\Support\Frontend\CreateTarget, med SAMMA grind som `can.create` ovan:
 * knappen i skalet och raden *Nytt item* i vyn kommer och går tillsammans, och
 * en mottagare som inte får skapa ett toppnivå-item får ingen knapp.
 * Menyn på ett item prövas i tests/Feature/Frontend/PlusknappTest.php.
 */
it('bär plusknappens mål efter samma grind som can.create', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $motorn = itemlistaItem($container, 'Motorn');

    $bred = itemlistaMottagare($container, null, 'create');
    $begränsad = itemlistaMottagare($container, $motorn, 'delete');

    actingAs($bred)->get("/containers/{$container->ulid}/items")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'item')
            ->where('create.href', "/containers/{$container->ulid}/items/create"),
    );

    actingAs($begränsad)->get("/containers/{$container->ulid}/items")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create', null),
    );
});

/*
 * Klart när: en tom container säger att den är tom — inte att den kanske är det.
 *
 * Meningen ligger i `lang/`, aldrig i vyn (Beslut 10), och den får inte
 * antyda att rader dolts: en omfångsbegränsad mottagare med noll items ser
 * samma mening, och "inga träffar bland N" hade avslöjat N.
 */
it('säger att containern är tom och aldrig att den kanske är det', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/items");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('structure', [])
        ->has('treeRows', 0)
        ->where('can.create', true)
    );

    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($en['item']['index']['empty'])->toBe('The container is empty.');
    expect($en['item']['index']['empty'])->not->toBe('');

    // Ingen totalsumma i vyn — raden är hela sidans svar på en tom container.
    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vy)->toContain("t('item.index.empty')");
    expect($vy)->not->toContain('items.length}/');
});

/*
 * Klart när: ingen svensk sträng står kvar i en `.vue`-fil; varje ny nyckel
 * finns på `sv` och `en`.
 *
 * Den första halvan vaktas av SprakTest (som läser varje fil under
 * resources/js). Här prövas den andra: nyckelparen, nyckel för nyckel.
 */
it('har varje item-nyckel', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['index', 'show'] as $grupp) {
        expect(array_keys($en['item'][$grupp]))->toBe(array_keys($sv['item'][$grupp]));

        foreach ($sv['item'][$grupp] as $nyckel => $varde) {
            expect(trim($varde))->not->toBe('', "item.{$grupp}.{$nyckel} är");
            expect(trim($en['item'][$grupp][$nyckel]))->not->toBe('', "item.{$grupp}.{$nyckel} är tom på en");
        }
    }

    // Navigationsraden läses som `container.nav.items` (samma `key` i
    // containerSections.js som i lang-filen).
    expect($sv['container']['nav']['items'])->not->toBe('');
    expect($en['container']['nav']['items'])->not->toBe('');

    // De nya vyerna har inga svenska strängar kvar — samma regel som
    // SprakTest prövar för hela resources/js, riktad mot den här issuen.
    foreach ([
        'pages/Containers/Items/Index.vue',
        'pages/Containers/Items/Show.vue',
        'components/ItemTagList.vue',
    ] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->not->toMatch('/[åäöÅÄÖ]/u', "svensk text utanför kommentar i {$fil}");
    }
});

/*
 * Klart när: statusens text ligger i `lang/` och inte i en `.vue`-fil.
 *
 * Ordet är mockupens, och uppslaget ligger i `item.index.status_*` — en text i
 * en komponent blir aldrig engelsk (issue 52 § Beslut 4, [[ADR-0021
 * Frontendteknik]]).
 *
 * **Uppslaget lämnade listans rad i issue 212** · [[ADR-0050
 * Desktopdesignen]]: listans rad ritar ingen status längre, så raden som bar
 * `t(\`item.index.status_${statuses[item.ulid]}\`)` finns inte kvar.
 * Källkodsprovet mot den nod som ritade ordet togs bort i issue 296, då
 * `ContainerMapNode.vue` utgick ([[ADR-0059 Kartan med en mitt]]); den nya
 * noden ritas i issue 297. Kvar här är katalogens ord och kontrollen att
 * listans vy inte bär ett eget `OK` — ett `OK` där vore en text utanför
 * `lang/`, oavsett vilken rad som ritar den.
 */
it('har statusens text i lang och inte i vyn', function () {
    $en = require lang_path('en/ui.php');

    expect($en['item']['index']['status_ok'])->toBe('OK');
    expect($en['item']['index']['status_overdue'])->not->toBe('');

    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vy)->not->toContain('>OK<');
    expect($vy)->not->toContain("'OK'");
});

/*
 * Rutan i itemfliken prövas sedan issue 327 i
 * tests/Feature/Frontend/ItemtradTest.php: listläget försvann och rutan
 * flyttade till trädets rad ([[ADR-0067 Itemfliken utan listläge]] § 3).
 */

/*
 * Klart när (issue 291 · [[ADR-0058 Trädet som tabell]] § 7): rubriken,
 * *New item* och växeln står på en rad, i den ordningen, i båda lägena.
 *
 * Raden ritas en gång och utanför lägesgrenarna — det är därför den gäller
 * båda — och provet läser därför markupen. En rad som låg inuti trädgrenen
 * hade gett rätt ordning i trädet och ingen rad alls i kartan, och en kontroll
 * mot de två svaren hade inte kunnat se skillnaden.
 */
it('ställer rubriken, New item och växeln på en rad i båda lägena', function () {
    $vy = itemlistaKod();

    $start = strpos($vy, '<div class="mt-6 flex flex-wrap items-center gap-3">');

    expect($start)->not->toBeFalse('huvudraden saknas i Index.vue');

    $slut = strpos($vy, '</div>', (int) $start);
    $huvud = substr($vy, (int) $start, (int) $slut - (int) $start);

    // En rad, inte en stapel.
    expect($huvud)->toContain('flex flex-wrap items-center');
    expect($huvud)->not->toContain('flex-col');

    // Ordningen är bindande: rubriken först, därefter *New item* och växeln.
    $rubrik = strpos($huvud, '<h1');
    $nytt = strpos($huvud, 'item.index.create');
    $vaxel = strpos($huvud, '<ItemViewSwitch');

    expect($rubrik)->not->toBeFalse()
        ->and($nytt)->toBeGreaterThan((int) $rubrik)
        ->and($vaxel)->toBeGreaterThan((int) $nytt);

    // *New item* ritas bara när hon får skapa (57b § Beslut 2).
    expect($huvud)->toContain('v-if="can.create"');

    // Huvudet står före lägesgrenen, alltså också i kartläget.
    expect($start)->toBeLessThan(strpos($vy, '<section v-if="view === \'tree\'"'));
});

/*
 * Klart när (issue 293 · [[ADR-0058 Trädet som tabell]] § 7 och issue 299 ·
 * [[ADR-0059 Kartan med en mitt]] § 10): filterraden ritas i båda lägena.
 *
 * Provet flyttade hit i issue 293: fram till dess hörde raden bara till
 * listan. Den ritas en gång och utanför lägesgrenarna — en rad inuti
 * trädgrenen hade gett rätt svar i trädet och ingen rad alls i kartan.
 * Kartläget fick raden i issue 299, då filtret började välja mitt, och
 * listläget försvann i issue 327 ([[ADR-0067 Itemfliken utan listläge]] § 1).
 */
it('ritar filterraden i båda lägena', function () {
    $vy = itemlistaKod();

    $trad = strpos($vy, '<section v-if="view === \'tree\'"');
    $karta = strpos($vy, '<section v-else class="mt-6">');

    expect($trad)->not->toBeFalse()
        ->and($karta)->toBeGreaterThan((int) $trad);

    // Raden finns en gång, före alla lägesgrenar och utan undantag för kartan.
    expect(substr_count($vy, '<ItemFilterBar'))->toBe(1);

    $filter = strpos($vy, '<ItemFilterBar');

    expect($filter)->toBeLessThan((int) $trad)
        ->and(str_contains($vy, 'v-if="view !== \'map\'"'))->toBeFalse();
});
