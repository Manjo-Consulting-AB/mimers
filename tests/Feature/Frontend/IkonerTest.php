<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 282 · `UiIcon` och ikonlistan, se [[ADR-0057 Ikoner och typsnitt]] § 1–3.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Issuen bygger en sluten
 * lista och en komponent som ritar ur den; det som går att pröva på
 * serversidan är att listan bär exakt de roller ADR:en namnger, att varje post
 * bär sitt Lucide-namn och sin version, att licensen ligger oförändrad, att
 * `UiIcon` äger storlek, linjebredd, färg och `aria-hidden`, att rutans fem
 * toner står på tokens, och att ett `name` som inte finns i listan fälls.
 *
 * **Det som INTE prövas här** är hur ikonerna ser ut: att strecken möter
 * bilden, att rutan har rätt storlek mot texten och att tonerna håller
 * kontrasten. Det kräver en webbläsare och står i PR-kroppens handprov.
 *
 * Kommentarer rensas bort före varje kontroll, som i
 * tests/Feature/Frontend/GenomgangTest.php: filerna är fulla av svensk prosa
 * som förklarar varför, och en regel som letar efter `v-html` ska inte kunna
 * nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `ikon` — Pest lägger alla testfiler i samma namnrymd
 * när hela sviten körs.
 */

/**
 * Versionen som ikonerna kopierades ur. Den står i varje kommentar i listan
 * och i `lucide-static`-paketet provet utgick från.
 */
function ikonVersion(): string
{
    return '1.59.0';
}

/**
 * Startlistan ur [[ADR-0057 Ikoner och typsnitt]] § 1: roll → Lucide-namn.
 * `open` kom med issue 297 och containerns karta
 * ([[ADR-0059 Kartan med en mitt]] § 3), och bytte glyf från `arrow-up-right`
 * till `eye` i issue 309 ([[ADR-0062 Kartans luft, listans ruta och den
 * löpande sökningen]] § 4); övriga står i ADR-0057.
 *
 * @return array<string, string>
 */
function ikonRoller(): array
{
    return [
        'dashboard' => 'house',
        'container' => 'box',
        'inbox' => 'inbox',
        'tasks' => 'list-checks',
        'documents' => 'files',
        'history' => 'history',
        'search' => 'search',
        'settings' => 'settings',
        'notifications' => 'bell',
        'favorite' => 'star',
        'overview' => 'layout-dashboard',
        'item' => 'package',
        'task' => 'circle-check',
        'schedule' => 'calendar-clock',
        'cost' => 'coins',
        'document' => 'file-text',
        'image' => 'image',
        'link' => 'link-2',
        'loan' => 'handshake',
        'tag' => 'tag',
        'category' => 'folder-tree',
        'map' => 'network',
        'tip' => 'lightbulb',
        'add' => 'plus',
        'edit' => 'pencil',
        'more' => 'ellipsis',
        'trash' => 'trash-2',
        'share' => 'users',
        'export' => 'download',
        'upload' => 'upload',
        'close' => 'x',
        'check' => 'check',
        'chevron-right' => 'chevron-right',
        'chevron-down' => 'chevron-down',
        'arrow-right' => 'arrow-right',
        'warning' => 'triangle-alert',
        'overdue' => 'clock-alert',
        'camera' => 'camera',
        'menu' => 'menu',
        'calendar' => 'calendar',
        'more-vertical' => 'ellipsis-vertical',
        'chevron-left' => 'chevron-left',
        'mail' => 'mail',
        'open' => 'eye',
    ];
}

/**
 * En fil ur resources/js, relativt katalogen.
 */
function ikonKod(string $sokvag): string
{
    return File::get(resource_path("js/{$sokvag}"));
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function ikonUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Posterna i listan, lästa radvis: roll → Lucide-namn, version och antal
 * noder. Kommentaren står raden ovanför rollen, och noderna är de åtta
 * indragna raderna under den.
 *
 * @return array<string, array{lucide: string, version: string, noder: int}>
 */
function ikonPoster(): array
{
    $rader = preg_split('/\R/', ikonKod('icons/index.js')) ?: [];
    $poster = [];
    $kommentar = [];

    foreach ($rader as $rad) {
        if (preg_match('#^\s*//\s*([a-z0-9-]+) · lucide-static (\S+)\s*$#', $rad, $träff) === 1) {
            $kommentar = ['lucide' => $träff[1], 'version' => $träff[2]];

            continue;
        }

        if (preg_match("#^\s{4}'?([a-z][a-z-]*)'?:\s*\[\s*$#", $rad, $träff) === 1) {
            $poster[$träff[1]] = [
                'lucide' => $kommentar['lucide'] ?? '',
                'version' => $kommentar['version'] ?? '',
                'noder' => 0,
            ];

            continue;
        }

        if ($poster === [] || preg_match("#^\s{8}\['[a-z]+', \{.*\}\],?$#", $rad) !== 1) {
            continue;
        }

        $poster[array_key_last($poster)]['noder']++;
    }

    return $poster;
}

/**
 * Namnen på de `UiIcon`-anrop en fil gör. Ett statiskt `name="…"` läses som
 * det är, och i ett bundet uttryck läses varje strängliteral — en dynamisk
 * variabel har ingen literal och kan inte prövas här.
 *
 * @return array<int, string>
 */
function ikonAnrop(string $kod): array
{
    preg_match_all(
        '#<UiIcon\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>#s',
        $kod,
        $träffar,
    );

    $namn = [];

    foreach ($träffar[1] as $attribut) {
        if (preg_match('/(?<!:)\bname="([^"]+)"/', $attribut, $träff) === 1) {
            $namn[] = $träff[1];
        } elseif (preg_match("/(?<!:)\bname='([^']+)'/", $attribut, $träff) === 1) {
            $namn[] = $träff[1];
        }

        if (preg_match('/:name="([^"]*)"/', $attribut, $träff) === 1) {
            preg_match_all("/'([a-z][a-z-]*)'/", $träff[1], $literaler);

            $namn = [...$namn, ...$literaler[1]];
        }
    }

    return $namn;
}

/**
 * Råa färgklasser i en fil: en palettfärg, eller svart eller vitt, efter ett
 * verktyg som målar. Samma regel som i DesignsystemTest — rollen ska komma ur
 * `@theme`, och en palettfärg är den färg rollen skulle ha ersatt.
 *
 * @return array<int, string>
 */
function ikonRaaFargklasser(string $kod): array
{
    $palett = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $verktyg = 'text|bg|border|ring|outline|divide|from|via|to|fill|stroke|placeholder|decoration|shadow|accent|caret';

    preg_match_all(
        '/\b(?:'.$verktyg.')-(?:(?:'.$palett.')-\d{2,3}|white|black)\b/',
        $kod,
        $träffar,
    );

    return $träffar[0];
}

it('har varje roll i startlistan med Lucide-namn och version', function () {
    $poster = ikonPoster();
    $roller = ikonRoller();

    // Listan är sluten: samma roller, i samma ordning som tabellen. En roll
    // som saknas är en sida som faller tillbaka på ingenting, och en roll för
    // mycket är en ikon någon lagt till utan beslut.
    expect($poster)->toHaveCount(count($roller));
    expect(array_keys($poster))->toBe(array_keys($roller));

    foreach ($roller as $roll => $lucide) {
        expect($poster[$roll]['lucide'])->toBe(
            $lucide,
            "{$roll} bär inte Lucide-namnet {$lucide} i kommentaren",
        );

        expect($poster[$roll]['version'])->toBe(
            ikonVersion(),
            "{$roll} bär ingen version i kommentaren",
        );

        expect($poster[$roll]['noder'])->toBeGreaterThan(
            0,
            "{$roll} saknar noder — posten är ingen ikon",
        );
    }
});

it('har Lucides LICENSE oförändrad', function () {
    $licens = ikonKod('icons/LICENSE');

    // Hashen är filen så den kom ur `lucide-static` 1.59.0. En ändrad rad i
    // licensen är ett licensbrott, inte en städning.
    expect(hash('sha256', $licens))->toBe(
        'b495047bd93a9b06913511076f504daba17d5bbeb3e0650f3bb53a4220329c57',
    );

    expect($licens)->toStartWith('ISC License');
    expect($licens)->toContain('The MIT License (MIT)');
});

it('ritar varje roll med h i tre storlekar', function () {
    $kod = ikonUtanKommentarer(ikonKod('components/UiIcon.vue'));

    // Rollen slås upp i listan. Ingen ikon ritas för hand i komponenten.
    expect($kod)->toContain("import { icons } from '../icons/index.js'");
    expect($kod)->toMatch('/icons\[props\.name\]/');

    // Tre storlekar och ingen fjärde: 16 för metarader, 20 som förval och 24
    // för taltutor och hjälten.
    expect($kod)->toMatch('/SIZES\s*=\s*\[16,\s*20,\s*24\]/');
    expect($kod)->toContain('size: { type: Number, default: 20 }');

    // SVG:en byggs med h() — noderna också. Ingen sträng med markup.
    expect($kod)->toMatch("/h\(\s*'svg'/");
    expect($kod)->toMatch('/nodes\.map\(\(\[tag, attributes\]\)\s*=>\s*h\(tag, attributes\)\)/');

    // Linjebredden är 1,75 och färgen är currentColor, för båda storlekarna
    // på varje roll.
    expect($kod)->toMatch("/'stroke-width':\s*1\.75/");
    expect($kod)->toMatch("/stroke:\s*'currentColor'/");
    expect($kod)->toMatch('/width:\s*size/');
    expect($kod)->toMatch('/height:\s*size/');
});

it('sätter aria-hidden på den ritade svg:en', function () {
    $kod = ikonUtanKommentarer(ikonKod('components/UiIcon.vue'));

    // En ikon upprepar alltid en text. Ett undantag hade varit en ikon som
    // skärmläsaren läser som innehåll.
    expect($kod)->toMatch("/'aria-hidden':\s*'true'/");

    // Exakt en svg skapas, och den skapas med h() — ingen rå <svg> i mallen.
    expect(preg_match_all("/'svg'/", $kod))->toBe(1);
    expect($kod)->not->toContain('<svg');
});

it('ritar ikonrutan i de fem tonerna och bara på tokens', function () {
    $kod = ikonUtanKommentarer(ikonKod('components/UiIcon.vue'));

    // `tile` bär tonens namn; tomt värde betyder ingen ruta. ADR:en namnger
    // ingen egen `tone`-propp.
    expect($kod)->toMatch('/\btile:\s*\{\s*type:\s*String/');
    expect($kod)->toContain('TONES[props.tile]');
    expect($kod)->toContain('rounded-control');

    // Tonerna ur ADR-0057 § 2 och deras ytor ur § 6. `accent` och `neutral`
    // har redan sina mjuka ytor i @theme; de tre övriga kom med issue 281.
    $toner = [
        'accent' => 'bg-accent-soft text-accent',
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'neutral' => 'bg-surface-sunken text-ink-muted',
    ];

    foreach ($toner as $ton => $klasser) {
        expect($kod)->toContain("{$ton}: '{$klasser}'");
    }

    // Fem och inte fler: en sjätte ton är en nyans någon hittade på.
    preg_match_all("/^\s{4}([a-z]+):\s*'/m", $kod, $träffar);

    expect($träffar[1])->toBe(['accent', 'success', 'warning', 'danger', 'neutral']);

    // Och klasserna är roller, inte palettfärger.
    expect(ikonRaaFargklasser($kod))->toBe([]);
});

it('använder inte v-html', function () {
    // Klart när-punkten gäller både komponenten och listan. Kommentarerna
    // nämner ordet med flit; det är koden som prövas.
    foreach (['components/UiIcon.vue', 'icons/index.js'] as $sokvag) {
        expect(ikonUtanKommentarer(ikonKod($sokvag)))->not->toContain(
            'v-html',
            "{$sokvag} använder v-html — ikonerna ritas med h()",
        );
    }
});

it('fäller ett UiIcon-name som inte finns i listan', function () {
    $roller = array_keys(ikonRoller());
    $vueFiler = array_values(array_filter(
        File::allFiles(resource_path('js')),
        fn ($fil): bool => $fil->getExtension() === 'vue',
    ));

    expect($vueFiler)->not->toBeEmpty();

    // Varje .vue-fil läses, även den här komponenten själv. Glober filen bort
    // blir loopen tyst, och ett tyst prov är värre än inget.
    expect(array_map(fn ($fil): string => $fil->getRelativePathname(), $vueFiler))
        ->toContain('components/UiIcon.vue');

    foreach ($vueFiler as $fil) {
        $kod = ikonUtanKommentarer($fil->getContents());

        foreach (ikonAnrop($kod) as $namn) {
            // `toContain` tar VARIABLA nålar, inte ett meddelande som andra
            // argument — det hade letats efter som en andra nål. Jämförelsen
            // står därför i `in_array`, med meddelandet på `toBeTrue`.
            expect(in_array($namn, $roller, true))->toBeTrue(sprintf(
                '%s kallar UiIcon med name="%s", som inte finns i resources/js/icons/index.js',
                $fil->getRelativePathname(),
                $namn,
            ));
        }
    }

    // Detektorn själv: en statisk roll fångas, en literal i ett bundet namn
    // fångas, och en dynamisk variabel hoppas över. Utan det här är provet
    // tyst så länge ingen sida använder UiIcon än.
    expect(ikonAnrop('<UiIcon name="finns-inte" />'))->toBe(['finns-inte']);
    expect(ikonAnrop("<UiIcon :name=\"'item'\" />"))->toBe(['item']);
    expect(ikonAnrop('<UiIcon :name="role" />'))->toBe([]);
});

it('fäller en ny handskriven svg utanför undantagen', function () {
    /*
     * Undantagen ritar data och inte ikoner ([[ADR-0057 Ikoner och typsnitt]]
     * § 2): diagram, donut, kartor, lagringsstapeln och QR-koden.
     *
     * Listan är issue 290:s nio filer, plus kartans linjelager från issue 306
     * ([[ADR-0061 Kartans kort och kanter]] § 5): det ritar kurvor mellan
     * korten och ingen ikon. `TaskOverviewPanel` står inte här: dess ring ritas
     * som en `conic-gradient`, inte som `<svg>`, så den behöver inget undantag.
     */
    $undantag = [
        'components/UiIcon.vue',
        'components/CostDonut.vue',
        'components/CostTimeChart.vue',
        'components/CostCategoryBreakdown.vue',
        'components/ActivityTimeChart.vue',
        'components/ActivityTypeChart.vue',
        'components/StorageBar.vue',
        'components/TotpQrCode.vue',
        'components/FocusMapNode.vue',
        'components/ItemMapEdges.vue',
    ];

    $vueFiler = array_values(array_filter(
        File::allFiles(resource_path('js')),
        fn ($fil): bool => $fil->getExtension() === 'vue',
    ));

    expect($vueFiler)->not->toBeEmpty();

    $granskade = 0;

    foreach ($vueFiler as $fil) {
        $relativ = $fil->getRelativePathname();

        if (in_array($relativ, $undantag, true)) {
            continue;
        }

        $granskade++;

        expect(str_contains(ikonUtanKommentarer($fil->getContents()), '<svg'))->toBeFalse(sprintf(
            '%s ritar en egen <svg> — ikoner går genom UiIcon, se ADR-0057 § 2',
            $relativ,
        ));
    }

    // Ett prov som granskar noll filer är tyst, inte grönt.
    expect($granskade)->toBe(count($vueFiler) - count($undantag));

    // Undantagen ska vara riktiga. UiIcon bygger svg:en med h() och bär
    // därför ingen literal; de övriga ska fortfarande rita data.
    foreach ($undantag as $relativ) {
        if ($relativ === 'components/UiIcon.vue') {
            continue;
        }

        expect(str_contains(ikonUtanKommentarer(ikonKod($relativ)), '<svg'))->toBeTrue(
            "{$relativ} står som undantag men ritar ingen <svg>",
        );
    }
});
