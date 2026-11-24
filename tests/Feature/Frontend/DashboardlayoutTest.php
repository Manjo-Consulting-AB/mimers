<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 252 · Dashboardens nya uppställning — se
 * resources/js/pages/Dashboard.vue.
 *
 * Provet är ett källkodsprov, som GenomgangTest: Vue-komponenten ritas i
 * webbläsaren och går inte att rendera här, men formen går att läsa ur filen.
 * Kommentarerna rensas bort först — annars kunde en mening om `lg:col-span-3`
 * nöja ett prov som letar efter klassen (samma fälla som SprakTest och
 * GenomgangTest rensar bort).
 *
 * Sidan är monteringspunkten (issue 122) och uppställningen är dess egen:
 * propparna, panelerna och talen står still — bara var sakerna hamnar prövas
 * här. Ordningen under `lg:` och den oberoende kolumnen prövas formellt i
 * issue 252 § Beslut 2 och 3. Sedan issue 435 · [[ADR-0090 Dashboardens
 * hjälte]] § 1 och 6 är den översta raden en hjälte, och brickorna och
 * kostnadssummeringen står inne i den över `md:` men under bandet på en
 * telefon.
 *
 * Hjälparna har prefixet `dashboardlayout` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar: ett
 * prov som letar efter en klass ska inte kunna nöjas av en mening i ett
 * docblock eller i en kommentar.
 */
function dashboardlayoutUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Klasserna på den `<div>` ett element står direkt i, eller null när elementet
 * inte står direkt i en div. `$element` är markupen som inleder elementet —
 * komponentnamnet eller taggen med sina attribut.
 */
function dashboardlayoutRamKlasser(string $markup, string $element): ?string
{
    $monster = '/<div class="([^"]*)"[^>]*>\s*<'.preg_quote($element, '/').'/s';

    if (preg_match($monster, $markup, $träff) !== 1) {
        return null;
    }

    return $träff[1];
}

/**
 * Klasserna på ett element, `$element` är markupen som inleder det. Till
 * skillnad från dashboardlayoutRamKlasser letar den här efter klassattributet
 * på elementet SJÄLVT och inte på `<div>`:en runt det.
 */
function dashboardlayoutKlasser(string $markup, string $element): ?string
{
    $monster = '/<'.preg_quote($element, '/').'[^>]*\bclass="([^"]*)"/s';

    if (preg_match($monster, $markup, $träff) !== 1) {
        return null;
    }

    return $träff[1];
}

/**
 * Positionen för en nål, med ett tydligt fel när den saknas.
 */
function dashboardlayoutPosition(string $markup, string $nål): int
{
    $position = strpos($markup, $nål);

    expect($position)->not->toBeFalse("{$nål} saknas i Dashboard.vue");

    return (int) $position;
}

// --- den översta raden -----------------------------------------------------

/*
 * Klart när (issue 435): hjälten är sidans översta rad, och brickorna och
 * kostnadssummeringen står inne i den över `md:`.
 *
 * Den översta raden ritas av `DashboardHero` sedan [[ADR-0090 Dashboardens
 * hjälte]] § 1, och den ritas före containergrupperna. Inne i hjälten står
 * brickorna före summeringen, precis som i den gamla raden (issue 474).
 */
it('ställer hjälten överst och summeringen inne i den före containergrupperna', function () {
    $sida = dashboardlayoutUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));
    $hjalten = dashboardlayoutUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    $hjalte = dashboardlayoutPosition($sida, '<DashboardHero');
    $containrarna = dashboardlayoutPosition($sida, 'props.containerGroups');

    expect($hjalte)->toBeLessThan($containrarna);

    // Hjälten ritar brickorna och summeringen i samma ordning som den gamla
    // raden: brickorna först, summeringen efter dem.
    expect(dashboardlayoutPosition($hjalten, '<DashboardStats'))
        ->toBeLessThan(dashboardlayoutPosition($hjalten, '<HeroCosts'));
});

/*
 * Klart när: "containrarna och panelerna står i var sin kolumn" —
 * containergrupperna och `<DashboardTasksPanel` har olika föräldrakolumner,
 * och ingen av dem är ett direkt barn till samma grid.
 *
 * Kolumnerna är de två `flex flex-col` som står under raden: vänster spänner
 * två kolumner (`lg:col-span-2`) och bär containergrupperna, höger är den
 * tredje (`lg:col-start-3`) och bär uppgifterna och händelserna. Ingen av dem
 * bär rutnätsklassen — rutnätet är förälder till kolumnerna, inte till
 * grupperna och panelen.
 */
it('lägger containrarna och panelerna i var sin kolumn', function () {
    $sida = dashboardlayoutUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    $containrar = dashboardlayoutRamKlasser($sida, 'section v-for="group in props.containerGroups"');
    $uppgifter = dashboardlayoutRamKlasser($sida, 'DashboardTasksPanel');

    expect($containrar)->not->toBeNull('containergrupperna står inte i en kolumn')
        ->and($uppgifter)->not->toBeNull('uppgiftspanelen står inte i en kolumn');

    // Olika kolumner: vänster spänner två kolumner, höger är den tredje.
    expect($containrar)->not->toBe($uppgifter);
    expect((string) $containrar)->toContain('lg:col-span-2');
    expect((string) $uppgifter)->toContain('lg:col-start-3');

    // Och ingen av dem ÄR rutnätet: klassen sitter på föräldern till
    // kolumnerna, inte på kolumnerna själva.
    expect((string) $containrar)->not->toContain('lg:grid lg:grid-cols-3');
    expect((string) $uppgifter)->not->toContain('lg:grid lg:grid-cols-3');
});

// --- hjälten under md ------------------------------------------------------

/*
 * Klart när (ADR-0090 § 6): under `md:` är hjälten ett lågt band med
 * hälsningen och rubriken, och brickorna och kostnadssummeringen står under
 * det.
 *
 * Ramen bär båda höjderna — `min-h-32` under `md:` och `min-h-56` över — och
 * sifferraden är `hidden … md:flex`: den ritas bara i den breda formen.
 * `Dashboard.vue` ritar därför en andra kopia under bandet i ett block som är
 * `md:hidden`.
 */
it('gör hjälten till ett lågt band under md med siffrorna under', function () {
    $hjalten = dashboardlayoutUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));
    $sida = dashboardlayoutUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    $ramen = dashboardlayoutKlasser($hjalten, 'HeroFrame');
    expect($ramen)->not->toBeNull('hjälten ritar ingen HeroFrame')
        ->and((string) $ramen)->toContain('min-h-32')
        ->and((string) $ramen)->toContain('md:min-h-56');

    $sifferraden = dashboardlayoutRamKlasser($hjalten, 'DashboardStats');
    expect($sifferraden)->not->toBeNull('brickorna står inte i en egen rad')
        ->and((string) $sifferraden)->toContain('hidden')
        ->and((string) $sifferraden)->toContain('md:flex');

    // Och sidan ritar brickorna och summeringen under bandet, bara under md.
    $mobilraden = dashboardlayoutRamKlasser($sida, 'DashboardStats');
    expect($mobilraden)->not->toBeNull('sidan ritar inga brickor under hjälten')
        ->and((string) $mobilraden)->toContain('md:hidden');

    expect(dashboardlayoutPosition($sida, '<DashboardHero'))
        ->toBeLessThan(dashboardlayoutPosition($sida, '<DashboardStats'));
});

// --- kostnadssummeringen ---------------------------------------------------

/*
 * Klart när (issue 474): utan kostnadsrader ritas ingen `HeroCosts`, inte
 * heller rubriken.
 *
 * Villkoret bor i `HeroCosts` självt — `v-if="props.totals.length"` på roten
 * — så både hjälten och mobilraden får samma tomma läge utan en egen gren. En
 * rubrik över inga rader är en yta som påstår att det finns något att visa.
 */
it('ritar ingen kostnadssummering utan kostnader', function () {
    $summeringen = dashboardlayoutUtanKommentarer(File::get(resource_path('js/components/HeroCosts.vue')));

    expect($summeringen)->toContain('v-if="props.totals.length"');

    foreach (['js/components/AppHero.vue', 'js/pages/Dashboard.vue'] as $fil) {
        $kod = dashboardlayoutUtanKommentarer(File::get(resource_path($fil)));

        // Ingen egen rubrik och ingen donut kvar på ytan.
        expect($kod)->not->toContain("t('dashboard.costs.heading')");
        expect($kod)->not->toContain('<CostDonut');
    }
});

// --- mobilordningen --------------------------------------------------------

/*
 * Klart när (Beslut 3): under `lg:` är ordningen brickorna, kostnaden,
 * uppgifterna, containrarna, händelserna och sist informationsytan.
 *
 * Beslut 2 binder uppgifterna och containrarna till var sin kolumn, och en
 * kolumn är en enhet i källordningen — utan ett grepp om saken hade den ena
 * följt den andra, och Beslut 3 hade varit omöjlig att uppfylla utan att bryta
 * Beslut 2. Greppet är `contents`: under `lg:` ritar kolumnen ingen egen box,
 * så hennes barn blir syskon i rutnätets flexkolumn och kan ordnas med
 * `order-*`. Över `lg:` nollställs ordningen (`lg:order-none`), så rutnätets
 * egen ordning står kvar — den prövas av proven ovan.
 *
 * Ordningens tal: uppgifterna 1, containrarna 2, händelserna 3 och
 * informationsytan 4. Den översta raden (brickorna och kostnaden) bär inget
 * tal och står först av sig själv.
 */
it('ordnar mobilen som Beslut 3: uppgifterna före containrarna', function () {
    $sida = dashboardlayoutUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    // Kolumnerna ritar ingen egen box under `lg:` — utan `contents` kunde
    // deras barn inte ordnas mot varandra.
    expect((string) dashboardlayoutRamKlasser($sida, 'section v-for="group in props.containerGroups"'))
        ->toContain('contents')
        ->and((string) dashboardlayoutRamKlasser($sida, 'DashboardTasksPanel'))
        ->toContain('contents');

    $uppgifter = dashboardlayoutKlasser($sida, 'DashboardTasksPanel');
    $containrar = dashboardlayoutKlasser($sida, 'section v-for="group in props.containerGroups"');
    $handelser = dashboardlayoutRamKlasser($sida, 'DashboardActivityPanel');
    $information = dashboardlayoutRamKlasser($sida, 'InfoPanel');

    expect($uppgifter)->not->toBeNull('uppgiftspanelen saknar klasser')
        ->and($containrar)->not->toBeNull('containergrupperna saknar klasser')
        ->and($handelser)->not->toBeNull('händelsepanelen saknar klasser')
        ->and($information)->not->toBeNull('informationsytan saknar klasser');

    // Uppgifterna (1) före containrarna (2), händelserna (3) efter dem och
    // informationsytan (4) sist.
    expect((string) $uppgifter)->toContain('order-1')
        ->and((string) $containrar)->toContain('order-2')
        ->and((string) $handelser)->toContain('order-3')
        ->and((string) $information)->toContain('order-4');

    // Och över `lg:` nollställs ordningen, så rutnätet ligger som källan.
    expect((string) $uppgifter)->toContain('lg:order-none')
        ->and((string) $containrar)->toContain('lg:order-none')
        ->and((string) $handelser)->toContain('lg:order-none')
        ->and((string) $information)->toContain('lg:order-none');
});
