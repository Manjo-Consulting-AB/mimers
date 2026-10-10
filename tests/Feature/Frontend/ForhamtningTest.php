<?php

use Illuminate\Support\Facades\File;

/*
 * Förhämtning och cache i webbläsaren — M31 · Flytande navigering · issue 277
 * (#836), se resources/js/app.js, resources/js/components/UiTabs.vue,
 * resources/js/components/ShellSections.vue,
 * resources/js/components/ShellContainerList.vue,
 * resources/js/components/MobileTabBar.vue,
 * resources/js/layouts/ContainerLayout.vue och
 * resources/js/pages/Tasks/Index.vue.
 *
 * **Källkodsprov och inte sidprov.** Förhämtningen sker i klienten: Inertia
 * renderar mallen i webbläsaren och läser `defaults` ur `app.js`, och en
 * svarskropp i en testsvit bär bara rotvyn. Det som går att pröva på
 * serversidan är därför formen på källkoden — vilken inställning, vilken
 * lyssnare, vilket attribut på vilken länk — samma form som GenomgangTest och
 * GlobalHistorikvyTest. Att förhämtningen faktiskt känns i en webbläsare står
 * i PR-kroppen.
 *
 * **Beslut 4 är hela filens negativa regel.** Itemsidan skriver ett besök
 * ([[ADR-0049 Nyligen besökta]]), och en förhämtad sida som sedan används ur
 * cachet når aldrig servern — då skrivs inget besök alls. Issue 276 hindrar
 * förhämtningen från att skriva, men bara när den når fram; därför får ingen
 * länk till `/containers/{c}/items/{i}` bära `prefetch`.
 *
 * Kommentarer rensas bort före varje kontroll, precis som i GenomgangTest och
 * GlobalHistorikvyTest: filerna är fulla av svenska docblock som förklarar
 * varför, och en regel som letar efter `prefetch` ska inte kunna nöjas av en
 * mening i ett docblock.
 *
 * Hjälparna har prefixet `forhamtning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest och
 * GlobalHistorikvyTest rensar: blockkommentarer, HTML-kommentarer och
 * radkommentarer.
 */
function forhamtningUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/** En fil under resources/js, med kommentarer borta. */
function forhamtningKod(string $sokvag): string
{
    return forhamtningUtanKommentarer(File::get(resource_path($sokvag)));
}

/**
 * Alla js- och vue-filer under resources/js, med kommentarer borta — den
 * repo-vida kontrollen i Beslut 4 läser hela frontenden och inte bara de
 * filer rutan listar.
 *
 * @return array<string, string> relativ sökväg → källkod utan kommentarer
 */
function forhamtningAlla(): array
{
    $filer = [];

    foreach (File::allFiles(resource_path('js')) as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        $filer[$fil->getRelativePathname()] = forhamtningUtanKommentarer($fil->getContents());
    }

    return $filer;
}

/**
 * Varje öppningstag av en `<Link>` i källkoden, som rå markup — samma form som
 * flikradTaggar i FlikradTest och genomgangTaggar i GenomgangTest.
 *
 * Attributvärdena matchas med citattecken runt om, och det är inte en prydnad:
 * ett värde kan bära en pil eller ett `>`, och en regel som läste `[^>]*` hade
 * stannat där och trott att taggen slutade.
 *
 * @return array<int, string>
 */
function forhamtningLankar(string $kod): array
{
    preg_match_all('#<Link\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>#s', $kod, $träffar);

    return $träffar[0];
}

/**
 * De `<Link>`-taggar i källkoden som bär `prefetch`.
 *
 * @return array<int, string>
 */
function forhamtningMedPrefetch(string $kod): array
{
    return array_values(array_filter(
        forhamtningLankar($kod),
        static fn (string $lank): bool => str_contains($lank, 'prefetch'),
    ));
}

/*
 * Klart när: `en förhämtad sida sparas i 30 sekunder och visas i 5 minuter` —
 * `app.js` innehåller `cacheFor: ['30s', '5m']` (Beslut 1).
 *
 * Två tal på samma rad: den första är hur länge en förhämtad sida är FÄRSK och
 * används utan ny förfrågan, den andra hur länge den alls finns kvar och visas
 * direkt medan den hämtas om i bakgrunden (*stale-while-revalidate*). Ändras
 * den ena utan den andra slutar stale-fönstret fungera utan att något annat
 * märker det, och därför prövas paret.
 *
 * Att värdet står under `defaults.prefetch` och inte på en enskild länk är en
 * del av kontraktet: det är appens förval, och varje `<Link>` utan egen
 * `cacheFor` läser det.
 */
it('en förhämtad sida sparas i 30 sekunder och visas i 5 minuter', function () {
    $kod = forhamtningKod('js/app.js');

    expect($kod)->toContain("cacheFor: ['30s', '5m']");

    expect($kod)->toMatch(
        '#defaults:\s*\{\s*prefetch:\s*\{#s',
        'livslängden står inte som appens förval under defaults.prefetch',
    );
});

/*
 * Klart när: `en ändring kastar allt sparat` — `app.js` innehåller
 * `router.on('finish'`, `method !== 'get'` och `router.flushAll()` (Beslut 2).
 *
 * Ordningen mellan vakten och tömningen är själva poängen och inte en detalj:
 * en tömning som ligger före metodfrågan hade tömt cachet på varje GET, alltså
 * också den vanliga navigeringen — och då finns ingenting kvar att använda.
 *
 * `router` måste komma ur paketet; en tömning utan import är en krasch i
 * webbläsaren som inget källkodsprov annars ser.
 */
it('en ändring kastar allt sparat', function () {
    $kod = forhamtningKod('js/app.js');

    expect($kod)->toContain("router.on('finish'")
        ->toContain("method !== 'get'")
        ->toContain('router.flushAll()');

    expect($kod)->toMatch(
        "#import \{[^}]*\brouter\b[^}]*\} from '@inertiajs/vue3'#",
        'router importeras inte ur @inertiajs/vue3',
    );

    expect(strpos($kod, "method !== 'get'"))->toBeLessThan(
        strpos($kod, 'router.flushAll()'),
        'tömningen står före metodfrågan — då töms cachet på varje GET',
    );
});

/*
 * Klart när: `sidopanelen, containerlistan och mobilens flikrad förhämtar` —
 * `ShellSections.vue`, `ShellContainerList.vue` och `MobileTabBar.vue`
 * innehåller `prefetch` (Beslut 3).
 *
 * Provet fångar taggen och läser den, i stället för att leta i filen i stort:
 * ett `prefetch` i en kommentar eller i en klass hade nöjt en enklare regel
 * utan att någon länk förhämtade. Sidopanelen prövas dessutom till antalet —
 * raden till en sektion är den ENDA som förhämtas, och utloggningen och
 * inloggningen står orörda (Beslut 5).
 */
it('sidopanelen, containerlistan och mobilens flikrad förhämtar', function () {
    foreach ([
        'js/components/ShellSections.vue',
        'js/components/ShellContainerList.vue',
        'js/components/MobileTabBar.vue',
    ] as $sokvag) {
        $länkar = forhamtningMedPrefetch(forhamtningKod($sokvag));

        expect(count($länkar))->toBeGreaterThan(0, "{$sokvag} förhämtar ingen länk");
    }

    $sektion = forhamtningMedPrefetch(forhamtningKod('js/components/ShellSections.vue'));

    expect($sektion)->toHaveCount(1, 'sidopanelen förhämtar fler länkar än sektionsraden');

    expect($sektion[0] ?? '')->toMatch('#:href="section\.href"#', 'sektionsraden förhämtar inte');
});

/*
 * Klart när: `flikraden förhämtar vid hovring och på begäran när sidan
 * laddats` — `UiTabs.vue` innehåller `prefetchOnMount` och
 * `['mount', 'hover']` (Beslut 3).
 *
 * Tre saker hålls isär, och alla behövs. Proppen finns och är av som förval —
 * det är förvalet som gör att en anropare måste SÄGA att monteringsförhämtning
 * är värd förfrågningarna (Beslut 4:s itemsida gör det aldrig). Bindningen ger
 * båda lägena: `mount` och `hover` när proppen är satt, och bara `hover` när
 * den inte är det, så en flikrad utan proppen förhämtar vid hovring som förut.
 */
it('flikraden förhämtar vid hovring och på begäran när sidan laddats', function () {
    $kod = forhamtningKod('js/components/UiTabs.vue');

    expect($kod)->toContain('prefetchOnMount')
        ->toContain('prefetchOnMount: { type: Boolean, default: false }')
        ->toContain("['mount', 'hover']")
        ->toContain(": 'hover'");

    $länkar = forhamtningMedPrefetch($kod);

    expect($länkar)->toHaveCount(1, 'flikradens länk bär ingen förhämtning');

    expect($länkar[0] ?? '')->toMatch('#:prefetch="#', 'förhämtningen är inte bunden till proppen');
});

/*
 * Klart när: `Tasks och containerns flikrad förhämtar när sidan laddats` —
 * `Tasks/Index.vue` och `ContainerLayout.vue` innehåller `prefetch-on-mount`
 * (Beslut 3).
 *
 * Regeln läser `<UiTabs>`-taggen och inte filen: proppen ska sitta på
 * flikraden, och en naken förekomst någon annanstans i filen hade inte gjort
 * någon flik förhämtad.
 */
it('Tasks och containerns flikrad förhämtar när sidan laddats', function () {
    foreach (['js/pages/Tasks/Index.vue', 'js/layouts/ContainerLayout.vue'] as $sokvag) {
        expect(forhamtningKod($sokvag))->toMatch(
            '#<UiTabs\b[^>]*\bprefetch-on-mount#',
            "{$sokvag} förhämtar inte flikraden när sidan laddats",
        );
    }
});

/*
 * Klart när: `itemsidans flikrad förhämtar inte när sidan laddats` —
 * `Items/Show.vue` och `Containers/Tasks.vue` innehåller inte
 * `prefetch-on-mount` (Beslut 3:s undantag).
 *
 * Itemsidans flikar går alla till `ItemController::show()`, webbens tyngsta
 * sida, och sju förhämtningar per itemvisning är för mycket för delad hosting.
 * Det är samma yta som Beslut 4 värnar, och undantaget står här i stället för i
 * en kommentar.
 */
it('itemsidans flikrad förhämtar inte när sidan laddats', function () {
    foreach (['js/pages/Containers/Items/Show.vue', 'js/pages/Containers/Tasks.vue'] as $sokvag) {
        expect(forhamtningKod($sokvag))->not->toMatch(
            '#\bprefetch-on-mount\b#',
            "{$sokvag} förhämtar flikraden när sidan laddats",
        );
    }
});

/*
 * Klart när: `ingen länk till itemsidan förhämtas` — `TodoRow.vue` och
 * `RecentVisitList.vue` innehåller inte `prefetch` (Beslut 4).
 *
 * De två första kontrollerna är de ytor issuen pekar ut. Den tredje är regeln
 * bakom dem, läst över hela frontenden: en `<Link>` vars adress pekar på ett
 * item får aldrig bära `prefetch`, hur den än byggs. Utan den hade nästa vy
 * som lägger till en itemlänk kunnat förhämtning-sätta den utan att något prov
 * sade ifrån.
 */
it('ingen länk till itemsidan förhämtas', function () {
    foreach (['js/components/TodoRow.vue', 'js/components/RecentVisitList.vue'] as $sokvag) {
        expect(forhamtningKod($sokvag))->not->toMatch(
            '#\bprefetch\b#',
            "{$sokvag} förhämtar en länk",
        );
    }

    foreach (forhamtningAlla() as $sokvag => $kod) {
        foreach (forhamtningMedPrefetch($kod) as $lank) {
            expect($lank)->not->toMatch(
                '#/items/#',
                "{$sokvag} förhämtar en länk till itemsidan",
            );
        }
    }
});

/*
 * Klart när: `utloggningen förhämtas inte` — i `ShellSections.vue` står inte
 * `prefetch` på samma `<Link>` som `href="/logout"` (Beslut 5).
 *
 * Utloggningen är en `<Link method="post">`, och Inertia förhämtar bara GET.
 * Attributet hade alltså varit verkningslöst där — men provet står kvar för
 * att regeln är "utloggningen rörs inte", och en framtida ändring av metoden
 * ska inte tyst göra utloggningslänken förhämtad.
 */
it('utloggningen förhämtas inte', function () {
    $kod = forhamtningKod('js/components/ShellSections.vue');

    $utloggning = array_values(array_filter(
        forhamtningLankar($kod),
        static fn (string $lank): bool => str_contains($lank, 'href="/logout"'),
    ));

    expect($utloggning)->toHaveCount(1, 'utloggningslänken saknas i sidopanelen');

    expect($utloggning[0] ?? '')->not->toMatch('#\bprefetch\b#', 'utloggningen förhämtas');
});
