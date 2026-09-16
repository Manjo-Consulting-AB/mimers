<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * Issue 68a · Mobilanpassning — telefonen. Genomgången av vyerna från 51 till
 * 67, se [[Backlog]] och M10 § 68.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Issuen är en genomgång
 * och inte en funktion: det finns ingen ny rutt, ingen ny prop och inget nytt
 * svar att hämta. Det som går att pröva på serversidan är därför formen på
 * källkoden — att varje sida bär en titel, att varje skicka-knapp har ett
 * vänteläge, att varje `router`-anrop sätter en flagga, att `md:` är den enda
 * brytpunkten, att navigeringen går att fälla ihop, och att ingen radåtgärd
 * är mindre än en tumme.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att de fem
 * flödena i "Klart när" faktiskt går att genomföra vid 375 px, att inget
 * hamnar utanför skärmen, och att en långsam uppkoppling visar vänteläget.
 * Källkodsprovet är det som håller reglerna kvar när nästa issue rör en vy;
 * handprovet står i PR-kroppen.
 *
 * Kommentarer rensas bort före varje kontroll, precis som i
 * tests/Feature/Frontend/SprakTest.php: filerna är fulla av svenska docblock
 * som förklarar varför, och en regel som letar efter `min-h-11` ska inte
 * kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `genomgang` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Alla filer under resources/js som renderar gränssnitt, med kommentarer
 * borttagna.
 *
 * @return array<string, string> relativ sökväg → källkod utan kommentarer
 */
function genomgangKod(): array
{
    $filer = [];

    foreach (File::allFiles(resource_path('js')) as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        $filer[$fil->getRelativePathname()] = genomgangUtanKommentarer($fil->getContents());
    }

    return $filer;
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som SprakTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer — docblocken är
 * svenska med flit (AGENTS.md § Språk i koden) och ska inte kunna räknas som
 * markup.
 */
function genomgangUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Varje `<button>`-element i en fil, som rå markup.
 *
 * @return array<int, array{rad: int, markup: string}>
 */
function genomgangKnappar(string $kod): array
{
    preg_match_all('/<button\b.*?<\/button>/s', $kod, $träffar, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $träff): array => [
        'rad' => substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
        'markup' => $träff[0],
    ], $träffar[0]);
}

/**
 * Varje `<li>`-element i en fil, som rå markup.
 *
 * @return array<int, array{rad: int, markup: string}>
 */
function genomgangRader(string $kod): array
{
    preg_match_all('/<li\b.*?<\/li>/s', $kod, $träffar, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $träff): array => [
        'rad' => substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
        'markup' => $träff[0],
    ], $träffar[0]);
}

/**
 * Varje SIDA under pages/ — alltså .vue-filerna, inte modulerna.
 * `pages/Auth/useErrorFocus.js` är en delad hjälpare som bor i katalogen men
 * inte renderar något, och den ska inte bära en sidtitel.
 *
 * @return array<string, string>
 */
function genomgangSidor(): array
{
    return array_filter(
        genomgangKod(),
        fn (string $sokvag): bool => str_starts_with($sokvag, 'pages/') && str_ends_with($sokvag, '.vue'),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * Väntetexten för en skicka-knapp, eller null om knappen saknar den.
 *
 * Två former är tillåtna: en ternary rakt i knappen, eller en `computed` som
 * heter något och definieras i filens script — den senare behövs när
 * etiketten redan är en ternary (skapa/redigera), och en nästlad ternary i en
 * mall är svårare att läsa än en rad i scriptet.
 */
function genomgangVantetext(string $kod, string $markup): ?string
{
    if (preg_match('/common\.pending\.[a-z]+/', $markup, $traff) === 1) {
        return $traff[0];
    }

    if (preg_match('/\{\{\s*([A-Za-z_$][\w$]*)\s*\}\}/', $markup, $traff) !== 1) {
        return null;
    }

    $namn = $traff[1];

    if (preg_match('/const '.preg_quote($namn, '/').' = computed\(.*?common\.pending\.[a-z]+/s', $kod) === 1) {
        return 'computed '.$namn;
    }

    return null;
}

it('sätter en sidtitel på varje sida under pages/', function () {
    $sidor = genomgangSidor();

    expect($sidor)->not->toBeEmpty();

    foreach ($sidor as $sokvag => $kod) {
        expect($kod)->toMatch('/<Head[^>]*\s:title=/s', "{$sokvag} sätter ingen <Head :title>");
    }
});

it('ger varje skicka-knapp ett vänteläge med en text ur lang/', function () {
    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        foreach (genomgangKnappar($kod) as $knapp) {
            if (! str_contains($knapp['markup'], 'type="submit"')) {
                continue;
            }

            $granskade++;

            // Inaktiverad medan svaret hämtas — det är det som gör att samma
            // knapp inte går att trycka två gånger (Beslut 5).
            expect($knapp['markup'])->toMatch('/:disabled=/', sprintf(
                '%s:%d är en skicka-knapp utan :disabled — dubbeltryck är möjligt',
                $sokvag,
                $knapp['rad'],
            ));

            // Och en text som säger att något händer. Etiketten står inte
            // still: en knapp som ser likadan ut medan svaret är på väg är
            // en användare som trycker igen (Beslut 4).
            expect(genomgangVantetext($kod, $knapp['markup']))->not->toBeNull(sprintf(
                '%s:%d saknar väntetext ur lang/ (common.pending.*)',
                $sokvag,
                $knapp['rad'],
            ));

            // 44 px träffyta (Beslut 3).
            expect($knapp['markup'])->toMatch('/\bmin-h-11\b/', sprintf(
                '%s:%d har en träffyta under 44 px',
                $sokvag,
                $knapp['rad'],
            ));
        }
    }

    expect($granskade)->toBeGreaterThan(20);
});

it('sätter ett vänteläge runt varje router-anrop', function () {
    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        // Bara de anrop en ANVÄNDARE utlöser räknas. `router.reload` är
        // undantaget med flit: exportsidan pollar sin egen `exports`-prop med
        // den medan en rad packas (67c), och den pollningen är ingen knapp —
        // raden den hämtar ritar sitt eget `pending`/`running` ur serverns
        // svar, vilket är precis vad Beslut 4 vill se.
        if (preg_match('/router\.(get|post|put|patch|delete)\(/', $kod) !== 1) {
            continue;
        }

        $granskade++;

        // Två former, och båda nollställer flaggan när svaret kommit — även
        // när det blev ett fel:
        //
        //   onStart/onFinish   Inertias egna krokar. Standardformen.
        //   await + finally    Containers/Edit.vue, vars raderingsanrop är
        //                      låst till formen `router.delete(url)` av
        //                      ContainerpapperskorgTest. Ett options-objekt
        //                      där hade tvingat fram en uppluckring av det
        //                      testet (Beslut 7), och uppluckringen är
        //                      dyrare än den ena avvikande formen.
        //
        // `str_contains` och inte `toContain` — Pests `toContain` tar fler
        // nålar, inte ett felmeddelande.
        $vantelage = str_contains($kod, 'onStart:') && str_contains($kod, 'onFinish:')
            || str_contains($kod, 'await router.') && str_contains($kod, '} finally {') && str_contains($kod, '= false');

        expect($vantelage)->toBeTrue(
            "{$sokvag} gör ett router-anrop utan vänteläge (onStart/onFinish eller await i try/finally)"
        );
        expect($kod)->toMatch('/:disabled=/', "{$sokvag} gör ett router-anrop utan att inaktivera kontrollen");
    }

    expect($granskade)->toBeGreaterThan(10);
});

it('använder md: som enda brytpunkt', function () {
    $avvikelser = [];

    foreach (genomgangKod() as $sokvag => $kod) {
        preg_match_all('/(?<![\w-])(sm|lg|xl|2xl|3xl):/', $kod, $träffar, PREG_OFFSET_CAPTURE);

        foreach ($träffar[0] as $träff) {
            $avvikelser[] = sprintf(
                '%s:%d använder %s — genomgången har EN brytpunkt uppåt, md:',
                $sokvag,
                substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
                $träff[0],
            );
        }
    }

    expect($avvikelser)->toBe([]);
});

it('fäller ihop navigeringen på en telefon i alla tre layouterna', function () {
    // De tre ytorna beslutet namnger: AppLayouts toppnavigering,
    // ContainerLayouts sektioner och SettingsLayouts sidlista (Beslut 2).
    // Var och en ska ha en menyknapp som bara syns under `md:` (`md:hidden`),
    // annonsera sitt läge (`aria-expanded`) och peka ut listan den styr
    // (`aria-controls`) — och listan ska stå framme över `md:` (`md:flex`)
    // och vara fälld under den.
    $layouter = [
        'layouts/AppLayout.vue',
        'layouts/ContainerLayout.vue',
        'layouts/SettingsLayout.vue',
    ];

    foreach ($layouter as $layout) {
        $kod = genomgangKod()[$layout] ?? null;

        expect($kod)->not->toBeNull("{$layout} saknas");

        expect(str_contains($kod, 'md:hidden'))->toBeTrue("{$layout} har ingen menyknapp som bara syns på en telefon");
        expect(str_contains($kod, 'aria-controls'))->toBeTrue("{$layout} pekar inte ut listan menyknappen styr");
        expect(str_contains($kod, ':aria-expanded'))->toBeTrue("{$layout} annonserar inte om menyn är öppen");
        expect(str_contains($kod, 'md:flex'))->toBeTrue("{$layout} ställer inte tillbaka listan över md:");
        expect(str_contains($kod, 'min-h-11'))->toBeTrue("{$layout} har länkar under 44 px");
    }
});

it('ger varje knapp och länk i en radlista en träffyta på minst 44 px', function () {
    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        foreach (genomgangRader($kod) as $rad) {
            preg_match_all('/<(button|Link)\b.*?(?:\/>|<\/button>|<\/Link>)/s', $rad['markup'], $kontroller);

            foreach ($kontroller[0] as $kontroll) {
                $granskade++;

                expect($kontroll)->toMatch('/\bmin-h-11\b/', sprintf(
                    "%s:%d har en radåtgärd under 44 px:\n%s",
                    $sokvag,
                    $rad['rad'],
                    trim($kontroll),
                ));
            }
        }
    }

    expect($granskade)->toBeGreaterThan(10);
});

it('skrollar ingen sida i sidled', function () {
    foreach (genomgangKod() as $sokvag => $kod) {
        // En sida som skrollar i sidled är det enklaste provet på att den inte
        // fungerar i en hand. Ett `<code>`-block som skrollade internt är
        // samma fel i miniatyr: det radbryts i stället (`break-all`).
        expect($kod)->not->toMatch('/overflow-x-(auto|scroll)/', "{$sokvag} skrollar i sidled");
    }
});

it('renderar sektionslistorna ur samma moduler som förut', function () {
    // Beslut 2:s andra mening: en ny sektion ska fortsätta fungera utan att
    // någon rör layouten (54 § Beslut 7). Genomgången får inte ha flyttat
    // kunskapen om sektionerna in i layouten.
    foreach (['layouts/ContainerLayout.vue' => 'containerSections', 'layouts/SettingsLayout.vue' => 'settingsSections'] as $layout => $modul) {
        $kod = genomgangKod()[$layout];

        expect(str_contains($kod, "import { {$modul} }"))->toBeTrue("{$layout} importerar inte {$modul}");
        expect(str_contains($kod, 'v-for="section in'))->toBeTrue("{$layout} renderar inte sin lista med v-for");
    }
});

/*
 * Undantaget `data/` i SprakTest gäller användarvänd svensk TEXT. Det här
 * provet gäller form, och där finns inget undantag — men filen nedan är en
 * modul och ingen komponent, så den bär inga knappar. Den är med här för att
 * bevisa att genomgången inte lämnade en enda fil utanför provet.
 */
it('läser varje fil under resources/js', function () {
    $filer = File::allFiles(resource_path('js'));

    /** @var array<int, SplFileInfo> $relevanta */
    $relevanta = array_values(array_filter(
        $filer,
        fn (SplFileInfo $fil): bool => in_array($fil->getExtension(), ['js', 'vue'], true),
    ));

    expect(genomgangKod())->toHaveCount(count($relevanta));
});
