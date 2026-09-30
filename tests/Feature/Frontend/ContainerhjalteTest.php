<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
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
 * går att läsa ur källkoden; vilken sida som ber om vilken hjälte, och vem som
 * får se *Redigera container*, är däremot svar som kommer från servern och
 * prövas genom rutterna.
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
 * Klart när: översikten ritar den höga hjälten med talen.
 *
 * Den höga hjälten är översiktens (ADR-0050 § 2), och talen kommer från sidan
 * — layouten frågar ingenting själv. Provet fäster båda leden: sidan ber om
 * `large` och fyller `hero-stats` med sina två `counts`, skalet förmedlar
 * sloten vidare, och hjälten ritar den bara när den är hög.
 */
it('ritar den höga hjälten med talen på översikten', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    actingAs($ägare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->where('counts.items', 0)
            ->where('counts.todos', 0)
        );

    $vy = hjalteKod('pages/Containers/Overview.vue');

    // Sidan ber om den höga formen, och fyller sloten med samma `counts` som
    // brickorna i sidans flöde läser.
    expect(hjalteTagg($vy, 'ContainerLayout'))->toContain('hero="large"')
        ->and($vy)->toContain('<template #hero-stats>')
        ->and($vy)->toContain('<UiStat :value="counts.items"')
        ->and($vy)->toContain('<UiStat :value="counts.todos"');

    // Skalet ritar hjälten ur proppen och förmedlar talen vidare — det läser
    // ingenting själv och räknar ingenting själv.
    $skal = hjalteKod('layouts/ContainerLayout.vue');

    expect(hjalteTagg($skal, 'ContainerHero'))->toContain('v-if="hero"')
        ->and(hjalteTagg($skal, 'ContainerHero'))->toContain(':hero="hero"')
        ->and(hjalteTagg($skal, 'ContainerHero'))->toContain(':can="can"')
        ->and($skal)->toContain('<slot name="hero-stats" />');

    // Och hjälten ritar sloten bara i den höga formen: den låga bär inga tal.
    $hjalte = hjalteHjalten();

    expect($hjalte)->toContain('<slot name="stats" />');

    expect($hjalte)->toMatch(
        '#<div\b[^>]*v-if="hero === \'large\'"[^>]*>\s*<slot name="stats" />#s',
        'talen ritas även i den låga hjälten — den ska bära inga',
    );
});

/*
 * Klart när: itemlistan och historiken ritar den låga hjälten utan tal.
 *
 * `compact` på varje flik utom översikten (ADR-0050 § 2). Ingen av dem fyller
 * `hero-stats`, och sloten är dessutom stängd i den låga formen — två spärrar
 * för samma sak, för en sida som fyllde sloten av misstag hade annars fått
 * tal ovanpå bilden.
 */
it('ritar den låga hjälten utan tal i itemlistan och historiken', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();

    foreach ([
        'pages/Containers/Items/Index.vue' => "/containers/{$container->ulid}/items",
        'pages/Containers/History.vue' => "/containers/{$container->ulid}/history",
    ] as $sokvag => $adress) {
        actingAs($ägare)->get($adress)->assertOk();

        $vy = hjalteKod($sokvag);

        expect(hjalteTagg($vy, 'ContainerLayout'))->toContain('hero="compact"');

        expect($vy)->not->toContain('hero-stats');
    }
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

    expect(hjalteTagg($vy, 'ContainerLayout'))->not->toBe('', 'itemvyn ritar ingen ContainerLayout');
    expect(hjalteTagg($vy, 'ContainerLayout'))->not->toContain('hero');

    $skal = hjalteKod('layouts/ContainerLayout.vue');

    expect($skal)->toContain('hero: { type: String, default: null }');

    // Namnraden står kvar som `v-else`: en sida utan hjälte ser ut som förut.
    expect($skal)->toMatch(
        '#<p v-else class="hidden[^"]*md:block">\{\{ heading \}\}</p>#s',
        'namnraden ritas inte när hjälten saknas',
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
 * Klart när: *Redigera container* leder till inställningssidan och ritas bara
 * för den som får ändra.
 *
 * Knappen är flikradens ersättning för raden *Inställningar* (ADR-0050 § 3),
 * och adressen är inställningssidans egen rutt — inte en sträng som råkar se
 * likadan ut. Flaggan är presentation: `ContainerPolicy::update` prövas på
 * nytt av rutten, och en läsare får `false` på varje flik som bär hjälten.
 */
it('låter Redigera container leda till inställningssidan, bara för den som får ändra', function () {
    withoutVite();

    [, $ägare, $container] = hjalteKontext();
    $läsare = hjalteLasare($container);

    $adress = route('containers.edit', $container, false);

    expect($adress)->toBe("/containers/{$container->ulid}/edit");

    $hjalte = hjalteHjalten();

    // Adressen byggs ur containerns ULID, och knappen ritas ur `can.update`.
    expect($hjalte)->toContain('`/containers/${container.ulid}/edit`')
        ->and($hjalte)->toContain('v-if="canUpdate"')
        ->and($hjalte)->toContain("t('container.hero.edit')");

    // Översikten: ägaren ser flaggan sann, läsaren falsk.
    actingAs($ägare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', true));

    actingAs($läsare)->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));

    // Och läsaren nekas skrivningen rutten förmedlar, som varje annan gång
    // flaggan är presentation.
    actingAs($läsare)->patch("/containers/{$container->ulid}", ['name' => 'Tjuvnamn'])
        ->assertForbidden();

    expect($container->fresh()->name)->toBe($container->name);

    // Meningen finns i katalogen — `t()` skriver nyckeln själv när uppslaget
    // misslyckas, och en knapp hade då hetat `container.hero.edit`.
    expect(Lang::get('ui.container.hero.edit', [], 'en'))->not->toBe('ui.container.hero.edit');
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
