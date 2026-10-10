<?php

use Illuminate\Support\Facades\File;

/*
 * Det bestående skalet — M31 Flytande navigering · issue 273 (#832), se
 * resources/js/layouts/AppLayout.vue och de tjugo sidorna under
 * resources/js/pages/.
 *
 * **Sidan deklarerar layouten, mallen bär den inte** (Beslut 1): var och en av
 * de tjugo sidorna får `defineOptions({ layout: AppLayout })` i
 * `<script setup>`, och `<AppLayout>`-taggarna försvinner ur mallen. Inertia
 * behåller då skalets instans mellan två sidor i stället för att riva och
 * bygga upp den vid varje visit.
 *
 * **Källkodsprov och inte sidprov.** Skalet ritas i klienten — Inertia
 * renderar mallen i webbläsaren, och en svarskropp i en testsvit bär bara
 * rotvyn. Det som går att pröva på serversidan är formen på källkoden: vilken
 * rad, vilken prop, vilken vakt. Samma form som GlobalHistorikvyTest och
 * MobilskalTest.
 *
 * **Plusknappens mål är undantaget** (Beslut 2): de två sidor som skickar
 * `create` bär målet som en layoutprop i en pilfunktion med exakt en
 * parameter. Formen är inte kosmetisk — Inertia 3.7 skiljer en propfunktion
 * från en renderfunktion på antalet parametrar och på att pilfunktioner saknar
 * `prototype` (`isRenderFunction` i @inertiajs/vue3), så en `function (props)`
 * eller en funktion med två parametrar tolkas fel.
 *
 * **Menyerna stängs vid navigering** (Beslut 3): när skalet står kvar står
 * också dess tillstånd kvar, och `watch(() => page.url)` nollställer de två
 * menyerna. Utan den står mobilmenyn öppen över nästa sida efter ett klick i
 * den.
 *
 * Kommentarerna rensas bort före varje källkodsprov, som i grannfilerna:
 * filerna är fulla av svensk prosa, och en regel som letar efter en tagg ska
 * inte kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `bestaendeSkal` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * De tjugo sidorna i rutan, relativa `resources/js/pages/`.
 *
 * @return list<string>
 */
function bestaendeSkalSidor(): array
{
    return [
        'Auth/MagicLink.vue',
        'Auth/MagicLinkCode.vue',
        'Auth/Register.vue',
        'Auth/VerifyEmail.vue',
        'Containers/Create.vue',
        'Containers/Index.vue',
        'Dashboard.vue',
        'Documents/Index.vue',
        'Error.vue',
        'History/Index.vue',
        'Inbox/Index.vue',
        'Info.vue',
        'Invitations/Show.vue',
        'Search.vue',
        'Settings/ConfirmUserDeletion.vue',
        'Settings/UserDeletionLinkInvalid.vue',
        'Tasks/Create.vue',
        'Tasks/Index.vue',
        'Trash/Containers.vue',
        'Welcome.vue',
    ];
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GlobalHistorikvyTest
 * rensar: blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function bestaendeSkalKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Ett led ur sidans kedja, som det står i källkoden: `[Komponent, { … }]`.
 *
 * Läses med regex och inte med en lös strängnål: det som ska fästas är att
 * proppen står i RÄTT led. `[ContainerLayout, …]` matchar inte
 * `[ContainerSettingsLayout, …]` — hakparentesen före namnet är en del av
 * mönstret — och den första träffen är den första förekomsten, alltså ledet
 * i den ordning sidan skriver sin kedja.
 */
function bestaendeSkalLed(string $kod, string $komponent): string
{
    preg_match('#\['.$komponent.', \{[^}]*\}\]#', $kod, $träff);

    return $träff[0] ?? '';
}

/**
 * De åtta inställningssidorna i rutan, relativa `resources/js/pages/`.
 *
 * Kedjan `[AppLayout, SettingsLayout]` gäller dem och ingen av de tjugo andra:
 * en sida utan sektionsmeny deklarerar bara `AppLayout` (issue 274).
 *
 * @return list<string>
 */
function bestaendeSkalInstallningssidor(): array
{
    return [
        'Settings/Accounts.vue',
        'Settings/Notifications.vue',
        'Settings/Plan.vue',
        'Settings/Profile.vue',
        'Settings/Security.vue',
        'Settings/Storage.vue',
        'Settings/Webhooks.vue',
        'Transfers/Index.vue',
    ];
}

/*
 * Klart när: `de tjugo sidorna deklarerar AppLayout` — var och en av sidorna i
 * rutan innehåller `defineOptions(`, `layout:` och `AppLayout` (Beslut 1).
 *
 * Listan räknas och inte bara läsas: en sida som tappas ur rutan ska falla
 * här, och antalet är issuens eget — tjugo.
 */
it('de tjugo sidorna deklarerar AppLayout', function () {
    $sidor = bestaendeSkalSidor();

    expect($sidor)->toHaveCount(20);

    foreach ($sidor as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))
            ->toContain('defineOptions(')
            ->toContain('layout:')
            ->toContain('AppLayout');
    }
});

/*
 * Klart när: `ingen av de tjugo sidorna bär AppLayout i mallen` (Beslut 1).
 *
 * Regeln läser den KOMMENTARRENSADE koden: en sida får nämna `AppLayout` i
 * importen och i `defineOptions`, men taggen `<AppLayout>` ska vara borta ur
 * mallen. En mall med flera rotnoder är giltig i Vue 3, och `<Head>` står
 * kvar som första nod.
 */
it('ingen av de tjugo sidorna bär AppLayout i mallen', function () {
    foreach (bestaendeSkalSidor() as $sida) {
        $kod = bestaendeSkalKod("js/pages/{$sida}");

        expect($kod)->not->toContain('<AppLayout');
        expect($kod)->not->toContain('</AppLayout>');
    }
});

/*
 * Klart när: `plusknappens mål följer med som layoutprop` — `Dashboard.vue` och
 * `Containers/Index.vue` bär målet i en pilfunktion med exakt en parameter
 * (Beslut 2).
 *
 * De arton andra skickar ingen `create` i dag och gör det inte heller efteråt:
 * de deklarerar layouten utan proppar. Skillnaden är hela poängen — en sida
 * vars knapp ska ha ett mål bär det, och en sida utan mål får ingen knapp
 * (PlusknappTest prövar serverns sida av samma kontrakt).
 */
it('plusknappens mål följer med som layoutprop', function () {
    $medMal = ['Dashboard.vue', 'Containers/Index.vue'];
    $rad = 'defineOptions({ layout: (props) => [AppLayout, { create: props.create }] });';

    foreach ($medMal as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))->toContain($rad);
    }

    foreach (array_diff(bestaendeSkalSidor(), $medMal) as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))->toContain('defineOptions({ layout: AppLayout });');
    }
});

/*
 * Klart när: `menyerna stängs vid navigering` — `AppLayout.vue` vaktar
 * `page.url` och nollställer `menuOpen` och `createMenuOpen` (Beslut 3).
 *
 * Provet fäster både vakten och dess innehåll: en `watch` som inte rör de två
 * menyerna är samma bugg som ingen vakt alls, och mobilmenyn står då öppen
 * över nästa sida. `watch` ska komma ur `vue` tillsammans med de tre andra
 * reaktiva hjälparna.
 */
it('menyerna stängs vid navigering', function () {
    $layout = bestaendeSkalKod('js/layouts/AppLayout.vue');

    preg_match("/import \{([^}]*)\} from 'vue'/", $layout, $vue);

    expect($vue)->not->toBeEmpty('importen från vue saknas');
    expect($vue[1])->toContain('watch');

    expect($layout)->toContain('watch(() => page.url');

    // Kroppen: båda menyerna nollställs, inte bara den ena.
    preg_match('#watch\(\(\) => page\.url, \(\) => \{(.*?)\}\)#s', $layout, $vakt);

    expect($vakt)->not->toBeEmpty('vakten kring page.url saknas');

    expect($vakt[1])->toContain('menuOpen.value = false')
        ->toContain('createMenuOpen.value = false');
});

/*
 * Inställningarnas sidor — M31 Flytande navigering · issue 274 (#833), se
 * resources/js/layouts/SettingsLayout.vue.
 *
 * **Kedjan deklareras av sidan** (Beslut 2): var och en av de åtta sidorna får
 * `defineOptions({ layout: [AppLayout, SettingsLayout] })`, och
 * `<SettingsLayout>`-taggarna försvinner ur mallen. Sidan är då rotvyn och
 * inställningarnas sidonavigering står kvar när användaren går mellan två
 * inställningssidor — och mellan en inställningssida och en sida från issue
 * 273.
 *
 * **SettingsLayout ritar bara sin egen del** (Beslut 1): `<AppLayout>` och
 * importen av den är borta. Skalet kommer in utifrån, ur kedjan.
 */

/*
 * Klart när: `inställningarnas sidor deklarerar kedjan` — var och en av de
 * åtta sidorna innehåller `layout: [AppLayout, SettingsLayout]` (Beslut 2).
 *
 * Listan räknas och inte bara läsas: en sida som tappas ur rutan ska falla
 * här, och antalet är issuens eget — åtta.
 */
it('inställningarnas sidor deklarerar kedjan', function () {
    $sidor = bestaendeSkalInstallningssidor();

    expect($sidor)->toHaveCount(8);

    foreach ($sidor as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))
            ->toContain('defineOptions(')
            ->toContain('layout: [AppLayout, SettingsLayout]');
    }
});

/*
 * Klart när: `ingen inställningssida bär SettingsLayout i mallen` (Beslut 2).
 *
 * Sidan får nämna `SettingsLayout` i importen och i `defineOptions`, men
 * taggen ska vara borta ur mallen — samma regel som för `<AppLayout>` i issue
 * 273.
 */
it('ingen inställningssida bär SettingsLayout i mallen', function () {
    foreach (bestaendeSkalInstallningssidor() as $sida) {
        $kod = bestaendeSkalKod("js/pages/{$sida}");

        expect($kod)->not->toContain('<SettingsLayout');
        expect($kod)->not->toContain('</SettingsLayout>');
    }
});

/*
 * Klart när: `SettingsLayout ritar inte AppLayout` — varken taggen eller
 * importen finns kvar (Beslut 1).
 */
it('SettingsLayout ritar inte AppLayout', function () {
    $layout = bestaendeSkalKod('js/layouts/SettingsLayout.vue');

    expect($layout)->not->toContain('<AppLayout');
    expect($layout)->not->toContain('import AppLayout');
});

/*
 * Klart när: `sektionsmenyn stängs vid navigering` — `SettingsLayout.vue`
 * vaktar `page.url` och nollställer `sectionsOpen` (Beslut 3).
 *
 * Provet fäster både vakten och dess innehåll, som för AppLayouts två menyer:
 * en `watch` som inte rör menyn är samma bugg som ingen vakt alls, och den
 * hopfällda sektionsmenyn står då öppen på mobilen över nästa sida.
 */
it('sektionsmenyn stängs vid navigering', function () {
    $layout = bestaendeSkalKod('js/layouts/SettingsLayout.vue');

    preg_match("/import \{([^}]*)\} from 'vue'/", $layout, $vue);

    expect($vue)->not->toBeEmpty('importen från vue saknas');
    expect($vue[1])->toContain('watch');

    expect($layout)->toContain('watch(() => page.url');

    preg_match('#watch\(\(\) => page\.url, \(\) => \{(.*?)\}\)#s', $layout, $vakt);

    expect($vakt)->not->toBeEmpty('vakten kring page.url saknas');
    expect($vakt[1])->toContain('sectionsOpen.value = false');
});

/*
 * Containerns sidor — M31 Flytande navigering · issue 275 (#834), se
 * resources/js/layouts/ContainerLayout.vue och
 * resources/js/layouts/ContainerSettingsLayout.vue.
 *
 * **Kedjan deklareras av sidan, och ingen layout ritar en annan** (Beslut 1, 2
 * och 4): `ContainerLayout` och `ContainerSettingsLayout` ritar bara sin egen
 * del, och var och en av de tjugo sidorna skriver sin kedja i `defineOptions`.
 * Mallen bär ingen layouttagg — samma form som issue 273 och 274, nu för
 * containerns sidor. Efter den här issuen bär ingen sida under
 * `resources/js/pages/` en layout i sin mall.
 *
 * **Proppen följer med till det led som läser den** (Beslut 3). Regeln är
 * mekanisk: en `:create="create"` på sidans gamla layouttagg blir
 * `create: props.create` i `AppLayout`-ledet, `hero`, `container` och `can`
 * stannar i `ContainerLayout`-ledet, och en prop sidan inte skickade förut
 * skickas inte efteråt. Följden är att `ContainerLayout` inte längre tar
 * `create`, och att `ContainerSettingsLayout` varken tar `create` eller `can`
 * — den läste dem aldrig, den vidarebefordrade dem bara.
 *
 * **Toppraden på mobilen flyttade till skalet.** Den ritas av `AppLayout` och
 * läses ur sidans `container`-prop; markupen i `ContainerLayout` kunde inte
 * fyllas när skalet ligger utanför i kedjan (en slot fylls nedåt). Det är
 * ändringen `MobilskalTest` och `ContainerbildvyTest` följer.
 *
 * **Pennans flagga går i kedjan och inte i `page.props.can`** (fynd 2 i
 * granskningen). `page.props.can` är sidans EGNA flaggor: på en item- eller
 * schemasida är `can.update` itemets behörighet, och skalet ritade då pennan
 * för en medlem som får ändra itemet men inte containern. De sidor som skickar
 * containerns `can` skickar den därför också till `AppLayout`, precis som de
 * skickar `create` ([[ADR-0056 Flytande navigering]] § 1) — och bara de.
 */

/**
 * De tolv sidorna under `ContainerLayout`, relativa `resources/js/pages/`.
 *
 * @return list<string>
 */
function bestaendeSkalContainerSidor(): array
{
    return [
        'Containers/Costs.vue',
        'Containers/Documents.vue',
        'Containers/History.vue',
        'Containers/Overview.vue',
        'Containers/Tasks.vue',
        'Containers/Items/Create.vue',
        'Containers/Items/Edit.vue',
        'Containers/Items/Index.vue',
        'Containers/Items/Show.vue',
        'Containers/Items/Schedules/Create.vue',
        'Containers/Items/Schedules/Edit.vue',
        'Containers/Items/Schedules/Show.vue',
    ];
}

/**
 * De åtta sidorna under `ContainerSettingsLayout`, relativa
 * `resources/js/pages/`. Kedjan har ett tredje led för dem.
 *
 * @return list<string>
 */
function bestaendeSkalContainerinstallningssidor(): array
{
    return [
        'Containers/CalendarFeed.vue',
        'Containers/Categories.vue',
        'Containers/Edit.vue',
        'Containers/Export.vue',
        'Containers/Sharing.vue',
        'Containers/Tags.vue',
        'Containers/Transfers.vue',
        'Containers/Trash.vue',
    ];
}

/*
 * Klart när: `ingen sida bär en layout i mallen` — ingen fil under
 * `resources/js/pages/` innehåller `<AppLayout`, `<SettingsLayout`,
 * `<ContainerLayout` eller `<ContainerSettingsLayout` (Beslut 4).
 *
 * Katalogen läses och inte en avskrift: en ny sida möts av kravet utan att
 * någon kommer ihåg provet, och räkningen fäller en genomsökning som tyst
 * läste nästan ingenting.
 */
it('ingen sida bär en layout i mallen', function () {
    $granskade = 0;

    foreach (File::allFiles(resource_path('js/pages')) as $fil) {
        if ($fil->getExtension() !== 'vue') {
            continue;
        }

        $granskade++;

        $kod = bestaendeSkalKod('js/pages/'.$fil->getRelativePathname());

        foreach (['<AppLayout', '<SettingsLayout', '<ContainerLayout', '<ContainerSettingsLayout'] as $tagg) {
            expect(str_contains($kod, $tagg))->toBeFalse(
                "{$fil->getRelativePathname()} bär {$tagg} i mallen",
            );
        }
    }

    expect($granskade)->toBeGreaterThan(20, 'provningen läste nästan inga sidor');
});

/*
 * Klart när: `ingen layout ritar en annan` — ingen fil under
 * `resources/js/layouts/` innehåller `<AppLayout`, `<ContainerLayout` eller
 * `<SettingsLayout` (Beslut 1 och 2).
 */
it('ingen layout ritar en annan', function () {
    $granskade = 0;

    foreach (File::allFiles(resource_path('js/layouts')) as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        $granskade++;

        $kod = bestaendeSkalKod('js/layouts/'.$fil->getRelativePathname());

        foreach (['<AppLayout', '<ContainerLayout', '<SettingsLayout'] as $tagg) {
            expect(str_contains($kod, $tagg))->toBeFalse(
                "{$fil->getRelativePathname()} ritar {$tagg}",
            );
        }
    }

    expect($granskade)->toBeGreaterThan(3, 'provningen läste nästan inga layouter');
});

/*
 * Klart när: `containerns sidor deklarerar kedjan` — var och en av de tolv
 * `ContainerLayout`-sidorna innehåller `[AppLayout` och `[ContainerLayout`,
 * och var och en av de åtta `ContainerSettingsLayout`-sidorna innehåller
 * dessutom `[ContainerSettingsLayout` (Beslut 3).
 *
 * Listorna räknas och inte bara läsas: en sida som tappas ur rutan ska falla
 * här, och antalen är issuens egna — tolv och åtta.
 */
it('containerns sidor deklarerar kedjan', function () {
    $sidor = bestaendeSkalContainerSidor();

    expect($sidor)->toHaveCount(12);

    foreach ($sidor as $sida) {
        $kod = bestaendeSkalKod("js/pages/{$sida}");

        expect($kod)->toContain('defineOptions(')
            ->toContain('[AppLayout')
            ->toContain('[ContainerLayout');

        // Importen och inte bara strängen (fynd 1 i granskningen): `[ContainerLayout, …]`
        // är ett fritt namn inuti en pilfunktion, och en sida som glömt
        // importen bygger utan att något klagar — sedan kastar den
        // `ReferenceError` första gången man byter flik.
        expect($kod)->toContain("import ContainerLayout from '");

        // `container` står i `ContainerLayout`-ledet: det är den enda prop
        // layouten kräver.
        expect(bestaendeSkalLed($kod, 'ContainerLayout'))->toContain('container: props.container');
    }

    $installningar = bestaendeSkalContainerinstallningssidor();

    expect($installningar)->toHaveCount(8);

    foreach ($installningar as $sida) {
        $kod = bestaendeSkalKod("js/pages/{$sida}");

        expect($kod)->toContain('defineOptions(')
            ->toContain('[AppLayout')
            ->toContain('[ContainerLayout')
            ->toContain('[ContainerSettingsLayout');

        // Båda layouterna i kedjan importeras, av samma skäl som ovan.
        expect($kod)->toContain("import ContainerLayout from '")
            ->toContain("import ContainerSettingsLayout from '");

        // `hero: 'compact'` är SIDANS svar sedan issue 275 (issue 678):
        // `ContainerSettingsLayout` satte attributet själv så länge den ritade
        // `ContainerLayout`. De åtta är EN flik, och en hjälte som fanns på en
        // av dem men försvann på nästa hade hoppat när man bytte rad.
        expect(bestaendeSkalLed($kod, 'ContainerLayout'))
            ->toContain("hero: 'compact'")
            ->toContain('container: props.container');

        // Och det sista ledet läser bara `container`: `create` och `can` gick
        // förbi det redan förut, och `container` står sist i kedjan.
        expect(bestaendeSkalLed($kod, 'ContainerSettingsLayout'))
            ->toBe('[ContainerSettingsLayout, { container: props.container }]');
    }
});

/*
 * Klart när: `plusknappen följer med där den fanns` — sidorna som skickade ett
 * mål före issuen bär `create: props.create` i `AppLayout`-ledet, och en sida
 * som inte skickade `create` gör det inte heller efteråt (Beslut 3).
 *
 * **Sex sidor och inte tre.** Issuens `Klart när` räknar upp tre; regeln i
 * Beslut 3 är mekanisk, och en genomsökning av de gamla layouttaggarna visar
 * att `Containers/Costs.vue`, `Containers/Documents.vue` och
 * `Containers/Tasks.vue` också bar `:create="create"`. De får målet av samma
 * skäl som de tre andra — en sida som glömmer raden får ingen knapp, och det
 * är tyst.
 *
 * Nålen läses ur LEDET och inte ur filen: sedan fynd 2 står `can: props.can`
 * bredvid `create` på fem av de sex, och en lös sträng hade inte sagt vilket
 * led proppen står i.
 */
it('plusknappen följer med där den fanns', function () {
    $medMal = [
        'Containers/Costs.vue',
        'Containers/Documents.vue',
        'Containers/Overview.vue',
        'Containers/Tasks.vue',
        'Containers/Items/Index.vue',
        'Containers/Items/Show.vue',
    ];

    foreach ($medMal as $sida) {
        expect(bestaendeSkalLed(bestaendeSkalKod("js/pages/{$sida}"), 'AppLayout'))
            ->toContain('create: props.create');
    }

    $utanMal = array_merge(
        array_values(array_diff(bestaendeSkalContainerSidor(), $medMal)),
        bestaendeSkalContainerinstallningssidor(),
    );

    foreach ($utanMal as $sida) {
        expect(bestaendeSkalKod("js/pages/{$sida}"))
            ->not->toContain('create: props.create');
    }
});

/*
 * Klart när (fynd 2): `pennan följer med till skalet där sidan skickar
 * containerns can`.
 *
 * Pennan på mobilens topprad ritas av `AppLayout` (issue 275), men flaggan
 * läses ur sidans kedja och inte ur `page.props.can`. Den senare bär sidans
 * EGNA flaggor: på en item- eller schemasida betyder `can.update` att
 * användaren får ändra ITEMET, och skalet ritade då en penna som servern
 * nekar — en medlem som får ändra itemet men inte containern såg den.
 * Flaggan följer samma regel som `create` ([[ADR-0056 Flytande navigering]]
 * § 1): den sida som har svaret skickar det till ledet som ritar.
 *
 * Listorna är de sju sidor som skickade containerns `can` till
 * `ContainerLayout` före issuen, och de sidor vars `can` betyder något annat.
 * De räknas upp och läses ur ledet, så att en sida som flyttar flaggan ur
 * `AppLayout`-ledet faller här.
 */
it('pennan följer med till skalet där sidan skickar containerns can', function () {
    // Skalet läser proppen och ritar ur den. `page.props.can` får inte finnas
    // kvar i filen: det var den läsningen som gav fel behörighet.
    $skal = bestaendeSkalKod('js/layouts/AppLayout.vue');

    expect($skal)->toContain('can: { type: Object, default: null }')
        ->toContain('props.can?.update === true');
    expect($skal)->not->toContain('page.props.can');

    $medCan = [
        'Containers/Costs.vue',
        'Containers/Documents.vue',
        'Containers/Edit.vue',
        'Containers/History.vue',
        'Containers/Overview.vue',
        'Containers/Tasks.vue',
        'Containers/Items/Index.vue',
    ];

    foreach ($medCan as $sida) {
        expect(bestaendeSkalLed(bestaendeSkalKod("js/pages/{$sida}"), 'AppLayout'))
            ->toContain('can: props.can');
    }

    // Itemvyn och schemasidorna bär itemets respektive schemats flaggor. De
    // skickade dem aldrig till `ContainerLayout`, och de ska inte skicka dem
    // till skalet heller.
    foreach ([
        'Containers/CalendarFeed.vue',
        'Containers/Categories.vue',
        'Containers/Export.vue',
        'Containers/Sharing.vue',
        'Containers/Tags.vue',
        'Containers/Transfers.vue',
        'Containers/Trash.vue',
        'Containers/Items/Create.vue',
        'Containers/Items/Edit.vue',
        'Containers/Items/Show.vue',
        'Containers/Items/Schedules/Create.vue',
        'Containers/Items/Schedules/Edit.vue',
        'Containers/Items/Schedules/Show.vue',
    ] as $sida) {
        expect(bestaendeSkalLed(bestaendeSkalKod("js/pages/{$sida}"), 'AppLayout'))
            ->not->toContain('can: props.can');
    }
});

/*
 * Klart när: `ContainerLayout tar inte create` och `ContainerSettingsLayout
 * tar varken create eller can` — propdeklarationerna saknar dem (Beslut 1, 2).
 *
 * `can` står kvar i `ContainerLayout`: hjälten ritar sin redigeringsknapp ur
 * den. Det är `create` som är borta, och `ContainerSettingsLayout` läser
 * varken den eller `can`.
 */
it('ContainerLayout tar inte create och ContainerSettingsLayout varken create eller can', function () {
    $containerLayout = bestaendeSkalKod('js/layouts/ContainerLayout.vue');

    expect($containerLayout)->not->toContain('create: { type: Object, default: null }');
    expect($containerLayout)->toContain('can: { type: Object, default: null }');

    $installningar = bestaendeSkalKod('js/layouts/ContainerSettingsLayout.vue');

    expect($installningar)->not->toContain('create: { type: Object, default: null }');
    expect($installningar)->not->toContain('can: { type: Object, default: null }');
    expect($installningar)->toContain('container: { type: Object, required: true }');
});

/*
 * Klart när: `sektionsmenyn stängs vid navigering` — `ContainerSettingsLayout`
 * vaktar `page.url` och nollställer `sectionsOpen` (Beslut 5).
 *
 * Samma vakt och samma skäl som i AppLayout och SettingsLayout: skalet står
 * kvar mellan två sidor, och utan raden står den hopfällda sektionsmenyn öppen
 * på mobilen efter ett klick i den.
 */
it('sektionsmenyn i containern stängs vid navigering', function () {
    $layout = bestaendeSkalKod('js/layouts/ContainerSettingsLayout.vue');

    preg_match("/import \{([^}]*)\} from 'vue'/", $layout, $vue);

    expect($vue)->not->toBeEmpty('importen från vue saknas');
    expect($vue[1])->toContain('watch');

    expect($layout)->toContain('watch(() => page.url');

    preg_match('#watch\(\(\) => page\.url, \(\) => \{(.*?)\}\)#s', $layout, $vakt);

    expect($vakt)->not->toBeEmpty('vakten kring page.url saknas');
    expect($vakt[1])->toContain('sectionsOpen.value = false');
});
