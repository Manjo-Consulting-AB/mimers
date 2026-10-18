<?php

use Illuminate\Support\Facades\File;

/*
 * M32 · issue 288 — översiktens rutnät utan hål och de tomma lägena, se
 * resources/js/pages/Containers/Overview.vue, de fyra panelkomponenterna,
 * resources/js/components/UiEmptyState.vue och [[M32 Formspråket]] § 288.
 *
 * **Rutnätet ritas i webbläsaren och går inte att rendera här.** Det som går
 * att avgöra på serversidan är formen: att inga fasta rader finns, att
 * rutnätet fyller med `grid-flow-dense` och spann, att panelerna ligger i
 * samma källordning som förut, att varje panelrubrik bär en ikon ur listan,
 * och att varje tomt läge ritas av `UiEmptyState`.
 *
 * **Fyllningen prövas som en simulering.** `grid-auto-flow: dense` lägger
 * varje panel i första lediga plats, så provet läser panelernas spann ur
 * källan — de statiska klasserna och den villkorliga `:class`-ternären — och
 * lägger dem rad för rad för alla fyra kombinationer av kostnads- och
 * bildpanel. Sedan issue 321 spänner omslaget med itemen och bilderna två
 * rader när bilderna finns, och simuleringen tar hänsyn till det också. En
 * rad som inte går jämnt ut i tre kolumner är hålet issuen stänger.
 *
 * Kommentarerna rensas bort först, som i GenomgangTest och
 * DashboardlayoutTest: filerna är fulla av svensk prosa som förklarar varför,
 * och en mening om `lg:row-start-3` ska inte kunna fällas av ett prov som
 * letar efter klassen.
 *
 * Hjälparna har prefixet `rutnat` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function rutnatUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Översikten med kommentarer borta.
 */
function rutnatVy(): string
{
    return rutnatUtanKommentarer(File::get(resource_path('js/pages/Containers/Overview.vue')));
}

/**
 * En fil ur resources/js, relativt katalogen, med kommentarer borta.
 */
function rutnatKod(string $sokvag): string
{
    return rutnatUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/**
 * Öppningstaggen för panelens ram, hittad på sin ordningsklass.
 */
function rutnatRam(string $vy, int $ordning): string
{
    $monster = '/<div\b([^>]*\blg:order-'.$ordning.'\b[^>]*)>/s';

    expect(preg_match($monster, $vy, $träff))->toBe(
        1,
        "ingen panel bär lg:order-{$ordning}",
    );

    return $träff[1];
}

/**
 * Den valda klasssträngen för en ram i en kombination: den statiska `class`
 * och den villkorliga `:class`-ternären lagda efter varandra.
 */
function rutnatKlasser(string $ram, bool $kostnader, bool $bilder): string
{
    $klasser = '';

    // `(?<!:)` skiljer den statiska klassen från den villkorliga `:class`.
    if (preg_match('/(?<!:)class="([^"]*)"/', $ram, $träff) === 1) {
        $klasser = $träff[1];
    }

    if (preg_match('/:class="([^"]+)"/', $ram, $träff) !== 1) {
        return $klasser;
    }

    $uttryck = $träff[1];

    expect($uttryck)->toMatch(
        "/^props\.(costs\.totals|recentImages)\.length \? '([^']*)' : '([^']*)'$/",
        "oväntad :class-form i rutnätet: {$uttryck}",
    );

    preg_match(
        "/^props\.(costs\.totals|recentImages)\.length \? '([^']*)' : '([^']*)'$/",
        $uttryck,
        $delar,
    );

    $sant = $delar[1] === 'costs.totals' ? $kostnader : $bilder;

    return trim($klasser.' '.($sant ? $delar[2] : $delar[3]));
}

/**
 * Kolumnspannet en ram får i en kombination. Standard är en kolumn; en
 * statisk `lg:col-span-*` eller den villkorliga ternären breddar den.
 */
function rutnatSpann(string $ram, bool $kostnader, bool $bilder): int
{
    return preg_match('/lg:col-span-(\d+)/', rutnatKlasser($ram, $kostnader, $bilder), $span) === 1
        ? (int) $span[1]
        : 1;
}

/**
 * Radspannet en ram får i en kombination. Omslaget med itemen och bilderna
 * spänner två rader när bilderna finns (issue 321), så informationsytan
 * behåller sin plats i tredje kolumnen.
 */
function rutnatRadspann(string $ram, bool $kostnader, bool $bilder): int
{
    return preg_match('/lg:row-span-(\d+)/', rutnatKlasser($ram, $kostnader, $bilder), $span) === 1
        ? (int) $span[1]
        : 1;
}

/**
 * Panelernas spann och radspann i visuell ordning — ordningen `lg:order-*`
 * ger — för en kombination. Ett spann på noll betyder att panelen inte ritas.
 *
 * Ordning 6 finns inte: bilderna ligger i omslaget med itemen (issue 321),
 * och deras `v-if` styr omslagets spann i stället.
 *
 * @return array<int, array{spann: int, rader: int}>
 */
function rutnatBredder(string $vy, bool $kostnader, bool $bilder): array
{
    $bredder = [];

    foreach ([1, 2, 3, 4, 5, 7] as $ordning) {
        $ram = rutnatRam($vy, $ordning);

        $spann = rutnatSpann($ram, $kostnader, $bilder);

        // Ordning 2 är kostnaderna; panelen ritas bara när listan har något
        // i sig, och då finns ingen panel att spänna.
        if ($ordning === 2 && ! $kostnader) {
            $spann = 0;
        }

        $bredder[$ordning] = [
            'spann' => $spann,
            'rader' => rutnatRadspann($ram, $kostnader, $bilder),
        ];
    }

    return $bredder;
}

/**
 * Lägger panelerna som `grid-auto-flow: dense`: varje panel tar första
 * lediga plats, med början på första raden, och ett spann som inte får plats
 * flyttar till nästa rad. Ett radspann upptar celler även på följande rader.
 *
 * @param  array<int, array{spann: int, rader: int}>  $bredder  i visuell ordning, noll = ritas inte
 * @return list<int> antalet fyllda kolumner per rad
 */
function rutnatRader(array $bredder): array
{
    $celler = [];

    foreach ($bredder as $panel) {
        $spann = $panel['spann'];

        if ($spann === 0) {
            continue;
        }

        $radspann = $panel['rader'];

        for ($rad = 0; ; $rad++) {
            $plats = null;

            for ($kolumn = 0; $kolumn + $spann <= 3; $kolumn++) {
                $fri = true;

                for ($r = 0; $r < $radspann && $fri; $r++) {
                    for ($i = 0; $i < $spann; $i++) {
                        if (isset($celler[$rad + $r][$kolumn + $i])) {
                            $fri = false;
                            break;
                        }
                    }
                }

                if ($fri) {
                    $plats = $kolumn;
                    break;
                }
            }

            if ($plats !== null) {
                for ($r = 0; $r < $radspann; $r++) {
                    for ($i = 0; $i < $spann; $i++) {
                        $celler[$rad + $r][$plats + $i] = true;
                    }
                }

                break;
            }
        }
    }

    ksort($celler);

    return array_map(fn (array $rad): int => count($rad), $celler);
}

/*
 * Klart när: ingen panel i `Overview.vue` har `lg:row-start-*`.
 *
 * Placeringen sker med `grid-flow-dense` och spann, inte med fasta rader:
 * en fast rad kan inte flytta sig när kostnads- eller bildpanelen inte ritas,
 * och det var just den fasta raden som lämnade kolumnen tom.
 */
it('placerar panelerna med grid-flow-dense och spann, inte med fasta rader', function () {
    $vy = rutnatVy();

    expect($vy)->toContain('lg:grid lg:grid-flow-dense lg:grid-cols-3')
        ->and($vy)->not->toMatch('/lg:row-start-\d/', 'en panel har fortfarande en fast rad')
        ->and($vy)->not->toMatch('/lg:col-start-\d/', 'en panel har fortfarande en fast kolumn');
});

/*
 * Klart när: utan kostnader, utan bilder och utan båda fyller panelerna
 * varje rad över `lg:` utan tom kolumn.
 *
 * Proven simulerar `grid-auto-flow: dense` för alla fyra kombinationerna.
 * Spannen läses ur källan, så en ändrad ternär eller en ny fast kolumn fälls
 * här och inte först i webbläsaren.
 */
it('fyller varje rad i alla fyra kombinationer av paneler', function () {
    $vy = rutnatVy();

    // Ordning 2 är kostnaderna; bilderna ligger i omslaget på ordning 4
    // (issue 321), och deras `v-if` styr omslagets spann.
    expect(rutnatRam($vy, 2))->toContain('v-if="props.costs.totals.length"')
        ->and(rutnatRam($vy, 4))->toContain('props.recentImages.length');

    foreach ([[true, true], [true, false], [false, true], [false, false]] as [$kostnader, $bilder]) {
        $rader = rutnatRader(rutnatBredder($vy, $kostnader, $bilder));

        expect($rader)->toHaveCount(3, sprintf(
            'kostnader=%s bilder=%s gav %d rader',
            $kostnader ? 'ja' : 'nej',
            $bilder ? 'ja' : 'nej',
            count($rader),
        ));

        foreach ($rader as $rad => $fyllda) {
            expect($fyllda)->toBe(3, sprintf(
                'kostnader=%s bilder=%s: rad %d har %d av 3 kolumner',
                $kostnader ? 'ja' : 'nej',
                $bilder ? 'ja' : 'nej',
                $rad + 1,
                $fyllda,
            ));
        }
    }
});

/*
 * Klart när: under `lg:` står *Items*, *Senaste bilder* och *Senaste
 * aktiviteter* i den ordningen.
 *
 * Ordningen över `lg:` sätts med `lg:order-*`, som bara gäller i rutnätet.
 * Under brytpunkten är behållaren ett block, där `order` inte har någon
 * verkan, och ingen panel får en ordningsklass utan `lg:`-prefix. Sedan
 * issue 321 ligger bilderna i samma omslag som itemen och före
 * aktiviteterna i källan, så mobilens ordning blir *Items*, *Senaste
 * bilder*, *Senaste aktiviteter*.
 */
it('ger mobilen ordningen Items, Senaste bilder, Senaste aktiviteter', function () {
    $vy = rutnatVy();

    $markörer = [
        '<InfoPanel',
        '<ContainerTasksPanel',
        "t('container.overview.costs')",
        '<ContainerDetailsPanel',
        "t('container.overview.view_all')",
        '<RecentImagesPanel',
        "t('container.overview.activity')",
    ];

    $positioner = [];

    foreach ($markörer as $markör) {
        $position = strpos($vy, $markör);

        expect($position)->not->toBeFalse("{$markör} saknas i Overview.vue");

        $positioner[] = (int) $position;
    }

    $sorterade = $positioner;
    sort($sorterade);

    expect($positioner)->toBe($sorterade, 'källordningen har ändrats');

    // Ordningsklasserna gäller bara över `lg:`.
    expect($vy)->not->toMatch('/(?<!lg:)order-\d/', 'en panel ordnas även under lg:');
});

/*
 * Klart när: varje panelrubrik på översikten bär en `UiIcon` ur listan.
 *
 * Översiktens egna paneler — kostnader, items och aktiviteter — ritar ikonen
 * i `heading`-slotten; de tre egna panelkomponenterna gör det samma.
 * Informationsytan får sin roll av anroparen: den ritas också på dashboarden,
 * som inte har fått sina ikoner än ([[M32 Formspråket]]).
 */
it('ger varje panelrubrik en ikon ur listan', function () {
    $vy = rutnatVy();

    expect($vy)->toMatch('~<template #heading>\s*<UiIcon name="cost" />~s')
        ->toMatch('~<template #heading>\s*<UiIcon name="item" />~s')
        ->toMatch('~<template #heading>\s*<UiIcon name="history" />~s');

    $ikoner = [
        'components/ContainerTasksPanel.vue' => 'tasks',
        'components/ContainerDetailsPanel.vue' => 'container',
        'components/RecentImagesPanel.vue' => 'image',
    ];

    foreach ($ikoner as $fil => $roll) {
        expect(rutnatKod($fil))->toMatch(
            '~<template #heading>\s*<UiIcon\b[^>]*name="'.$roll.'"[^>]*/>~s',
            "{$fil} saknar {$roll} i rubriken",
        );
    }

    // Informationsytan tar rollen som propp och ritar ingenting utan den.
    expect($vy)->toMatch('~<InfoPanel\b[^>]*\bicon="tip"~s')
        ->and(rutnatKod('components/InfoPanel.vue'))
        ->toMatch('~<UiIcon v-if="props\.icon" :name="props\.icon" />~')
        ->and(rutnatKod('components/InfoPanel.vue'))
        ->toContain('icon: { type: String, default: null }');
});

/*
 * Klart när: inget tomt läge på översikten är ett bart `<p>`; alla ritas av
 * `UiEmptyState`.
 *
 * De tre tomma lägena — uppgifterna, itemen och aktiviteterna — lånar samma
 * nycklar som förut. Bara formen byts: `UiEmptyState` äger ramen, och en bar
 * `<p>` var en yta utan den.
 */
it('ritar varje tomt läge med UiEmptyState', function () {
    $vy = rutnatVy();

    expect($vy)->toContain("import UiEmptyState from '../../components/UiEmptyState.vue'")
        ->toMatch("~<UiEmptyState v-if=\"props\\.items\\.length === 0\">\\s*\\{\\{ t\\('item\\.index\\.empty'\\) \\}\\}\\s*</UiEmptyState>~s")
        ->toMatch("~<UiEmptyState v-if=\"props\\.events\\.length === 0\">\\s*\\{\\{ t\\('audit\\.history\\.empty'\\) \\}\\}\\s*</UiEmptyState>~s");

    expect($vy)->not->toMatch('~<p[^>]*v-if="props\\.(items|events)\\.length === 0"~s');

    $uppgifter = rutnatKod('components/ContainerTasksPanel.vue');

    expect($uppgifter)->toContain("import UiEmptyState from './UiEmptyState.vue'")
        ->toMatch("~<UiEmptyState v-if=\"props\\.tasks\\.length === 0\">\\s*\\{\\{ t\\('todo\\.empty\\.nothing'\\) \\}\\}\\s*</UiEmptyState>~s");

    expect($uppgifter)->not->toMatch('~<p[^>]*v-if="props\\.tasks\\.length === 0"~s');
});
