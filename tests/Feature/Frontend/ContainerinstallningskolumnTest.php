<?php

use Illuminate\Support\Facades\File;

/*
 * Containerns inställningskolumn. Se
 * resources/js/layouts/ContainerSettingsLayout.vue,
 * resources/js/layouts/SettingsLayout.vue,
 * resources/js/layouts/containerSections.js och [[ADR-0042 Designsystemet]].
 *
 * **Före den här issuen fanns listan bara på inställningssidan**, som en rad
 * under formuläret (issue 101). De sex andra sektionerna nåddes därför bara
 * genom den sidan. Nu är listan en sidokolumn i samma form som undermenyn
 * under huvudmenyns "Settings" — `SettingsLayout` är förlagan — och den står
 * på ALLA ÅTTA sidor, så man byter mellan dem utan att gå via inställningarna.
 *
 * **Pest läser Vue-filerna som text**, samma grepp som ContainerflikTest,
 * eftersom det går att avgöra strukturen ur markupen men inte utseendet. Det
 * som kräver en webbläsare — att kolumnen ser ut som mockupen, att
 * hopfällningen fungerar i handen och att flikraden och plusknappen beter sig
 * oförändrat på de åtta sidorna — står i PR:ens `## Frågor och antaganden` och
 * prövas av handen, inte här.
 *
 * **Flikarna räknas upp och stängs av.** De tre flikarna (översikten,
 * historiken och item-sidorna) är containerns arbetsytor och hör inte till
 * inställningarna; en kolumn där hade varit sju länkar ovanför innehållet på
 * en sida som inte är en inställning. Provet *"lämnar flikarna utan
 * sidokolumn"* är vakten mot att layouten sprids av bara farten.
 *
 * **Inställningssidan flyttade IN i kolumnen i issue 170**, när den lämnade
 * flikraden ([[ADR-0050 Desktopdesignen]] § 3), och ligger först där. Listan är
 * därför åtta rader och inte sju, och provet *"når alla sektioner från
 * inställningskolumnen"* räknar dem mot `containerSections` så att ingen rad
 * tappas mellan ytorna.
 *
 * Hjälparna har prefixet `containerinstallningskolumn` — Pest lägger alla
 * testfiler i samma namnrymd när hela sviten körs.
 */

/**
 * De åtta sidorna som bär kolumnen.
 *
 * `Edit` först: den är inställningssidan och listans gamla hem.
 *
 * @return list<string>
 */
function containerinstallningskolumnSidor(): array
{
    return [
        'Edit',
        'Categories',
        'Tags',
        'Sharing',
        'CalendarFeed',
        'Export',
        'Trash',
        'Transfers',
    ];
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer — docblocken är
 * svenska med flit (AGENTS.md § Språk i koden), och en regel som letar efter
 * en markup ska inte kunna nöjas av en mening i ett docblock.
 */
function containerinstallningskolumnKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Varje `<Link>`-tag i en fil, som rå markup — samma form som
 * containerflikLanktaggar i ContainerflikTest.
 *
 * Attributvärdena matchas med citattecken runt om, så ett `>` inuti ett värde
 * inte avslutar taggen i förtid.
 *
 * @return list<string>
 */
function containerinstallningskolumnLanktaggar(string $kod): array
{
    preg_match_all(
        '/<Link\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
    );

    return $träffar[0];
}

/**
 * Kör ett uttryck mot resources/js/layouts/containerSections.js i node, med
 * modulens listor bundna vid namn — samma grepp och samma skäl som
 * ContainerflikTest::containerflikKor(): modulen importerar varken Vue eller
 * Inertia, och en PHP-avskrift av listorna hade bevisat noll.
 *
 * Sökvägen går genom pathToFileURL() i stället för att klistras in rått: en
 * Windows-sökväg är ingen giltig ESM-specificerare.
 */
function containerinstallningskolumnKor(string $uttryck): mixed
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
function containerinstallningskolumnNycklar(string $lista): array
{
    return containerinstallningskolumnKor("{$lista}.map((post) => post.key)");
}

/**
 * Adresserna i en av modulens listor, byggda ur en påhittad ULID.
 *
 * @return array<string, string>
 */
function containerinstallningskolumnLankar(string $lista): array
{
    return containerinstallningskolumnKor(
        "Object.fromEntries({$lista}.map((post) => [post.key, post.href('01JTESTULID0000000000000000')]))",
    );
}

/*
 * Klart när: layouten lägger sektionslistan i en sidokolumn bredvid
 * innehållet.
 *
 * Formen är SettingsLayouts: en kolumn med fast bredd över `md:`, och ytan
 * bredvid den tar resten — `min-w-0 flex-1`, för ett flexbarn utan `min-w-0`
 * vägrar krympa under sitt innehålls bredd och skjuter ut kolumnen.
 */
it('lägger sektionslistan i en sidokolumn bredvid innehållet', function () {
    $kod = containerinstallningskolumnKod('layouts/ContainerSettingsLayout.vue');

    expect($kod)->not->toBe('', 'ContainerSettingsLayout.vue saknas');

    expect($kod)->toContain('md:flex-row')
        ->toContain('md:w-48')
        ->toContain('md:shrink-0')
        ->toContain('min-w-0 flex-1')
        ->toContain('<slot />');

    // Och kolumnen står FÖRE innehållet i dokumentordningen — den är en
    // sidokolumn till vänster, inte en rad under sidan.
    $nav = strpos($kod, '<nav');

    expect($nav)->not->toBeFalse('layouten ritar ingen <nav>');
    expect($nav)->toBeLessThan(strpos($kod, '<slot />'), 'kolumnen står efter innehållet');
});

/*
 * Klart när: layouten ritar listan ur containerSettingsSections och skickar
 * propsen vidare.
 *
 * `container`, `create` och `can` deklareras och skickas oförändrade till
 * `ContainerLayout`, som är ramen och inte ändras: flikraden, rubriken, bilden
 * och plusknappen beter sig som förut. Listan kommer ur modulen och renderas
 * med `v-for` — en ny sektion är en ny rad i containerSections.js och ingen
 * ändring i layouten.
 */
it('ritar sidokolumnen ur containerSettingsSections och skickar propsen vidare', function () {
    $kod = containerinstallningskolumnKod('layouts/ContainerSettingsLayout.vue');

    expect($kod)->toContain('import { containerSettingsSections }')
        ->toContain('v-for="section in containerSettingsSections"')
        ->toContain('section.href(container.ulid)')
        ->toContain('container.nav.${section.key}');

    // Ramen och de tre propparna.
    expect($kod)->toContain('<ContainerLayout')
        ->toContain('</ContainerLayout>')
        ->toContain(':container="container"')
        ->toContain(':create="create"')
        ->toContain(':can="can"');

    // Deklarerade med samma namn och samma krav som ContainerLayout själv:
    // `container` är det enda som krävs, och en sida som inte skickar `create`
    // eller `can` får ingen plusknapp och ingen penna.
    expect($kod)->toContain('container: { type: Object, required: true }')
        ->toContain('create: { type: Object, default: null }')
        ->toContain('can: { type: Object, default: null }');
});

/*
 * Klart när: inställningskolumnen når alla sektioner, inställningarna först
 * (issue 170).
 *
 * **Inställningssidan lämnade flikraden** ([[ADR-0050 Desktopdesignen]] § 3)
 * och är tillbaka i den sedan issue 646, men kolumnen är fortfarande den
 * förteckning över containerns övriga ytor som hubben hänger under — och den
 * enda vägen dit under `md:`. Två saker måste hålla: hubben står FÖRST —
 * annars ligger vägen till de sex sektioner en läsare når bakom tre andra
 * rader — och ingen rad har tappats mellan ytorna. Flikarna och kolumnen
 * täcker tillsammans `containerSections`, med `settings` som enda post på
 * båda ytorna (issue 646: samma post är både kolumnens första rad och
 * flikradens sista).
 *
 * Listorna läses i node och inte som råtext, samma grepp som
 * ContainerflikTest använder: modulen är ren, och en assertion på en avskrift
 * hade bevisat noll.
 */
it('når alla sektioner från inställningskolumnen, inställningarna först', function () {
    $kolumn = containerinstallningskolumnNycklar('containerSettingsSections');
    $flikar = containerinstallningskolumnNycklar('containerTabs');
    $alla = containerinstallningskolumnNycklar('containerSections');

    expect($kolumn[0] ?? null)->toBe('settings', 'inställningssidan står inte först i kolumnen');

    // Översikten är en flik utan rad i `containerSections` — den är containerns
    // EGEN sida och ingen undersida (issue 89) — och räknas därför inte in i
    // jämförelsen. Kvar är sektionerna, och ingen av dem får ligga på båda
    // ytorna eller saknas på båda.
    $flikar = array_values(array_filter($flikar, fn (string $nyckel): bool => $nyckel !== 'overview'));

    // `settings` är UNDANTAGET (issue 646): posten står med flit både först i
    // kolumnen och sist i flikraden — hubben och fliken är samma yta och samma
    // adress. Dubblettkontrollen gäller därför de övriga, medan täckningen
    // räknar `settings` en gång, ur kolumnen.
    $dubbletter = array_values(array_filter($flikar, fn (string $nyckel): bool => $nyckel !== 'settings'));

    $täckta = [...$dubbletter, ...$kolumn];

    expect(array_unique($täckta))->toBe($täckta, 'en sektion ligger på båda ytorna');

    sort($täckta);
    sort($alla);

    expect($täckta)->toBe($alla, 'en sektion nås inte från någon av ytorna');

    // Och kolumnens första rad är inställningssidan, med sin egen adress ur
    // samma modul — samma nyckel som fliken bar innan den lämnade raden.
    expect(containerinstallningskolumnLankar('containerSettingsSections'))
        ->toHaveKey('settings');
});

/*
 * Klart när: alla åtta inställningssidor har samma sidokolumn.
 *
 * Provet är ett dataset över de åtta filerna och var och en möts av samma tre
 * krav: sidan importerar layouten, öppnar och stänger den, och ritar ingen
 * `ContainerLayout` själv. Den sista halvan är den som håller kolumnen på
 * plats — en sida som byter tillbaka till ramen tappar menyn utan att något
 * annat syns.
 */
it('ger alla åtta inställningssidor samma sidokolumn', function (string $sida) {
    $kod = containerinstallningskolumnKod("pages/Containers/{$sida}.vue");

    expect($kod)->not->toBe('', "pages/Containers/{$sida}.vue saknas");

    expect($kod)->toContain("import ContainerSettingsLayout from '../../layouts/ContainerSettingsLayout.vue'")
        ->toContain('<ContainerSettingsLayout')
        ->toContain('</ContainerSettingsLayout>');

    // Ingen förklaring till `not->toContain`: Pest läser ett andra argument som
    // en andra nål, inte som ett meddelande (samma fälla som ContainerflikTest
    // undviker).
    expect($kod)->not->toContain('<ContainerLayout');
})->with(containerinstallningskolumnSidor());

/*
 * Klart när: översikten, historiken och Items/** använder fortfarande
 * ContainerLayout och inte den nya layouten.
 *
 * De är containerns arbetsytor och inte inställningar; en kolumn med de sju
 * sektionerna ovanför itemlistan hade varit samma vägg som den gamla
 * sektionsmenyn. Listan över filer tas ur katalogen och inte ur en avskrift,
 * så en ny item-sida möts av kravet utan att någon kommer ihåg provet.
 */
it('lämnar flikarna utan sidokolumn', function () {
    $flikar = [
        'pages/Containers/Overview.vue',
        'pages/Containers/History.vue',
    ];

    foreach (File::allFiles(resource_path('js/pages/Containers/Items')) as $fil) {
        if ($fil->getExtension() === 'vue') {
            $flikar[] = 'pages/Containers/Items/'.$fil->getRelativePathname();
        }
    }

    expect(count($flikar))->toBeGreaterThan(3, 'item-sidorna hittades inte');

    foreach ($flikar as $sokvag) {
        $kod = containerinstallningskolumnKod($sokvag);

        expect($kod)->toContain('<ContainerLayout');

        expect($kod)->not->toContain('ContainerSettingsLayout');
    }
});

/*
 * Klart när: listan är hopfälld bakom en knapp med `aria-controls` och
 * `aria-expanded` under `md:`.
 *
 * Sju rader ovanför innehållet är sju rader man skrollar förbi varje gång, och
 * samma svar som SettingsLayout ger samma problem gäller här. Över `md:` står
 * listan framme och knappen försvinner (`md:hidden` på knappen, `md:flex` på
 * listan).
 */
it('fäller ihop sidokolumnen på en telefon', function () {
    $kod = containerinstallningskolumnKod('layouts/ContainerSettingsLayout.vue');

    expect($kod)->toContain('md:hidden')
        ->toContain('aria-controls')
        ->toContain(':aria-expanded')
        ->toContain('md:flex')
        ->toContain('sectionsOpen');

    // Klasserna som gör själva fällningen: fälld under `md:`, framme över.
    expect($kod)->toContain("sectionsOpen ? 'flex' : 'hidden md:flex'");

    // Knappens etikett kommer ur `lang/` — samma två nycklar som skalets meny
    // bär, och etiketten säger vad knappen gör och inte vad ytan är.
    expect($kod)->toContain("t('nav.menu')")
        ->toContain("t('nav.menu_close')");
});

/*
 * Klart när: länkarna bär `min-h-11`, och aktiv post har `aria-current="page"`.
 *
 * Den aktiva posten läses ur adressen mot `section.href(container.ulid)` och
 * inte ur ett tillstånd — samma skäl som i UiTabs: en post man kan länka till
 * är en post man kan dela, och jämförelsen klarar en omladdning. Träffytan är
 * 44 px ur issue 68a § Beslut 3.
 */
it('markerar aktiv post och håller tryckytan på 44 px', function () {
    $kod = containerinstallningskolumnKod('layouts/ContainerSettingsLayout.vue');

    $lankar = containerinstallningskolumnLanktaggar($kod);

    expect($lankar)->toHaveCount(1, 'layouten ritar ingen egen länkrad');

    expect($lankar[0])->toContain('section.href(container.ulid)')
        ->toContain('min-h-11')
        ->toContain('aria-current')
        ->toContain('isActive(section) ? \'page\' : undefined');

    // Adressen är källan, och jämförelsen tål en undersökväg — samma form som
    // SettingsLayouts isActive.
    expect($kod)->toContain('page.url === href')
        ->toContain('page.url.startsWith(`${href}/`)');
});

/*
 * Klart när: `<nav>` bär `aria-label` ur `t('container.edit.sections')` och
 * ingen sträng är hårdkodad.
 *
 * Texten formuleras på servern och slås bara upp på klienten
 * ([[ADR-0013 Språk och i18n]]): en svensk eller engelsk literal i en .vue-fil
 * blir aldrig översatt, och `t()` hade skrivit nyckeln själv på skärmen om
 * uppslaget misslyckats. Provet fångar båda: nycklarna finns i katalogen, och
 * meningen står inte i filen.
 */
it('hämtar alla texter ur översättningsfilen', function () {
    $kod = containerinstallningskolumnKod('layouts/ContainerSettingsLayout.vue');

    expect($kod)->toContain(":aria-label=\"t('container.edit.sections')\"");

    foreach (['container.edit.sections', 'nav.menu', 'nav.menu_close'] as $nyckel) {
        expect($kod)->toContain("t('{$nyckel}')");

        $mening = trans("ui.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');
    }

    // Rubriken över listan är samma nyckel, och meningen själv står inte i
    // filen — den bor i `lang/en/ui.php`.
    expect($kod)->not->toContain('More in this container');
});

/*
 * Klart när: `Edit.vue` inte har kvar listan under formuläret.
 *
 * Listan flyttade till layouten, och `<nav>` med den — inklusive
 * `mt-12 border-t`-avgränsaren. Kvar på sidan är formuläret, bildavsnittet och
 * raderingsknappen, oförändrade; provet fäster alla tre så att borttagningen
 * inte tog något med sig.
 */
it('har inte kvar listan under formuläret', function () {
    $kod = containerinstallningskolumnKod('pages/Containers/Edit.vue');

    // Två rader och inte en kedja: `not->toContain` mitt i en kedja fäller
    // phpstan (Expectation::not finns bara på det första ledet).
    expect($kod)->not->toContain('<nav');
    expect($kod)->not->toContain('containerSettingsSections');

    expect($kod)->toContain("t('container.edit.submit')")
        ->toContain('<ContainerCoverSheet')
        ->toContain('v-if="can.delete"');
});
