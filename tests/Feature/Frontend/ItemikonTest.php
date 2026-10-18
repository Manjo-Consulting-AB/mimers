<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 300 · `ItemIcon` och Lucide, se [[ADR-0060 Itemets ikon]] § 1–6.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Issuen bygger en
 * komponent och en presentationsmodul som ingen vy ritar ännu (vyerna kommer i
 * 302–305). Det som går att pröva på serversidan är formen på källkoden: att
 * `lucide` är ett beroende, att globben är lat, att noderna ritas med `h()`
 * och aldrig med `v-html`, att fallbacken ritar `item` i neutral ton och i
 * samma storlek, att rutan följer paletten, att de sexton tokens finns, och
 * att ingen annan fil når in i paketet.
 *
 * **Det som INTE prövas här** är hur ikonerna ser ut: att Lucides streck
 * stämmer mot texten, att rutan håller måttet mot raden och att färgerna
 * håller kontrasten. Det kräver en webbläsare och står i PR-kroppens handprov.
 *
 * Kommentarer rensas bort före varje kontroll, som i
 * tests/Feature/Frontend/IkonerTest.php: filerna är fulla av svensk prosa,
 * och en regel som letar efter `v-html` eller `eager` ska inte kunna nöjas av
 * en mening i ett docblock.
 *
 * Hjälparna har prefixet `itemikon` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En fil ur resources/js, relativt katalogen.
 */
function itemikonKod(string $sokvag): string
{
    return File::get(resource_path("js/{$sokvag}"));
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function itemikonUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Paletten ur [[ADR-0060 Itemets ikon]] § 5, i väljarens ordning.
 *
 * @return array<int, string>
 */
function itemikonPaletten(): array
{
    return ['slate', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'violet'];
}

it('har lucide som beroende och en lat glob', function () {
    $paket = json_decode(File::get(base_path('package.json')), true);

    // Beroendet står under `dependencies`, inte `devDependencies`: ikonen
    // följer med bygget och är inte ett verktyg för utvecklaren.
    expect($paket['dependencies']['lucide'] ?? null)->not->toBeNull();

    $modul = itemikonKod('components/itemIconPresentation.js');

    // Mönstret står som en literal — Vite läser argumentet statiskt — och
    // `import: 'default'` ger ikonens nodlista i stället för modulen.
    expect($modul)->toContain(
        "import.meta.glob('/node_modules/lucide/dist/esm/icons/*.mjs', { import: 'default' })",
    );

    // Utan `eager`. Med den hade varje ikon hamnat i bundlen, och en sida
    // utan item-ikoner hade burit hela Lucide. Kommentarerna nämner ordet med
    // flit, så kontrollen läser anropets argument och inte hela filen — och
    // fällan med `/*` i globmönstret gör att kommentarsrensning inte kan
    // användas här.
    preg_match('/import\.meta\.glob\([^)]*\)/', $modul, $glob);

    expect($glob[0] ?? '')->not->toBe('');
    expect($glob[0])->not->toContain('eager');
});

it('hämtar en ikon först när den ritas', function () {
    $modul = itemikonUtanKommentarer(itemikonKod('components/itemIconPresentation.js'));
    $komponent = itemikonUtanKommentarer(itemikonKod('components/ItemIcon.vue'));

    // Modulen lämnar ut laddaren; den anropas aldrig på modulnivå. Ett namn
    // som saknas i globben ger `null` utan att någon fil hämtas.
    expect($modul)->toMatch('/export function loadIcon\s*\(/');
    expect($modul)->toContain("typeof name !== 'string'");
    expect($modul)->toContain('iconModules[`${ICON_PREFIX}${name}.mjs`] ?? null');

    // Komponenten hämtar ur namnet i sin watcher, alltså när den ritas.
    expect($komponent)->toMatch('/watch\(\s*\(\)\s*=>\s*props\.name/');
    expect($komponent)->toContain('const loader = loadIcon(name)');
    expect($komponent)->toContain('await loader()');

    // Fallbacken ritas medan filen hämtas: nollställningen står före await.
    expect(strpos($komponent, 'nodes.value = null'))->toBeLessThan(
        strpos($komponent, 'await loader()'),
    );
});

it('ritar ikonen med h och utan v-html', function () {
    $komponent = itemikonUtanKommentarer(itemikonKod('components/ItemIcon.vue'));

    // Samma nodform som UiIcon: `[tag, attribut]` ur Lucides fil, ritade med
    // h(). Ingen sträng med markup och ingen v-html (ADR-0060 § 1).
    expect($komponent)->toMatch("/h\(\s*'svg'/");
    expect($komponent)->toMatch(
        '/nodes\.value\.map\(\(\[tag, attributes\]\)\s*=>\s*h\(tag, attributes\)\)/',
    );
    expect($komponent)->not->toContain('v-html');
    expect(preg_match_all("/'svg'/", $komponent))->toBe(1);

    // Storleken, linjebredden, färgen och aria-hidden följer UiIcon.
    expect($komponent)->toMatch("/'stroke-width':\s*1\.75/");
    expect($komponent)->toMatch("/stroke:\s*'currentColor'/");
    expect($komponent)->toMatch("/'aria-hidden':\s*'true'/");
    expect($komponent)->toMatch('/width:\s*size\.value/');
    expect($komponent)->toMatch('/height:\s*size\.value/');
});

it('ritar fallback-rollen i neutral ton i samma storlek när ikonen saknas', function () {
    $komponent = itemikonUtanKommentarer(itemikonKod('components/ItemIcon.vue'));

    // Tre lägen faller tillbaka på samma ikon: `name` är null, namnet finns
    // inte i globben, och filen hämtas fortfarande. Två UiIcon-anrop: ett med
    // ruta och ett utan.
    expect(preg_match_all('/<UiIcon\b/', $komponent))->toBe(2);
    expect(substr_count($komponent, ':name="fallback"'))->toBe(2);

    // Samma normaliserade storlek i båda fallen, så att raden inte hoppar när
    // ikonen kommer.
    expect(substr_count($komponent, ':size="size"'))->toBe(2);
    expect($komponent)->toMatch('/SIZES\s*=\s*\[16,\s*20,\s*24\]/');
    expect($komponent)->toContain('SIZES.includes(props.size) ? props.size : 20');

    // Rutan i fallet utan ikon är UiIcons neutrala ton, inte paletten.
    expect($komponent)->toContain('tile="neutral"');
});

/*
 * Klart när (issue 316 · [[ADR-0064 Containerns ikon]] § 4): `ItemIcon` ritar
 * rollen i `fallback` när `name` är tomt eller okänt, och rollen `item` när
 * proppen inte skickas.
 *
 * Fallbacken är en roll ur den slutna listan i `resources/js/icons/index.js`
 * — inte ett Lucide-namn. Båda vägarna till en tom ikon (ett `name` som är
 * `null`, och ett namn som inte finns i globben) landar i samma två grenar,
 * eftersom `nodes` förblir null i båda fallen.
 */
it('ritar fallback för ett tomt eller okänt namn och item utan proppen', function () {
    $komponent = itemikonUtanKommentarer(itemikonKod('components/ItemIcon.vue'));

    expect($komponent)->toMatch(
        '/fallback:\s*\{\s*type:\s*String,\s*default:\s*\'item\'\s*\}/',
        'ItemIcon saknar proppen fallback med item som förval',
    );

    // Båda fallback-grenarna läser proppen — den med ruta och den utan.
    expect(substr_count($komponent, ':name="fallback"'))->toBe(2);

    // Ett okänt namn ger ingen loader, och noderna töms innan nästa ikon
    // hämtas: fallbacken ritas alltså både för null och för ett okänt namn.
    expect($komponent)->toContain('const loader = loadIcon(name)')
        ->toContain('if (!loader)')
        ->toContain('nodes.value = null');
});

/*
 * Klart när (issue 322 · [[M41 Fynd 2026-10-19]] § 322): med `plain` ritas
 * linjen i `currentColor` och ingen ruta, oavsett `color`; fallbacken ritas
 * med `UiIcon` utan den neutrala tonen. Utan proppen ritar komponenten som
 * förut — `tile` och dess palettklasser står kvar oförändrade.
 */
it('ritar linjen i currentColor utan ruta med plain', function () {
    $komponent = itemikonUtanKommentarer(itemikonKod('components/ItemIcon.vue'));

    expect($komponent)->toMatch(
        '/plain:\s*\{\s*type:\s*Boolean,\s*default:\s*false\s*\}/',
        'ItemIcon saknar proppen plain med false som förval',
    );

    // Rutan ritas bara utan plain — proppen vinner även om `tile` skickas med.
    expect($komponent)->toMatch('/v-if="tile\s*&&\s*!plain\s*&&\s*icon"/')
        ->and($komponent)->toMatch('/v-else-if="tile\s*&&\s*!plain"/');

    // Linjen bär palettklassen bara utan plain; med plain ärver den
    // `currentColor` ur raden som varje annan ikon i skalet.
    expect($komponent)->toContain('class: props.plain ? undefined : tone.value.line');

    // Fallbacken med plain — lådan för en container utan ikon — får ingen
    // neutral ton, så att den ritas i radens färg.
    expect($komponent)->toContain(":class=\"plain ? undefined : 'text-ink-muted'\"");

    // Utan plain står den neutrala rutan kvar, som förut.
    expect($komponent)->toContain('tile="neutral"');
});

/*
 * Klart när (issue 316 · [[ADR-0064 Containerns ikon]] § 5): rollen `container`
 * står kvar där den betyder containrar som BEGREPP och inte en viss container
 * — *Containers* i navigeringen, detaljpanelens rubrik och historikens
 * typfilter. De tre ytorna ritas med `UiIcon` och rörs inte av containerns
 * egen ikon.
 */
it('låter rollen container stå kvar i navigeringen, detaljpanelen och historikfiltret', function () {
    $sektioner = itemikonUtanKommentarer(itemikonKod('components/ShellSections.vue'));
    $panelen = itemikonUtanKommentarer(itemikonKod('components/ContainerDetailsPanel.vue'));
    $filtret = itemikonUtanKommentarer(itemikonKod('components/HistoryFilterBar.vue'));

    expect($sektioner)->toContain("icon: 'container'")
        ->and($panelen)->toContain('<UiIcon name="container"')
        ->and($filtret)->toContain('name="container"');
});

it('ritar rutan i paletten och faller tillbaka på slate', function () {
    $komponent = itemikonUtanKommentarer(itemikonKod('components/ItemIcon.vue'));

    expect($komponent)->toMatch('/tile:\s*\{\s*type:\s*Boolean/');
    expect($komponent)->toContain("ICON_COLORS.includes(props.color) ? props.color : 'slate'");

    // Rutan är UiIcons form: samma radius och samma luft, så att den färdiga
    // ikonen och fallbacken har samma mått.
    expect($komponent)->toContain('rounded-control');
    expect($komponent)->toContain('tone.box');
    expect($komponent)->toContain('tone.value.line');

    // Åtta nycklar och hela klasssträngar — Tailwind läser källkoden statiskt
    // och ser inte en klass som byggs med mallsträng.
    foreach (itemikonPaletten() as $farg) {
        expect($komponent)->toContain(
            "{$farg}: { line: 'text-tint-{$farg}', box: 'bg-tint-{$farg}-soft' }",
        );
    }

    // Ingen nionde nyckel i kartan.
    expect(preg_match_all('/^\s{4}([a-z]+):\s*\{ line:/m', $komponent, $traffar))->toBe(8);
    expect($traffar[1])->toBe(itemikonPaletten());
});

it('exporterar iconNames och paletten med åtta nycklar utan rött', function () {
    $modul = itemikonUtanKommentarer(itemikonKod('components/itemIconPresentation.js'));

    // Namnlistan läses ur globbens nycklar, utan sökväg och ändelse, och
    // sorteras. Väljaren i 302 läser samma funktion (ADR-0060 § 2).
    expect($modul)->toMatch('/export function iconNames\s*\(/');
    expect($modul)->toContain('Object.keys(iconModules)');
    expect($modul)->toContain("path.slice(path.lastIndexOf('/') + 1)");
    expect($modul)->toContain("replace(/\\.mjs$/, '')");
    expect($modul)->toMatch('/\.sort\(\)/');

    // Paletten är de åtta nycklarna ur ADR-0060 § 5, i den ordningen.
    expect($modul)->toMatch('/export const ICON_COLORS\s*=\s*\[/');

    preg_match('/export const ICON_COLORS\s*=\s*\[([^\]]*)\]/', $modul, $traff);
    $nycklar = array_values(array_filter(array_map(
        fn (string $nyckel): string => trim($nyckel, " \t\n\r\0\x0B'"),
        explode(',', $traff[1] ?? ''),
    )));

    expect($nycklar)->toBe(itemikonPaletten());
    expect($nycklar)->toHaveCount(8);

    // Paletten saknar rött med flit: `danger` betyder att något har passerat,
    // och en röd ikon användaren valt skulle läsas som en varning.
    foreach ($nycklar as $nyckel) {
        expect($nyckel)->not->toContain('red');
        expect($nyckel)->not->toContain('rose');
    }
});

it('lägger till sexton tint-tokens som undantag från rollnamnen', function () {
    $css = File::get(resource_path('css/app.css'));
    $utanKommentarer = itemikonUtanKommentarer($css);

    preg_match_all('/--color-tint-([a-z]+)(-soft)?\s*:/', $utanKommentarer, $traffar, PREG_SET_ORDER);

    $funna = array_map(
        fn (array $traff): string => '--color-tint-'.$traff[1].($traff[2] ?? ''),
        $traffar,
    );

    // Ätta nycklar gånger två: linjen och den mjuka rutan.
    expect($funna)->toHaveCount(16);
    expect(array_unique($funna))->toHaveCount(16);

    foreach (itemikonPaletten() as $farg) {
        expect($funna)->toContain("--color-tint-{$farg}");
        expect($funna)->toContain("--color-tint-{$farg}-soft");
    }

    // Ingen nionde färg, och ingen röd nyans.
    foreach ($funna as $token) {
        expect($token)->not->toContain('red');
        expect($token)->not->toContain('rose');
    }

    // Undantaget står kommenterat och pekar på beslutet (ADR-0060 § 5).
    expect($css)->toContain('[[ADR-0060 Itemets ikon]] § 5');
    expect($css)->toContain('undantag');
});

it('når lucide bara från ItemIcon och itemIconPresentation', function () {
    $filer = array_values(array_filter(
        File::allFiles(resource_path('js')),
        fn ($fil): bool => in_array($fil->getExtension(), ['js', 'vue'], true),
    ));

    expect($filer)->not->toBeEmpty();

    $tillatna = [
        'components/ItemIcon.vue',
        'components/itemIconPresentation.js',
    ];

    $träffar = [];

    foreach ($filer as $fil) {
        $relativ = str_replace('\\', '/', $fil->getRelativePathname());
        $kod = itemikonUtanKommentarer($fil->getContents());

        if (preg_match('#(?:from\s*|require\(\s*|import\(\s*)[\'"]lucide(?:\/[^\'"]*)?[\'"]|node_modules/lucide#', $kod) === 1) {
            $träffar[] = $relativ;
        }
    }

    // Ingen fil utanför de två når in i paketet. Gränssnittets ikoner bor
    // kvar i den slutna listan (ADR-0060 § 3).
    expect(array_diff($träffar, $tillatna))->toBe([]);

    // Och globben är inte borta: ett tomt prov hade varit tyst, inte grönt.
    expect($träffar)->toContain('components/itemIconPresentation.js');
});

it('lämnar UiIcon och ikonlistan utanför lucide', function () {
    foreach (['components/UiIcon.vue', 'icons/index.js'] as $sokvag) {
        $kod = itemikonUtanKommentarer(itemikonKod($sokvag));

        expect($kod)->not->toContain('import.meta.glob');
        expect(strtolower($kod))->not->toContain('lucide');
    }

    // Den slutna listan är kvar, och UiIcon läser den — itemets ikon är en
    // egen sak och rör inte gränssnittets ordförråd (ADR-0060 § 3).
    expect(itemikonUtanKommentarer(itemikonKod('components/UiIcon.vue')))
        ->toContain("import { icons } from '../icons/index.js'");
    expect(itemikonKod('icons/index.js'))->toContain('export const icons');
});
