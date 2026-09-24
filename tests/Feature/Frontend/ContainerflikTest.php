<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 101 · Containerns flikrad och inställningssidan. Se
 * resources/js/layouts/containerSections.js,
 * resources/js/layouts/ContainerLayout.vue,
 * resources/js/pages/Containers/Edit.vue, resources/js/components/UiTabs.vue
 * och [[ADR-0042 Designsystemet]] § Beslut och § Konsekvenser.
 *
 * **Filen prövar en fördelning och ett krav.** Fördelningen — vilka av
 * containerns tio sidor som blir flikar och vilka som samlas på
 * inställningssidan — är designarbete. Kravet är det inte: **ingen rad får
 * försvinna**, och det är 62a:s motivering för papperskorgen och 67c:s för
 * exporten. Provet *"var och en av de tio sektionerna går att nå"* är därför
 * filens tyngdpunkt: det räknar upp nycklarna ur modulen klienten importerar
 * och begär varje adress som inloggad medlem.
 *
 * **Fliklistan läses i node, inte som råtext.** `containerSections.js` är en
 * ren modul — den importerar varken Vue eller Inertia — och samma grepp som
 * KategoriuppsattningTest använder gäller här: en assertion på en PHP-avskrift
 * av listan hade bevisat noll. Det som prövas är de exporterade listorna
 * själva, med adresser byggda ur containerns ULID.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att flikraden ser
 * ut som `container.jpeg`, att den aktiva fliken lyser när man står på den, och
 * att tangentbordet tar sig genom raden. Formen på `UiTabs` prövas i
 * tests/Feature/Frontend/FlikradTest.php, träffytan och brytpunkten i
 * tests/Feature/Frontend/GenomgangTest.php, och handprovet står i PR-kroppen.
 *
 * **Dokument, uppgifter och kostnader har ingen flik, och det är avsiktligt.**
 * Ingen rutt svarar på dem och ingen prop bär dem, så en flik för dem hade
 * varit en död länk — en yta ingen hittar är samma sak som en yta som inte
 * finns. De tre står i PR:ens `## Frågor och antaganden`; en flik som kräver en
 * ny kontrollermetod är ett fynd och ingen ändpunkt i smyg.
 *
 * Hjälparna har prefixet `containerflik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * Medlemmen och inte en delegat: de tio sektionerna är containerns egna sidor,
 * och den som ser dem är den som förvaltar containern.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function containerflikKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En delegerad läsare: en `container_access` på `read`, utan konto i
 * ägarkontot.
 *
 * Byggd här och inte med ContainervyerTests containerGrant(): den hjälparen
 * bor i en annan testfil, och den här filen bär sina egna med prefixet
 * `containerflik` av samma skäl som proven gör det — ett prov som lånar en
 * annan fils kontext måste läsa den filen för att förstå sitt eget.
 */
function containerflikLasare(Container $container): User
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
 * Kör ett uttryck mot resources/js/layouts/containerSections.js i node, med
 * modulens tre listor bundna vid namn.
 *
 * Sökvägen går genom pathToFileURL() i stället för att klistras in rått, samma
 * skäl som SprakTest::korTranslate(): en Windows-sökväg är ingen giltig
 * ESM-specificerare.
 */
function containerflikKor(string $uttryck): mixed
{
    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const { containerSections, containerTabs, containerSettingsSections } = await import(pathToFileURL('
            .json_encode(resource_path('js/layouts/containerSections.js'), JSON_UNESCAPED_SLASHES).').href);',
        "process.stdout.write(JSON.stringify({$uttryck}));",
    ]);

    $rader = [];
    $kod = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));

    return json_decode(implode("\n", $rader), true);
}

/**
 * Nycklarna i en av modulens listor, i listans ordning.
 *
 * @return list<string>
 */
function containerflikNycklar(string $lista): array
{
    return containerflikKor("{$lista}.map((post) => post.key)");
}

/**
 * Adresserna i en av modulens listor, byggda ur $ulid — samma anrop som
 * layouten och inställningssidan gör.
 *
 * @return array<string, string> nyckel → adress
 */
function containerflikLankar(string $lista, string $ulid): array
{
    return containerflikKor(
        "Object.fromEntries({$lista}.map((post) => [post.key, post.href('{$ulid}')]))",
    );
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer — docblocken är
 * svenska med flit (AGENTS.md § Språk i koden), och en regel som letar efter en
 * markup ska inte kunna nöjas av en mening i ett docblock.
 */
function containerflikKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Varje `<Link>`-tag i en fil, som rå markup — samma form som
 * genomgangTaggar i GenomgangTest.
 *
 * Attributvärdena matchas med citattecken runt om, så ett `>` inuti ett värde
 * inte avslutar taggen i förtid.
 *
 * @return list<string>
 */
function containerflikLanktaggar(string $kod): array
{
    preg_match_all(
        '/<Link\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
    );

    return $träffar[0];
}

/*
 * Klart när: containern har en flikrad byggd av UiTabs.
 *
 * Raden är komponenten från issue 100 och inte en egen rad markup: formen,
 * tangentbordet och den aktiva fliken bor där, och två flikrader glider isär
 * inom samma milstolpe. Den här filen prövar att layouten använder den, att
 * etiketten kommer ur `lang/`, och att varje flik svarar.
 */
it('containern har en flikrad byggd av UiTabs', function () {
    withoutVite();

    [, $anvandare, $container] = containerflikKontext();

    $layout = containerflikKod('layouts/ContainerLayout.vue');

    expect($layout)->not->toBe('', 'ContainerLayout.vue saknas');

    expect($layout)->toContain("import UiTabs from '../components/UiTabs.vue'")
        ->toContain('<UiTabs')
        ->toContain(':tabs="tabs"')
        ->toContain(':label="heading"');

    // Etiketten slås upp och skrivs aldrig i filen ([[ADR-0013 Språk och
    // i18n]]): nyckeln byggs ur flikens egen `key`, och `label` är tablistens
    // tillgängliga namn — UiTabs kräver det.
    expect($layout)->toContain('container.nav.${tab.key}');

    // Och sektionsmenyn är borta. Raden som renderade de nio länkarna i en
    // kolumn får inte stå kvar jämte flikraden: två navigeringar över samma
    // sidor är två sanningar om var man är.
    expect($layout)->not->toContain('v-for="section in containerSections"');

    // Flikarna, ur modulen klienten importerar: översikten först — det är
    // containerns egen sida — sedan den sektion man arbetar i och den sida som
    // bär resten.
    expect(containerflikNycklar('containerTabs'))->toBe(['overview', 'items', 'settings', 'history']);

    foreach (containerflikLankar('containerTabs', $container->ulid) as $nyckel => $adress) {
        // Etiketten kommer ur `lang/`, och `t()` hade skrivit nyckeln själv på
        // skärmen om den saknades.
        $mening = trans("ui.container.nav.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("ui.container.nav.{$nyckel}", "container.nav.{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');

        actingAs($anvandare)->get($adress)->assertOk();
    }
});

/*
 * Klart när: var och en av de tio sektionerna går att nå.
 *
 * Filens tyngdpunkt, och issue 101:s enda krav: **ingen rad får försvinna**.
 * Nycklarna räknas upp ur modulen — en tionde rad vore en ny sida någon byggt
 * utan att en issue bad om den, och en åttonde vore en yta ingen hittar — och
 * varje adress begärs som inloggad medlem.
 *
 * Den andra halvan är inställningssidan, som är den yta de sju sektionerna
 * flyttade TILL. Att adressen svarar 200 är inte samma sak som att den går att
 * hitta: raden ska ritas på sidan, med samma etikett och samma adress som
 * sektionsmenyn gav den.
 */
it('var och en av de tio sektionerna går att nå', function () {
    withoutVite();

    [, $anvandare, $container] = containerflikKontext();

    expect(containerflikNycklar('containerSections'))->toBe([
        'items',
        'categories',
        'tags',
        'sharing',
        'settings',
        'calendar',
        'export',
        'trash',
        'transfer',
        'history',
    ]);

    foreach (containerflikLankar('containerSections', $container->ulid) as $nyckel => $adress) {
        actingAs($anvandare)->get($adress)->assertOk();
    }

    // De sju som lämnade flikraden, ur samma modul: items, settings och sedan
    // issue 116 även history stannar i raden, resten samlas på
    // inställningssidan. Historiken stannar därför att bilden ritar den jämte
    // översikten och items — se containerSections.js.
    expect(containerflikNycklar('containerSettingsSections'))->toBe([
        'categories',
        'tags',
        'sharing',
        'calendar',
        'export',
        'trash',
        'transfer',
    ]);

    // Och inställningssidan ritar dem. Listan kommer ur modulen och renderas
    // med `v-for` — en ny sektion är en ny rad där och ingen ändring i vyn —
    // och varje rad är en länk byggd ur containerns ULID.
    $vy = containerflikKod('pages/Containers/Edit.vue');

    expect($vy)->toContain('import { containerSettingsSections }')
        ->toContain('v-for="section in containerSettingsSections"')
        ->toContain('section.href(container.ulid)')
        ->toContain('container.nav.${section.key}');

    $lankar = containerflikLanktaggar($vy);

    expect($lankar)->toHaveCount(1, 'inställningssidan ritar ingen egen länkrad');
    expect($lankar[0])->toContain('section.href(container.ulid)');
    expect($lankar[0])->toContain('min-h-11');
});

/*
 * Klart när: var och en av de tio sektionerna går att nå — också för den som
 * inte äger containern.
 *
 * Sedan issue 101 är inställningssidan enda vägen till de sju sektionerna, och
 * den grindas därför på `view` och inte på `update`:
 * App\Http\Controllers\ContainerController::edit() auktoriserar läsningen av
 * sidan, och `PATCH` prövar `update()` som förut. Låg hubben kvar bakom
 * `update` hade en delegerad `read`-mottagare — som DelningsvyTest visar finns —
 * tappat sex ytor hon når i dag, för kategorier, taggar, delning, kalendern,
 * exporten och papperskorgen är alla `view`-grindade.
 *
 * Överlåtelsen svarar INTE för henne: `viewTransfers` är ägarkontots (issue
 * 67b). Länken var en död länk också i den gamla sektionsmenyn, så det är
 * ingen regression — den står som känd rest i PR:ens `## Frågor och
 * antaganden`, och provet låser fast att den är just 403 och inte 500.
 */
it('låter en delegerad läsare nå sektionerna via inställningssidan', function () {
    withoutVite();

    [, , $container] = containerflikKontext();

    $lasare = containerflikLasare($container);

    actingAs($lasare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));

    $adresser = containerflikLankar('containerSettingsSections', $container->ulid);

    foreach (['categories', 'tags', 'sharing', 'calendar', 'export', 'trash'] as $nyckel) {
        actingAs($lasare)->get($adresser[$nyckel])->assertOk();
    }

    actingAs($lasare)->get($adresser['transfer'])->assertForbidden();
});

/*
 * Klart när: uppgifter och underhåll är en flik.
 *
 * [[ADR-0042 Designsystemet]] § Bildernas avvikelser: bilden ger dem var sin
 * flik och var sitt tal, och `schedule` skiljer dem bara åt via
 * `recurrence_type` — den skillnaden är ett filter i listan och ska inte bäras
 * av en vy. Provet faller om två skilda flikar eller två skilda rutter finns:
 * ingen underhållsflik, ingen underhållsrutt, och som mest en uppgiftsflik.
 *
 * **Ingen uppgiftsflik finns i dag, och det är inte samma sak som att provet är
 * tomt.** Ingen sida svarar på containerns uppgifter, så fliken hade varit en
 * död länk (fyndet står i PR:ens `## Frågor och antaganden`). Att raden är tom
 * är därför rätt i dag, och provet är vakten för i morgon: den dag ytan byggs
 * blir den EN flik och EN rutt, och aldrig två.
 *
 * Det sista provet är det som gäller redan nu: den plats där containern räknar
 * sina uppgifter — översiktens bricka — räknar dem som ETT tal och inte två.
 */
it('uppgifter och underhåll är en flik', function () {
    withoutVite();

    [, $anvandare, $container] = containerflikKontext();

    $flikar = containerflikNycklar('containerTabs');

    $underhallsflikar = array_values(array_filter(
        $flikar,
        fn (string $nyckel): bool => str_contains($nyckel, 'maintenance'),
    ));

    $uppgiftsflikar = array_values(array_filter(
        $flikar,
        fn (string $nyckel): bool => str_contains($nyckel, 'task'),
    ));

    expect($underhallsflikar)->toBe([], 'underhållet har fått en egen flik');
    expect(count($uppgiftsflikar))->toBeLessThanOrEqual(1, 'uppgifterna har fått mer än en flik');

    // Samma sak i ruttabellen: ingen rutt bär ett eget underhållsnamn, och
    // uppgifterna har som mest en rutt.
    $rutter = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($rutt): string => $rutt->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'containers/{container}'))
        ->values()
        ->all();

    $underhallsrutter = array_values(array_filter(
        $rutter,
        fn (string $uri): bool => (bool) preg_match('#/(maintenance|underhall)(/|$)#', $uri),
    ));

    $uppgiftsrutter = array_values(array_filter(
        $rutter,
        fn (string $uri): bool => (bool) preg_match('#/tasks?(/|$)#', $uri),
    ));

    expect($underhallsrutter)->toBe([], 'underhållet har fått en egen rutt');
    expect(count($uppgiftsrutter))->toBeLessThanOrEqual(1, 'två rutter skiljer uppgifterna från underhållet');

    // Och där containern räknar dem är de ETT tal. Översiktens brickor kommer
    // ur issue 89, och brickan för uppgifter och underhåll är en och samma
    // ([[ADR-0039 Containerns översikt]] § Beslut).
    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk();

    $uppgiftstal = array_values(array_filter(
        array_keys($svar->inertiaProps()['counts']),
        fn (string $nyckel): bool => (bool) preg_match('/task|todo|maintenance/', $nyckel),
    ));

    expect($uppgiftstal)->toHaveCount(1, 'översikten räknar uppgifterna i mer än ett tal');
});

/*
 * Klart när: historikfliken finns och läser genom läsregeln.
 *
 * **Provet hette *"ingen historikflik finns"* och vände i issue 116.** Det
 * skrevs i issue 101 medan historiken väntade på instrumenteringen
 * ([[ADR-0042 Designsystemet]] § Konsekvenser: `audit_log` instrumenteras i ett
 * eget arbete), och det förutsatte att fliken, rutten och etiketten saknades.
 * Förutsättningen faller här, och provet svarar nu på samma tre ställen i
 * omvänd riktning — plus det fjärde som är issuens ärende: sidan läser genom
 * App\Actions\Audit\ListAuditEvents och inte runt den.
 *
 * **Läsregeln prövas inte här.** Rad för rad görs det i LasregelTest (issue
 * 108) och genom fliken i HistorikflikTest; det här provet bevisar att fliken
 * pekar på den ytan och att grinden står kvar. De två frågorna är åtskilda med
 * flit: grinden svarar på om man når containern, läsregeln på vilka rader man
 * då får läsa, och en främling får 403 och inte en tom lista.
 */
it('historikfliken finns och läser genom läsregeln', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerflikKontext();

    // Fliken finns i raden och bär sin egen etikett ur katalogen — `t()` hade
    // skrivit nyckeln själv på skärmen om den saknades.
    expect(containerflikNycklar('containerTabs'))->toContain('history');

    $mening = trans('ui.container.nav.history', [], 'en');

    expect($mening)->not->toBe('ui.container.nav.history', 'container.nav.history saknas');
    expect(trim($mening))->not->toBe('');

    // Och rutten svarar. Raden är skriven i loggen och hämtad ur den — en sida
    // som svarade 200 med en tom lista hade sett likadan ut i ett prov som
    // bara mätte statuskoden.
    $rad = AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $konto->id,
        'user_id' => $ägare->id,
        'action' => AuditLog::ACTION_CONTAINER_CREATED,
    ]);

    actingAs($ägare)->get("/containers/{$container->ulid}/history")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/History')
            ->has('rows', 1)
            ->where('rows.0.ulid', $rad->ulid)
        );

    // Grinden är kvar: den som inte når containern får 403 och inte en lista
    // över sina egna rader (issue 108).
    actingAs(User::factory()->create())
        ->get("/containers/{$container->ulid}/history")
        ->assertForbidden();

    // Och ingen händelsepanel har smugit in som prop på översikten: historiken
    // är en egen sida och inte ett kort på någon annans.
    actingAs($ägare)->get("/containers/{$container->ulid}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('events')
            ->missing('history')
            ->missing('auditLog')
        );
});

/*
 * Varje flik pekar på en NAMNGIVEN rutt (issue 101 och 116).
 *
 * Flikarna renderar ytor som redan fanns när issue 101 skrevs: översikten
 * (issue 89), itemlistan (57a) och inställningssidan (54). Provet fäster varje
 * flik vid sin rutt så att en flik inte kan peka på en adress någon byggt för
 * hand: hade en flik behövt en ny kontrollermetod hade adressen inte funnits i
 * ruttabellen, och då är den ett fynd i PR:ens `## Frågor och antaganden`.
 *
 * **`history` är den flik som BRÖT mot det**, och det är ärligt bokfört:
 * historikfliken kom med issue 116, kontrollermetoden
 * (ContainerHistoryController::index()) är ny, och rutten `containers.history`
 * fanns inte förut. Provet räknar därför fyra rader i stället för tre — den
 * femte fliken hade varit den som smög in.
 */
it('pekar varje flik på en namngiven rutt', function () {
    [, , $container] = containerflikKontext();

    expect(containerflikLankar('containerTabs', $container->ulid))->toBe([
        'overview' => route('containers.show', $container, false),
        'items' => route('containers.items.index', $container, false),
        'settings' => route('containers.edit', $container, false),
        'history' => route('containers.history', $container, false),
    ]);
});
