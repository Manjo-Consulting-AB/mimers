<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 152 · Plusknappen skapar det vyn visar, se [[M23 Mobilen och kartan]]
 * § 152 och [[ADR-0048 Mobilen och plusknappen]] § 2.
 *
 * **Provet är två prov i ett, och det är issuen.** Det första är MÅLET: vad
 * knappen gör på var och en av de fem sidorna, prövat på servern mot samma
 * policyer som rutterna — målet bor i App\Support\Frontend\CreateTarget och
 * blir sidans `create`-propp. Det andra är FORMEN: att skalet ritar knappen ur
 * proppen, att en sida utan propp inte får någon knapp, att menyn är ett ark
 * med webbläsarens fokusfälla, och att ordet *sibling* inte finns i
 * gränssnittet.
 *
 * **Det som kräver en webbläsare prövas inte här.** Att arket glider upp
 * nedifrån, att fästet hamnar under knappen över `md:`, och att fokusfällan
 * håller i handen står i PR-kroppens handprov. Det som går att avgöra ur
 * källkoden är att fällan är webbläsarens — `<dialog>` med `showModal()` — och
 * inte en egen tabbhanterare, samma form som MobilskalTest och GenomgangTest.
 *
 * Kommentarer rensas bort före varje källkodsprov, som i grannfilerna: filerna
 * är fulla av svensk prosa, och en regel som letar efter `showModal()` ska
 * inte kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `plusknapp` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function plusknappKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En containerbred grant till $user, på angiven nivå.
 *
 * `item_id` är null med flit: det är den containerbreda raden
 * `ContainerPolicy::createItem()` kräver, och den som gör att en mottagare når
 * containerns sidor utan att vara medlem.
 */
function plusknappGrant(Container $container, User $user, string $niva): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $niva,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function plusknappItem(Container $container): Item
{
    return Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som MobilskalTest rensar.
 */
function plusknappKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Målet. Klart när-punkterna 1, 2, 3, 4, 5 och 6.
 * ---------------------------------------------------------------------------
 */

it('leder till formuläret för en ny container på dashboarden och i containerlistan', function () {
    withoutVite();

    [, $anvandare] = plusknappKontext();

    foreach (['/dashboard', '/containers'] as $url) {
        actingAs($anvandare)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->where('create.kind', 'container')
                ->where('create.href', '/containers/create'),
        );
    }
});

it('leder till formuläret för ett nytt item i containern', function () {
    withoutVite();

    [, $anvandare, $container] = plusknappKontext();

    // Översikten och itemlistan — de två containervyer som finns i den här
    // issuen. Trädet och kartan (154, 156 och 157) skickar samma mål, med
    // `?parent` när man står på en nod; `forContainer()` tar emot den.
    foreach (["/containers/{$container->ulid}", "/containers/{$container->ulid}/items"] as $url) {
        actingAs($anvandare)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->where('create.kind', 'item')
                ->where('create.href', "/containers/{$container->ulid}/items/create"),
        );
    }
});

/*
 * Menyn på ett item. Fem rader sedan issue 168, då *Kostnad* fick sin yta —
 * itemets kostnadsflik — och menyns sista döda länk försvann. Se
 * App\Support\Frontend\CreateTarget.
 */
it('öppnar menyn med de rader användaren får använda på ett item', function () {
    withoutVite();

    [, $anvandare, $container] = plusknappKontext();
    $item = plusknappItem($container);

    $bas = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->get($bas)->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'menu')
            ->where('create.rows', [
                ['key' => 'item', 'href' => "/containers/{$container->ulid}/items/create?parent={$item->ulid}"],
                ['key' => 'relation', 'href' => "{$bas}?tab=relations"],
                ['key' => 'attachment', 'href' => "{$bas}?tab=attachments"],
                ['key' => 'schedule', 'href' => "{$bas}/schedules/create"],
                // Den femte raden ur [[ADR-0048 Mobilen och plusknappen]] § 2:
                // *Kostnad*, som leder till itemets kostnadsflik. Raden
                // byggdes inte förrän ytan fanns (issue 168) — en menyrad till
                // en webbyta som inte finns är en död länk.
                ['key' => 'cost', 'href' => "{$bas}?tab=costs"],
            ]),
    );
});

/*
 * Klart när: "plusknappens meny har raden Kostnad som leder till fliken".
 *
 * Raden är den femte ur [[ADR-0048 Mobilen och plusknappen]] § 2 och kom med
 * issue 168, när ytan den leder till byggdes: före det hade den varit en död
 * länk, och en yta ingen hittar är samma sak som en yta som inte finns.
 * Adressen är itemets egen med `?tab=costs`, samma form *Relation* och *Bild
 * eller dokument* bär — menyn byter flik, den lämnar inte itemet.
 *
 * Grinden är itemets `create`, samma pinne som `ItemController::show()` ritar
 * raden ur och `CostEntryController::store()` prövar på nytt. En läsare får
 * därför ingen meny alls, och alltså ingen rad: `create`-proppen är null.
 */
it('har raden Kostnad som leder till fliken', function () {
    withoutVite();

    [, $anvandare, $container] = plusknappKontext();
    $item = plusknappItem($container);

    $bas = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->get($bas)->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.rows.4', [
            'key' => 'cost',
            'href' => "{$bas}?tab=costs",
        ]),
    );

    $lasare = User::factory()->create();
    plusknappGrant($container, $lasare, 'read');

    actingAs($lasare)->get($bas)->assertInertia(
        fn (AssertableInertia $page) => $page->where('create', null),
    );
});

/*
 * Den aktuella förekomsten följer med menyraden, precis som den följer med
 * flikarnas `href` (issue 95 och 102). Utan den tappade *Relation* och *Bild
 * eller dokument* vägen man stod på, och vyn hade bytt rad i brödsmulan utan
 * att någon bett om det.
 */
it('bär den aktuella förekomsten på menyraderna som pekar på en flik', function () {
    withoutVite();

    [, $anvandare, $container] = plusknappKontext();
    $item = plusknappItem($container);

    $bas = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->get("{$bas}?path={$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.rows.1.href', "{$bas}?path={$item->ulid}&tab=relations")
            ->where('create.rows.2.href', "{$bas}?path={$item->ulid}&tab=attachments"),
    );
});

/*
 * Klart när: "en användare med läsåtkomst ser ingen knapp i en container eller
 * ett item". En `read`-mottagare når sidorna — det är själva poängen med
 * `ContainerPolicy::view()` och `ItemPolicy::view()` — men får inte skapa
 * något, och då finns det inget mål att skicka.
 */
it('visar ingen knapp för en användare med läsåtkomst', function () {
    withoutVite();

    [, , $container] = plusknappKontext();
    $item = plusknappItem($container);

    $lasare = User::factory()->create();
    plusknappGrant($container, $lasare, 'read');

    foreach ([
        "/containers/{$container->ulid}",
        "/containers/{$container->ulid}/items",
        "/containers/{$container->ulid}/items/{$item->ulid}",
    ] as $url) {
        actingAs($lasare)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->where('create', null),
        );
    }

    // Och samma användare ser ingen knapp på dashboarden eller i
    // containerlistan: hon är inte medlem i något konto, och
    // `ContainerPolicy::create()` kräver medlemskap.
    foreach (['/dashboard', '/containers'] as $url) {
        actingAs($lasare)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->where('create', null),
        );
    }
});

/*
 * Klart när: "en rad vars policy nekar finns inte i menyn".
 *
 * Relationen är den enda raden med en annan grind: en `related`-länk ändrar
 * BÅDA itemen, och `ItemLinkController::store()` kräver `update` på dem medan
 * de fyra andra raderna kräver `create`. En mottagare på `create` ser därför
 * fyra rader och en på `write` fem — samma meny, olika innehåll, och det är
 * servern som avgör.
 */
it('utelämnar en rad vars policy nekar', function () {
    withoutVite();

    [, , $container] = plusknappKontext();
    $item = plusknappItem($container);

    $skapare = User::factory()->create();
    plusknappGrant($container, $skapare, 'create');

    actingAs($skapare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->has('create.rows', 4)
            ->where('create.rows.0.key', 'item')
            ->where('create.rows.1.key', 'attachment')
            ->where('create.rows.2.key', 'schedule')
            ->where('create.rows.3.key', 'cost'),
    );

    $skrivare = User::factory()->create();
    plusknappGrant($container, $skrivare, 'write');

    actingAs($skrivare)->get("/containers/{$container->ulid}/items/{$item->ulid}")->assertInertia(
        fn (AssertableInertia $page) => $page->has('create.rows', 5)
            ->where('create.rows.1.key', 'relation'),
    );
});

/*
 * Klart när: "knappen visas inte på sidor utan mål". `create` är en propp som
 * fem kontrollrar skickar, och skalet ritar knappen bara när den finns —
 * inställningarna, uppgiftslistan och sökningen har ingen.
 */
it('visar ingen knapp på en sida utan mål', function () {
    withoutVite();

    [, $anvandare] = plusknappKontext();

    foreach (['/tasks', '/settings/profile'] as $url) {
        actingAs($anvandare)->get($url)->assertInertia(
            fn (AssertableInertia $page) => $page->missing('create'),
        );
    }
});

/*
 * ---------------------------------------------------------------------------
 * Formen. Klart när-punkterna 7 och 8, och kopplingen mellan proppen och
 * skalet.
 * ---------------------------------------------------------------------------
 */

it('ritar knappen i skalet och i flikraden ur sidans mål', function () {
    $layout = plusknappKod('layouts/AppLayout.vue');
    $flikrad = plusknappKod('components/MobileTabBar.vue');
    $knapp = plusknappKod('components/CreateButton.vue');

    // Skalet ritar knappen bara när sidan har ett mål — det är hela
    // `Klart när`-punkten "knappen visas inte på sidor utan mål" — och samma
    // mål går till flikradens mitt.
    expect($layout)->toContain('import CreateButton from')
        ->toContain('<CreateButton v-if="create"')
        ->toContain(':create="create"')
        ->toContain('<MobileTabBar');

    expect($flikrad)->toContain('import CreateButton from')
        ->toContain('<CreateButton');

    // Knappen vet inte vad den gör: formen på målet avgör om den är en länk
    // eller en knapp som öppnar menyn.
    expect($knapp)->toContain("props.create.kind === 'menu'")
        ->toContain('<Link')
        ->toContain('<button')
        ->toContain("t('create.label')");

    // Containerns skal förmedlar inte målet längre (issue 275): sidan skickar
    // det direkt till `AppLayout`, som ligger först i kedjan, och
    // `ContainerLayout` ligger innanför och rör det inte. Fram till issue 275
    // tog den emot `create` och skickade den vidare; nu går målet förbi den.
    expect(plusknappKod('layouts/ContainerLayout.vue'))
        ->not->toContain('create: { type: Object, default: null }');

    // Och de fem sidorna skickar sitt mål vidare till sitt skal. Sedan issue
    // 273 deklarerar de två toppnivåsidorna sin layout med `defineOptions` och
    // bär målet som en layoutprop; sedan issue 275 deklarerar containerns sidor
    // sin kedja, och målet står i `AppLayout`-ledet i var och en. En sida som
    // glömmer raden får ingen knapp, och det är tyst — därför räknas de upp.
    //
    // `can` står inte här: containerns behörighetsflagga hör till
    // `ContainerLayout`-ledet, och pennan ritas av `ContainerLayout` i
    // toppraden och teleporteras in i skalet (issue 275).
    foreach ([
        'pages/Dashboard.vue' => 'defineOptions({ layout: (props) => [AppLayout, { create: props.create }] });',
        'pages/Containers/Index.vue' => 'defineOptions({ layout: (props) => [AppLayout, { create: props.create }] });',
        'pages/Containers/Overview.vue' => '[AppLayout, { create: props.create }]',
        'pages/Containers/Items/Index.vue' => '[AppLayout, { create: props.create }]',
        'pages/Containers/Items/Show.vue' => '[AppLayout, { create: props.create }]',
    ] as $sokvag => $rad) {
        expect(plusknappKod($sokvag))->toContain($rad);
    }
});

/*
 * Klart när: "arket har fokusfälla och stängs med Escape".
 *
 * Fällan är webbläsarens: `<dialog>` med `showModal()` håller fokus i arket
 * medan det är öppet och Esc fyrar `close` — samma konstruktion som
 * MobileMenu och bildvisaren, och av samma skäl (issue 68b § Beslut 2). En
 * egen tabbhanterare är den fälla som fastnar, och `aria-modal` är samma
 * påstående utan webbläsarens stöd.
 */
it('har fokusfälla och stängs med Escape', function () {
    $ark = plusknappKod('components/UiSheet.vue');
    $meny = plusknappKod('components/CreateMenu.vue');

    expect($ark)->toContain('<dialog')
        ->toContain('showModal()')
        ->toContain('@close="onClosed"')
        // Rubriken är en prop och ritas som arkets namn.
        ->toContain('{{ heading }}');

    expect($ark)->not->toContain('aria-modal');
    expect($ark)->not->toMatch('/@keydown\.(tab|esc)/');

    // Fokus lämnas tillbaka till elementet som öppnade arket — det är samma
    // element som bär fästet över `md:`.
    expect($ark)->toContain('props.trigger?.focus()');

    // Och fokus landar i panelen när arket öppnas. Utan `autofocus` tar
    // webbläsaren första tabbbara element, och det är tryckytan utanför
    // panelen — en stängknapp i full skärmstorlek som ingen ser.
    expect($ark)->toContain('autofocus');

    // Stängningen har tre vägar och alla går genom `close()`: knappen i
    // rubrikraden, Esc (webbläsarens `close`-händelse) och trycket utanför.
    expect(preg_match_all('/@click="close"/', $ark))->toBe(2, 'arket saknar stängknapp eller tryckyta utanför');

    // Menyn ritar raderna i arket och stänger sig när en rad väljs.
    expect($meny)->toContain("import UiSheet from './UiSheet.vue'")
        ->toContain('<UiSheet')
        ->toContain("t('create.heading')")
        ->toContain('v-for="row in rows"')
        ->toContain('@click="emit(\'close\')"');

    // Öppna-läget bor i skalet, som sidomenyns: knappen är en knapp och menyn
    // en yta, och den som äger båda är layouten.
    expect(plusknappKod('layouts/AppLayout.vue'))->toContain('import CreateMenu from')
        ->toContain('<CreateMenu')
        ->toContain(':trigger="createTrigger"');
});

/*
 * Klart när: "ordet *sibling* förekommer inte" ([[ADR-0048 Mobilen och
 * plusknappen]] § 4). Relationerna är tre — parent, child och related — och
 * items som delar förälder visas i trädet, inte som en egen grupp.
 *
 * Provet gäller den text en ANVÄNDARE möter: katalogen och det som står i
 * komponenterna. Ett identifierarnamn är inte gränssnittstext (AGENTS.md
 * § Språk i koden), och `siblings` i components/categoryTree.js är en lokal
 * variabel för kategoriträdets barn — ingen rad någon läser. Därför läses
 * `.js`-filerna på sina strängliteraler och `.vue`-filerna i sin helhet.
 */
it('har inte ordet sibling i gränssnittet', function () {
    foreach (File::allFiles(lang_path()) as $fil) {
        expect(str_contains(strtolower(File::get($fil)), 'sibling'))->toBeFalse(
            "ordet sibling står i {$fil->getRelativePathname()} — det är struket ur gränssnittet",
        );
    }

    $granskade = 0;

    foreach (File::allFiles(resource_path('js')) as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        $granskade++;

        $kod = plusknappKod($fil->getRelativePathname());

        if ($fil->getExtension() === 'js') {
            // Bara strängliteralerna: `'…'`, `"…"` och mallsträngar.
            preg_match_all('/\'[^\']*\'|"[^"]*"|`[^`]*`/s', $kod, $träffar);

            $kod = implode("\n", $träffar[0]);
        }

        expect(str_contains(strtolower($kod), 'sibling'))->toBeFalse(
            "ordet sibling står i {$fil->getRelativePathname()} — det är struket ur gränssnittet",
        );
    }

    expect($granskade)->toBeGreaterThan(50, 'provningen läste nästan inga filer');
});

/*
 * Menyradernas etiketter slås upp med en BYGGD nyckel — `` t(`create.rows.
 * ${row.key}`) `` — och fångas därför inte av SprakTest, som letar literala
 * uppslag. Nycklarna läses ur formen raderna har i stället för att skrivas av
 * här: en rad som byter namn ska följa med, och en ny rad ska mötas av provet.
 */
it('har en mening till varje menyrad och till knappen', function () {
    foreach (['create.label', 'create.heading', 'create.close'] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    foreach (['item', 'relation', 'attachment', 'schedule', 'cost'] as $rad) {
        expect(Lang::get("ui.create.rows.{$rad}", [], 'en'))->not->toBe(
            "ui.create.rows.{$rad}",
            "ui.create.rows.{$rad} saknas",
        );
    }
});
