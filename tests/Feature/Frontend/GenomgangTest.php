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

/*
 * ---------------------------------------------------------------------------
 * Issue 68b · Tangentbordet och skärmläsaren.
 *
 * Samma sorts källkodsprov som ovan och av samma skäl: det som går att avgöra
 * ur markupen prövas här, det som kräver en webbläsare — de fem flödena, den
 * faktiska kontrasten, att tangentbordet når fram — står i PR-kroppen.
 * ---------------------------------------------------------------------------
 */

/**
 * Varje öppningstag av en sort i en fil, som rå markup.
 *
 * Attributvärdena matchas med citattecken runt om, så ett `>` inuti ett värde
 * inte avslutar taggen i förtid. Det behövs för `:disabled="a === b"` och för
 * `:class="menuOpen ? 'flex' : 'hidden'"`, som båda står i repot.
 *
 * @return array<int, array{rad: int, markup: string}>
 */
function genomgangTaggar(string $kod, string $tag): array
{
    preg_match_all(
        '/<'.$tag.'\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
        PREG_OFFSET_CAPTURE,
    );

    return array_map(fn (array $träff): array => [
        'rad' => substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
        'markup' => $träff[0],
    ], $träffar[0]);
}

it('håller fokusordningen i dokumentordningen — ingen positiv tabindex', function () {
    // Beslut 4. Ett `tabindex="1"` flyttar hela sidans ordning och går sönder
    // nästa gång någon lägger till ett fält; bara 0 och -1 är tillåtna. Provet
    // läser literala värden — ett bundet värde är 0 i PHP och faller igenom,
    // vilket är rätt: `:tabindex="-1"` är tillåtet.
    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        preg_match_all('/:?tabindex="([^"]*)"/', $kod, $träffar, PREG_OFFSET_CAPTURE);

        foreach ($träffar[1] as $träff) {
            $granskade++;

            expect((int) $träff[0])->toBeLessThanOrEqual(0, sprintf(
                '%s:%d sätter tabindex="%s" — bara 0 och -1 är tillåtna',
                $sokvag,
                substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
                $träff[0],
            ));
        }
    }

    expect($granskade)->toBeGreaterThan(10);
});

it('gör varje klickbar yta till en knapp, en länk eller ett inmatningsfält', function () {
    // Beslut 1. En `<div>` med `@click` är en knapp ingen kan nå med tabb.
    // `<input>` står med för radioknappens `@click` i
    // NotificationPreferenceRow, som kompletterar `@change` — fältet är
    // tabbbart och tangentbordet äger det.
    $tillåtna = ['button', 'a', 'Link', 'summary', 'input'];

    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        preg_match_all('/@click[\w.]*=|v-on:click[\w.]*=/', $kod, $träffar, PREG_OFFSET_CAPTURE);

        foreach ($träffar[0] as $träff) {
            $start = strrpos(substr($kod, 0, $träff[1]), '<');

            expect($start)->not->toBeFalse("{$sokvag}: ett @click utanför en tagg");

            preg_match('/^<([a-zA-Z][\w-]*)/', (string) substr($kod, (int) $start), $namn);

            $granskade++;

            expect(in_array($namn[1] ?? '', $tillåtna, true))->toBeTrue(sprintf(
                '%s:%d har @click på en <%s> — den går inte att nå med tabb',
                $sokvag,
                substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
                $namn[1] ?? '?',
            ));
        }
    }

    expect($granskade)->toBeGreaterThan(20);
});

it('ger varje bild en alt-text', function () {
    // Beslut 8. `alt=""` är tillåtet och rätt för det som bara är pynt; en
    // `<img>` helt utan `alt` läser skärmläsaren upp som "bild".
    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        foreach (genomgangTaggar($kod, 'img') as $bild) {
            $granskade++;

            expect($bild['markup'])->toMatch('/\b:?alt=/', sprintf(
                '%s:%d är en <img> utan alt',
                $sokvag,
                $bild['rad'],
            ));
        }
    }

    expect($granskade)->toBeGreaterThan(0);
});

it('knyter varje etikett till sitt fält med for och id', function () {
    // Beslut 3. En `<label>` utan `for` och ett fält utan `id` hör inte ihop:
    // skärmläsaren får ett namnlöst fält och en lös rad text.
    $etiketter = 0;
    $fält = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        foreach (genomgangTaggar($kod, 'label') as $etikett) {
            $etiketter++;

            expect($etikett['markup'])->toMatch('/(^|\s):?for="/', sprintf(
                '%s:%d är en <label> utan for',
                $sokvag,
                $etikett['rad'],
            ));
        }

        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach (genomgangTaggar($kod, $tag) as $kontroll) {
                $fält++;

                expect($kontroll['markup'])->toMatch('/(^|\s):?id="/', sprintf(
                    '%s:%d är ett <%s> utan id',
                    $sokvag,
                    $kontroll['rad'],
                    $tag,
                ));
            }
        }
    }

    expect($etiketter)->toBeGreaterThan(20);
    expect($fält)->toBeGreaterThan(30);
});

it('ger varje sida exakt en h1', function () {
    // Beslut 5. `<h1>` är den som säger var man är, och två av dem säger
    // ingenting. Att rubriknivåerna inte hoppar över kontrolleras för hand i
    // PR:en — det kräver att komponenterna räknas in i sidan de ritas i.
    $sidor = genomgangSidor();

    expect($sidor)->not->toBeEmpty();

    foreach ($sidor as $sokvag => $kod) {
        expect(preg_match_all('/<h1\b/', $kod))->toBe(1, "{$sokvag} har inte exakt en <h1>");
    }
});

it('annonserar flashmeddelanden och felsummeringar', function () {
    // Beslut 6. Ett "sparat" som bara syns är inget besked för den som inte
    // tittar på skärmen. Varje felrad bär antingen `role="alert"` — den
    // fristående summeringen — eller ett id som fältet pekar på med
    // `aria-describedby`, vilket är FormFields väg.
    $flash = genomgangKod()['components/FlashMessage.vue'] ?? null;

    expect($flash)->not->toBeNull()->toContain('role="status"');

    $granskade = 0;

    foreach (genomgangKod() as $sokvag => $kod) {
        foreach (genomgangTaggar($kod, 'p') as $rad) {
            if (stripos($rad['markup'], 'error') === false) {
                continue;
            }

            $granskade++;

            $harRoll = str_contains($rad['markup'], 'role="alert"');
            $harId = preg_match('/(^|\s):?id="/', $rad['markup']) === 1;
            $harBindning = str_contains($kod, 'aria-describedby') || str_contains($kod, 'describedBy');

            expect($harRoll || ($harId && $harBindning))->toBeTrue(sprintf(
                '%s:%d bär ett fel utan role="alert" och utan ett id ett fält kan peka på',
                $sokvag,
                $rad['rad'],
            ));
        }
    }

    expect($granskade)->toBeGreaterThan(10);
});

it('låter webbläsaren äga fokusfällan i varje overlay', function () {
    // Beslut 2. `<dialog>` fångar tabben medan den är öppen och släpper den
    // när den stängs — det är webbläsarens fokusfälla, inte vår. Ett eget
    // `aria-modal` eller en egen tabbhanterare är den fälla som fastnar.
    foreach (genomgangKod() as $sokvag => $kod) {
        expect($kod)->not->toContain('aria-modal', "{$sokvag} bygger en egen modal");

        expect($kod)->not->toMatch('/@keydown\.(tab|esc)/', "{$sokvag} hanterar tangentbordet i en egen fälla");
    }
});

it('stänger bildvisaren med Esc och lämnar fokus tillbaka till miniatyren', function () {
    $kod = genomgangKod()['components/ItemAttachmentSection.vue'] ?? null;

    expect($kod)->not->toBeNull()
        ->toContain('<dialog')
        ->toContain('@close=')
        ->toContain('showModal()')
        ->toContain('?.focus()');
});

it('förmedlar pausat, försenat och återkallat med ett ord och inte bara en nyans', function () {
    // Beslut 7:s andra stycke. Färgen får bära tillståndet bara tillsammans
    // med ett ord — den som inte ser nyansen ska ändå kunna läsa raden.
    $ord = [
        'components/ScheduleListSection.vue' => 'item.schedule.paused',
        'components/OpenOccurrence.vue' => 'item.schedule.occurrence.overdue',
        'components/ItemLoanSection.vue' => 'item.loan.overdue',
        'components/CalendarFeedRow.vue' => 'calendar.row.revoked_badge',
        'components/WebhookEndpointRow.vue' => 'webhook.activate',
        'components/NotificationPreferenceRow.vue' => 'notifications.mode.',
    ];

    foreach ($ord as $fil => $nyckel) {
        expect(genomgangKod()[$fil] ?? null)->not->toBeNull("{$fil} saknas")->toContain($nyckel);
    }
});

it('sätter html lang ur den delade locale-propen även vid klientnavigering', function () {
    // Beslut 9. Rotvyn sätter attributet vid den första laddningen
    // (resources/views/app.blade.php, prövat i SprakTest); app.js håller det
    // färskt när språket byts med en Inertia-visit, vilket är vad
    // profilsidans locale-väljare gör.
    $kod = File::get(resource_path('js/app.js'));

    expect($kod)->toContain('document.documentElement.lang')
        ->toContain("'inertia:navigate'")
        ->toContain('props.locale');
});
