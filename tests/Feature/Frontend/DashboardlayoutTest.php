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
 * issue 252 § Beslut 2 och 3.
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
 * Klart när: "donuten står i översta raden" — `<CostDonut` står före
 * containergrupperna.
 *
 * Den översta raden (Beslut 1) är ramen som spänner alla tre kolumnerna
 * (`lg:col-span-3`), och den ritas först. Att donuten ligger efter radens
 * öppning men före containergrupperna bevisar att den står INUTI raden:
 * containergrupperna ligger i vänsterkolumnen, som öppnas efter raden.
 */
it('ställer kostnadsdonuten i den översta raden före containergrupperna', function () {
    $sida = dashboardlayoutUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    $överstaRaden = dashboardlayoutPosition($sida, 'lg:col-span-3');
    $donuten = dashboardlayoutPosition($sida, '<CostDonut');
    $containrarna = dashboardlayoutPosition($sida, 'props.containerGroups');

    expect($donuten)->toBeGreaterThan($överstaRaden)
        ->and($donuten)->toBeLessThan($containrarna);
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

// --- kostnadskortet --------------------------------------------------------

/*
 * Klart när: "utan kostnader ritas inget kostnadskort" — villkoret på
 * `costs.totals.length` står kvar.
 *
 * Kortet ritas bara när månaden har någon kostnadsrad, och donuten ligger
 * inuti grenen: en tom ring under en rubrik är en yta som påstår att det finns
 * något att visa (issue 125). Att villkoret står före donuten i källan bevisar
 * att det gäller den.
 */
it('ritar inget kostnadskort utan kostnader', function () {
    $sida = dashboardlayoutUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    expect($sida)->toContain('v-if="props.costs.totals.length"');

    expect(dashboardlayoutPosition($sida, 'v-if="props.costs.totals.length"'))
        ->toBeLessThan(dashboardlayoutPosition($sida, '<CostDonut'));
});
