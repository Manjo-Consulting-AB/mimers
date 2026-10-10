<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 170 · Containerns hjälte. Se [[ADR-0050 Desktopdesignen]] § 2–4,
 * [[ADR-0047 Containerns bild]] § Beslut,
 * resources/js/components/ContainerHero.vue,
 * resources/js/layouts/ContainerLayout.vue och
 * resources/js/layouts/containerSections.js.
 *
 * **Filen prövar både ytan och propparna.** Hjälten är en form som ritas ur
 * containern — bilden, arten, namnet och hela beskrivningen — och den formen
 * går att läsa ur källkoden; vilken sida som ber om vilken hjälte, och vad
 * `can.update` svarar, är däremot svar som kommer från servern och prövas
 * genom rutterna. Sedan issue 646 ritar hjälten ingen väg till
 * inställningssidan — den ligger i flikraden — och `can`-flaggan bor kvar i
 * skalet för pennan.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att hjälten ser
 * ut som `container.jpeg` och `kostnader.png`, att bilden beskärs rätt i sin
 * ruta, och att kontrasten mellan texten och skuggan håller i en hand. Det
 * står i PR-kroppens handprov. Träffytan på 44 px och frånvaron av sidledsskroll
 * prövas för varje fil i tests/Feature/Frontend/GenomgangTest.php, och att
 * flikraden är *Översikt · Items · Historik* i FlikradTest.php.
 *
 * Kommentarer rensas bort före varje källkodsprov, som i grannfilerna: docblocken
 * är svenska med flit (AGENTS.md § Språk i koden), och en regel som letar efter
 * en markup ska inte kunna nöjas av en mening i en kommentar.
 *
 * Hjälparna har prefixet `hjalte` — Pest lägger alla testfiler i samma namnrymd
 * när hela sviten körs.
 */

/**
 * Ett ägarkonto med en medlem, och en container ägd av kontot.
 *
 * Medlemmen och inte en delegat: hjälten ritas för var och en som når
 * containern, men flaggan `can.update` kräver en containerbred skrivrätt.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function hjalteKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En delegerad läsare: en containerbred `container_access` på `read`, utan
 * konto i ägarkontot. Hon når varje flik men får aldrig `can.update`.
 */
function hjalteLasare(Container $container): User
{
    $lasare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $lasare->id,
        'level' => 'read',
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $lasare;
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function hjalteKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Öppningstaggen för en komponent i en fil, som rå markup — samma form som
 * genomgangTaggar i GenomgangTest. Attributvärdena matchas med citattecken runt
 * om, så ett `>` inuti ett värde inte avslutar taggen i förtid.
 */
function hjalteTagg(string $kod, string $komponent): string
{
    preg_match(
        '/<'.$komponent.'\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
    );

    return $träffar[0] ?? '';
}

/**
 * Hjälten med kommentarer borta.
 */
function hjalteHjalten(): string
{
    return hjalteKod('components/ContainerHero.vue');
}

/*
 * Klart när: hjälten ritar brickorna ur den delade proppen.
 *
 * Talen kommer ur `containerCounts` — `{ items, todos }`, byggd i
 * App\Http\Middleware\HandleInertiaRequests::containerCounts() (issue 679) — och
 * hjälten läser den själv ur `page.props` (issue 680). Sloten `stats` och vägen
 * genom skalet är borta: sidan skickar inga tal, och hjälten frågar inte sidan
 * om dem.
 */
it('ritar brickorna i hjälten ur den delade proppen', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    // Översikten svarar med den delade proppen satt.
    actingAs($ägare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->where('containerCounts.items', 0)
            ->where('containerCounts.todos', 0)
        );

    $hjalte = hjalteHjalten();

    // Hjälten läser proppen själv och fyller brickorna ur den.
    expect($hjalte)->toContain('page.props.containerCounts')
        ->and($hjalte)->toContain('<UiStat :value="counts.items"')
        ->and($hjalte)->toContain('<UiStat :value="counts.todos"');

    // Brickorna ritas i båda formerna: villkoret är talens närvaro — `counts`
    // — och inte hjältens höjd.
    preg_match('#<div\b[^>]*v-if="counts"[^>]*>#s', $hjalte, $träffar);

    $brickrad = $träffar[0] ?? '';

    expect($brickrad)->not->toBe('', 'brickorna ritas inte ur `counts`');
    expect($brickrad)->not->toContain("hero === 'large'");

    expect($hjalte)->toMatch(
        '#<div\b[^>]*v-if="counts"[^>]*>\s*<Link\b.*?<UiStat#s',
        'brickorna står inte inuti brickraden',
    );

    // Sloten `stats` finns inte längre, i någon av de tre filerna: hjälten får
    // talen ur proppen och skalet förmedlar ingenting.
    foreach ([$hjalte, hjalteKod('layouts/ContainerLayout.vue'), hjalteKod('pages/Containers/Overview.vue')] as $kod) {
        expect($kod)->not->toContain('hero-stats')
            ->and($kod)->not->toContain('name="stats"');
    }

    // Och sidan ber fortfarande om den höga formen — sedan issue 275 i sin
    // layoutkedja i stället för i mallen.
    expect(hjalteKod('pages/Containers/Overview.vue'))
        ->toContain("[ContainerLayout, { hero: 'large', container: props.container, can: props.can }]");
});

/*
 * Klart när: brickorna står i den låga hjälten på flikarna.
 *
 * `compact` på varje flik utom översikten (ADR-0050 § 2), och sedan issue 680
 * bär den låga hjälten samma två brickor som den höga: den delade proppen
 * `containerCounts` är satt på varje containersida (issue 679), och hjälten
 * ritar talen när den finns. Provet fäster båda leden: flikarna svarar med
 * proppen satt, och deras skal ber om den låga formen.
 */
it('ritar brickorna i den låga hjälten på flikarna', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    foreach ([
        'pages/Containers/Items/Index.vue' => "/containers/{$container->ulid}/items",
        'pages/Containers/History.vue' => "/containers/{$container->ulid}/history",
    ] as $sokvag => $adress) {
        actingAs($ägare)->get($adress)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('containerCounts.items', 0)
                ->where('containerCounts.todos', 0)
            );

        $vy = hjalteKod($sokvag);

        expect($vy)->toContain("[ContainerLayout, { hero: 'compact'");
    }
});

/*
 * Klart när: brickorna är länkar.
 *
 * *Items* leder till containerns itemlista och *Open tasks* till fliken
 * *Tasks* — samma grepp som dashboardens brickor fick i issue 654: länken bor i
 * hjälten och inte i `UiStat`, som förblir en form. Provet fäster adresserna mot
 * ruttabellen, läser länkarna ur källkoden och kräver att varje bricka omsluts
 * av sin egen `<Link>` med fokusringen.
 */
it('länkar itembrickan till itemlistan och uppgiftsbrickan till uppgiftsfliken', function () {
    withoutVite();

    [, , $container] = hjalteKontext();

    // Adresserna är ruttnamnens egna, så en flyttad rutt fälls här och inte
    // först i webbläsaren.
    expect(route('containers.items.index', $container, false))
        ->toBe("/containers/{$container->ulid}/items")
        ->and(route('containers.tasks', $container, false))
        ->toBe("/containers/{$container->ulid}/tasks");

    $hjalte = hjalteHjalten();

    expect($hjalte)->toContain('`/containers/${container.ulid}/items`')
        ->and($hjalte)->toContain('`/containers/${container.ulid}/tasks`');

    // Varje bricka omsluts av sin egen `<Link>`, och länken bär fokusringen —
    // samma klassrad som DashboardStats.vue.
    preg_match_all(
        '#<Link\b([^>]*)>\s*<UiStat\b([^>]*)/>#s',
        $hjalte,
        $träffar,
        PREG_SET_ORDER,
    );

    expect($träffar)->toHaveCount(2, 'brickorna omsluts inte var för sig av en <Link>');

    expect($träffar[0][1])->toContain('focus-visible:ring-2')
        ->and($träffar[0][2])->toContain(':value="counts.items"')
        ->and($träffar[1][1])->toContain('focus-visible:ring-2')
        ->and($träffar[1][2])->toContain(':value="counts.todos"');
});

/*
 * Klart när: den låga hjälten ritas på alla inställningssidor.
 *
 * Inställningarna är EN flik (ADR-0050 § 2): kolumnen till vänster byter
 * mellan de åtta sidorna utan att lämna fliken, och en hjälte som fanns på
 * `Edit.vue` men försvann på `Categories.vue` hade hoppat mitt i fliken.
 * Attributet sätts därför en gång, i skalet — `ContainerSettingsLayout`
 * skickar `hero="compact"` till `ContainerLayout` — och ingen av de åtta
 * sidorna ändras: de skickar fortfarande ingenting själva, och deras egna
 * `<h1>` står kvar under flikraden som på de andra låga flikarna.
 */
it('ritar den låga hjälten på alla inställningssidor', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    // Sedan issue 275 är attributet sidans: det står i `ContainerLayout`-ledet
    // i var och en av de åttas kedja, och `ContainerSettingsLayout`-ledet bär
    // det inte. Fram till issue 275 satte inställningslayouten `hero` när den
    // ritade `ContainerLayout`; nu ligger den innanför och rör ingen hjälte.
    expect(hjalteKod('layouts/ContainerSettingsLayout.vue'))->not->toContain('hero');

    foreach ([
        'Edit', 'Categories', 'Tags', 'Sharing',
        'CalendarFeed', 'Export', 'Trash', 'Transfers',
    ] as $sida) {
        $vy = hjalteKod("pages/Containers/{$sida}.vue");

        expect($vy)->toContain("[ContainerLayout, { hero: 'compact'");
        expect($vy)->toContain('[ContainerSettingsLayout, { container: props.container }]');
    }

    // Och ägaren når två av dem hela vägen genom servern.
    actingAs($ägare)->get("/containers/{$container->ulid}/edit")->assertOk();
    actingAs($ägare)->get("/containers/{$container->ulid}/categories")->assertOk();
});

/*
 * Klart när: itemvyn ritar ingen hjälte.
 *
 * Itemvyn, formulären, schemasidorna och inställningssidorna skickar ingenting
 * och ser ut som i dag (ADR-0050 § 2 ritar hjälten på containerns flikar, och
 * itemet är en egen sida under itemlistan). Proppen har `null` som förval, så
 * en sida som inte säger något får ingen hjälte — och namnraden över flikraden
 * står kvar precis som förut.
 */
it('ritar ingen hjälte i itemvyn', function () {
    $vy = hjalteKod('pages/Containers/Items/Show.vue');

    // Kedjan står i vyn, och `ContainerLayout`-ledet bär ingen hjälteform.
    expect($vy)->toContain('[ContainerLayout, { container: props.container }]');

    $skal = hjalteKod('layouts/ContainerLayout.vue');

    expect($skal)->toContain('hero: { type: String, default: null }');

    // Namnraden står kvar som `v-else`: en sida utan hjälte ser ut som förut.
    expect($skal)->toMatch(
        '#<p v-else class="hidden[^"]*md:block">\{\{ heading \}\}</p>#s',
        'namnraden ritas inte när hjälten saknas',
    );
});

/*
 * Klart när: översiktens eget huvud ritas bara under `md:`.
 *
 * Regeln är skalets egen, att namnraden ersätts av hjälten där hjälten ritas
 * (ADR-0050 § 2), och den gäller sidans huvud också: hjälten bär namnet, arten
 * och beskrivningen över brytpunkten, och en andra rad med samma text är både
 * en synlig dubblett och en skärmläsare som läser fel. Rubriken ska finnas på
 * varje bredd, och därför är hjältens namn sidans `<h1>` — under `md:` ritas
 * hjälten inte, och då står sidans eget huvud kvar oförändrat.
 */
it('ritar översiktens eget huvud bara under md', function () {
    $vy = hjalteKod('pages/Containers/Overview.vue');

    expect(hjalteTagg($vy, 'h1'))->toContain('md:hidden');

    // Listan bär art och beskrivning — de två fält hjälten upprepar — och hela
    // listan hör därför till huvudet över brytpunkten.
    expect(hjalteTagg($vy, 'dl'))->toContain('md:hidden');

    // Och rubriken bor i hjälten över `md:`: dess namn är en `<h1>`.
    expect(hjalteHjalten())->toMatch(
        '#<h1\b[^>]*>\s*\{\{ container\.name \}\}\s*</h1>#s',
        'hjältens namn är ingen <h1>',
    );
});

/*
 * Klart när: undertiteln är hela beskrivningen.
 *
 * ADR-0050 § 2: beskrivningen delas inte upp (issue 88) och kortas inte.
 * Provet går hela vägen — en beskrivning med två stycken kommer ORÖRD genom
 * servern till alla tre flikarna — och läser sedan formen: raden ritar
 * `container.description` med `whitespace-pre-line`, och ingen klass kortar
 * den (`truncate`, `line-clamp`).
 */
it('visar hela beskrivningen som undertitel', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    $beskrivning = "Mål 116 • 1984\nRenoverad 2019, ny rigg och nya segel.";

    $container->update(['description' => $beskrivning]);

    foreach ([
        "/containers/{$container->ulid}",
        "/containers/{$container->ulid}/items",
        "/containers/{$container->ulid}/history",
    ] as $adress) {
        actingAs($ägare)->get($adress)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('container.description', $beskrivning)
            );
    }

    $hjalte = hjalteHjalten();

    expect($hjalte)->toContain('{{ container.description }}')
        ->and($hjalte)->toContain('whitespace-pre-line');

    expect($hjalte)->not->toMatch('/\btruncate\b/');
    expect($hjalte)->not->toMatch('/\bline-clamp-/');
});

/*
 * Klart när: en container utan beskrivning har ingen undertitel.
 *
 * Fältet är frivilligt (issue 88), och en tom rad vore ett påstående om att
 * något saknas — samma regel som översiktens huvud. Undertiteln ritas därför
 * ur ett närvarovillkor och inte ur ett sanningsvärde på en färdig mening.
 */
it('utelämnar undertiteln när beskrivningen saknas', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    actingAs($ägare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.description', null)
        );

    expect(hjalteHjalten())->toMatch(
        '#<p\b[^>]*v-if="container\.description"[^>]*>\s*\{\{ container\.description \}\}#s',
        'undertiteln ritas utan att fråga om beskrivningen finns',
    );
});

/*
 * Klart när: hjälten ritar ingen väg till inställningssidan (issue 646).
 *
 * Hjälten bar två grenar till `/containers/{ulid}/edit` — *Redigera container*
 * för den som får ändra (ADR-0050 § 3) och länken *Inställningar* för läsaren.
 * Båda togs bort i issue 646, som i stället lade *Settings* i flikraden för
 * alla som når containern: fliken är samma adress och samma `view`-grindade
 * sida, och två vägar till samma val var just det ADR-0050 § 3 ville bort.
 *
 * Provet fäster frånvaron i källkoden och att sidan själv står kvar: både
 * ägaren och läsaren får 200 på adressen — vägen dit är flikens och skalets,
 * inte hjältens — och läsaren nekas fortfarande `PATCH`, för flaggan var
 * presentation och aldrig grinden.
 */
it('ritar ingen redigeringslänk i hjälten', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();
    $läsare = hjalteLasare($container);

    $hjalte = hjalteHjalten();

    expect($hjalte)->not->toContain('/edit');
    expect($hjalte)->not->toContain('container.hero.edit');

    // Ingen av dem tappar vägen dit: sidan svarar 200 för båda, och fliken i
    // raden (ContainerflikTest, FlikradTest) och skalets rad under `md:`
    // (provet nedanför) bär den.
    $adress = "/containers/{$container->ulid}/edit";

    actingAs($ägare)->get($adress)->assertOk();
    actingAs($läsare)->get($adress)->assertOk();

    // Flaggan finns kvar och skiljer dem åt på översikten — den styr pennan i
    // skalets topprad, som är en annan väg till samma val.
    actingAs($ägare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', true));

    actingAs($läsare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));

    // Och läsaren nekas fortfarande skrivningen, som varje annan gång flaggan
    // är presentation.
    actingAs($läsare)->patch("/containers/{$container->ulid}", ['name' => 'Tjuvnamn'])
        ->assertForbidden();

    expect($container->fresh()->name)->toBe($container->name);
});

/*
 * Klart när: itemlistan och historiken bär `can.update`.
 *
 * De två flikarna skickade inte flaggan förut (den kom med pennan i skalets
 * topprad, och bara översikten och inställningssidan bar den). Hjälten ritar
 * *Redigera container* på varje sida som visar den, så kontrollerna lägger
 * flaggan bredvid resursen — samma policyfråga som
 * `ContainerController::show()` ställer.
 */
it('bär can.update i itemlistan och historiken', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();
    $läsare = hjalteLasare($container);

    foreach ([
        "/containers/{$container->ulid}/items",
        "/containers/{$container->ulid}/history",
    ] as $adress) {
        actingAs($ägare)->get($adress)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', true));

        actingAs($läsare)->get($adress)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));
    }
});

/*
 * Klart när: under `md:` når var och en inställningssidan.
 *
 * Hjälten ritas inte under brytpunkten (ADR-0050 § 2), och toppraden från issue
 * 151 bär bara namn, bild och tillbakaknapp — flikraden var alltså den enda
 * vägen till inställningarna, och den slutade bära dem i issue 170. Skalet
 * ritar därför raden *Inställningar* direkt efter flikraden, för var och en:
 * också den som får ändra, för på en telefon är raden den enda vägen dit.
 * Adressen är inställningssidans egen rutt, och den är `view`-grindad — provet
 * går hela vägen dit som läsare.
 */
it('låter var och en nå inställningssidan under md', function () {
    withoutVite();

    [, , $container] = hjalteKontext();
    $läsare = hjalteLasare($container);

    actingAs($läsare)->get("/containers/{$container->ulid}/edit")->assertOk();

    $skal = hjalteKod('layouts/ContainerLayout.vue');

    preg_match('#<UiTabs\b[^>]*/>\s*(<Link\b.*?</Link>)#s', $skal, $träffar);

    $länk = $träffar[1] ?? '';

    expect($länk)->toContain('`/containers/${container.ulid}/edit`')
        ->and($länk)->toContain('md:hidden')
        ->and($länk)->toContain('min-h-11')
        ->and($länk)->toContain("t('container.nav.settings')");

    // Raden ligger efter flikraden och utanför den — flikraden är fortfarande
    // *Översikt · Items · Historik* (FlikradTest) — och den bär ingen flagga:
    // den ritas för var och en.
    expect($länk)->not->toContain('v-if');
});
