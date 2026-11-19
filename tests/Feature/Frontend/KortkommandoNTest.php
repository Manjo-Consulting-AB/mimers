<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 1401 · Kortkommandot `N`, se [[ADR-0074 Registrering i vyn]] § 7 och
 * tillägget 2026-11-18 samt [[M23 Mobilen och plusknappen]] § 152.
 *
 * **Appens första globala kortkommando.** `N` gör samma sak som plusknappen på
 * sidan man står på — öppnar flikens formulär, öppnar menyn eller följer
 * länken — och det har ingen egen logik för vad som ska skapas: det frågar
 * samma `create`-propp som knappen ritar ur. Att målet är rätt per sida prövas
 * redan på servern i PlusknappTest och PlusknappPaFlikarnaTest; här prövas att
 * skalet läser samma propp och att de tre formerna får var sin väg.
 *
 * **Provet är ett källkodsprov**, som PlusknappTest: lyssnaren, vakterna och
 * de tre vägarna syns bara i källkoden. Kommentarer rensas bort först — en
 * regel som letar efter `isComposing` ska inte kunna nöjas av en mening i ett
 * docblock. Provet läser beteendet i Klart när-punkterna och inte en exakt
 * formulering: vakterna prövas på att de står FÖRE `preventDefault()`, vilket
 * är skillnaden mellan "ignoreras" och "stryks".
 *
 * Hjälparna har prefixet `kortkommando` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som PlusknappTest rensar.
 */
function kortkommandoKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Kroppen av `onKeydown`, och ingenting annat.
 *
 * En vakt som står någon annanstans i filen — i en grannfunktion eller i en
 * kommentar — ska inte kunna nöja provet: det är hanteraren som lyssnaren
 * anropar som avgör vad `N` gör.
 */
function kortkommandoHanterare(string $kod): string
{
    preg_match('/function onKeydown\(event\) \{(.*?)\n\}/s', $kod, $traff);

    return $traff[1] ?? '';
}

/*
 * ---------------------------------------------------------------------------
 * Lyssnaren. Klart när: "N gör samma sak som plusknappen". Skalet lyssnar
 * medan det är monterat och släpper tangenten när det avmonteras.
 * ---------------------------------------------------------------------------
 */

it('lyssnar på window medan skalet är monterat och släpper tangenten vid avmontering', function () {
    $layout = kortkommandoKod('layouts/AppLayout.vue');

    expect($layout)->toContain("window.addEventListener('keydown', onKeydown)")
        ->toContain("window.removeEventListener('keydown', onKeydown)");

    // Lyssnaren läggs i onMounted — skalet står kvar mellan två sidor i
    // Inertia, så den får inte läggas en gång per sida — och tas bort i
    // onBeforeUnmount. En kvarlämnad lyssnare svarar på nästa sidas
    // tangenttryck också, och det är tyst.
    preg_match('#onMounted\(\(\) => \{(.*?)\n\}\)#s', $layout, $montering);
    preg_match('#onBeforeUnmount\(\(\) => \{(.*?)\n\}\)#s', $layout, $avmontering);

    expect($montering[1] ?? '')->toContain("window.addEventListener('keydown', onKeydown)");
    expect($avmontering[1] ?? '')->toContain("window.removeEventListener('keydown', onKeydown)");
});

/*
 * Klart när: "N öppnar formuläret i vyn, öppnar menyn eller följer länken".
 *
 * De tre vägarna är plusknappens tre, och de ligger i samma hanterare:
 *
 *   - `inline` — formuläret i vyn ägs av sidan, så anropet går genom samma
 *     räknare som knappens klick (useInlineCreate, issue 1400).
 *   - `menu` — menyn är skalets yta, och den öppnas med knappen som `trigger`
 *     så att fokus lämnas tillbaka dit vid stängning (issue 152).
 *   - `href` — en `<Link>` i knappen blir en `router.visit()` här.
 *
 * Att hanteraren inte har någon egen logik prövas av att varje väg läser
 * `props.create` — samma propp som knappen ritar ur — och av att vakten för
 * en sida utan mål står före de tre anropen.
 */
it('gör samma sak som plusknappen: formuläret i vyn, menyn eller länken', function () {
    $layout = kortkommandoKod('layouts/AppLayout.vue');
    $knapp = kortkommandoKod('components/CreateButton.vue');
    $hanterare = kortkommandoHanterare($layout);

    expect($hanterare)->not->toBeEmpty('skalet har ingen onKeydown');

    // Formuläret i vyn: samma `request()` som knappens klick anropar.
    expect($hanterare)->toContain("props.create.kind === 'inline'")
        ->toContain('request()');

    expect($layout)->toContain("import { useInlineCreate } from '../composables/useInlineCreate.js'");

    // Menyn: öppnas genom skalets egen väg, med ett element som argument —
    // `openCreateMenu()` utan trigger hade tappat tillbaka-fokuset. Elementet
    // är plusknappen som syns, och den hittas på märket knappen bär.
    expect($hanterare)->toContain("props.create.kind === 'menu'");
    expect($hanterare)->toMatch('/openCreateMenu\([^)]/');

    preg_match('#function visibleCreateButton\(\) \{(.*?)\n\}#s', $layout, $synlig);

    expect($synlig[1] ?? '')->not->toBeEmpty('skalet kan inte hitta plusknappen')
        ->toContain('[data-create-button]')
        ->toContain('getClientRects');

    expect($knapp)->toContain('data-create-button');

    // Länken: knappens `<Link href>` blir en visit.
    expect($hanterare)->toContain('router.visit(props.create.href)');
    expect($layout)->toMatch("/import \{[^}]*router[^}]*\} from '@inertiajs\/vue3'/");

    // Ingen egen logik: alla tre vägarna frågar proppen, och vakten för en
    // sida utan mål står före dem. En sida utan plusknapp har ingen `create`,
    // och då finns det ingenting att trycka på.
    $utanMal = strpos($hanterare, 'if (!props.create)');
    expect($utanMal)->not->toBeFalse('vakten för en sida utan mål saknas');

    foreach (['request()', 'openCreateMenu(', 'router.visit('] as $anrop) {
        $plats = strpos($hanterare, $anrop);
        expect($plats)->not->toBeFalse("hanteraren saknar {$anrop}");
        expect($plats)->toBeGreaterThan($utanMal, "{$anrop} står före vakten för en sida utan mål");
    }
});

/*
 * ---------------------------------------------------------------------------
 * Vakterna. Klart när: "N gör ingenting i ett fält, under IME-inmatning, i en
 * öppen dialog eller på en sida utan plusknapp".
 *
 * Ordningen är beteendet: en vakt returnerar FÖRE `preventDefault()`, alltså
 * stryks inte bokstaven när den betyder något annat. I alla andra fall stryks
 * den, så att `n` inte hamnar någon annanstans.
 * ---------------------------------------------------------------------------
 */

it('ignorerar N i ett fält, under IME, med en dialog öppen och när händelsen redan är hanterad', function () {
    $layout = kortkommandoKod('layouts/AppLayout.vue');
    $hanterare = kortkommandoHanterare($layout);

    expect($hanterare)->not->toBeEmpty('skalet har ingen onKeydown');

    // Bokstaven själv, utan Ctrl, Meta och Alt. Shift är tillåtet — Caps Lock
    // ska inte stänga av kortkommandot — och prövas därför inte.
    expect($hanterare)->toContain("event.key !== 'n'")
        ->toContain("event.key !== 'N'")
        ->toContain('event.ctrlKey')
        ->toContain('event.metaKey')
        ->toContain('event.altKey');

    // Fältet: input, textarea, select och contenteditable.
    expect($hanterare)->toContain('isEditingField(event.target)');
    expect($layout)->toContain('isContentEditable')
        ->toContain("'INPUT'")
        ->toContain("'TEXTAREA'")
        ->toContain("'SELECT'");

    // IME: en sammansatt bokstav är inte en tangenttryckning.
    expect($hanterare)->toContain('event.isComposing');

    // Öppen dialog: skalets egen meny, eller vilket `<dialog open>` som helst
    // — arken och UiDialog är alla `<dialog>` med webbläsarens fokusfälla.
    expect($hanterare)->toContain('menuOpen.value')
        ->toContain("document.querySelector('dialog[open]')");

    // Redan hanterad: någon annan äger händelsen.
    expect($hanterare)->toContain('event.defaultPrevented');

    // Och strykningen står efter alla vakter — det är skillnaden mellan
    // "ignoreras" och "stryks".
    $strypt = strpos($hanterare, 'event.preventDefault()');
    expect($strypt)->not->toBeFalse('hanteraren stryker aldrig bokstaven');

    foreach ([
        'event.defaultPrevented',
        'event.isComposing',
        "event.key !== 'n'",
        'event.ctrlKey',
        'isEditingField(event.target)',
        'menuOpen.value',
        "document.querySelector('dialog[open]')",
    ] as $vakt) {
        $plats = strpos($hanterare, $vakt);

        expect($plats)->not->toBeFalse("vakten {$vakt} saknas i hanteraren");
        expect($plats)->toBeLessThan($strypt, "vakten {$vakt} står efter preventDefault");
    }
});

/*
 * ---------------------------------------------------------------------------
 * Det synliga. Klart när: "Plusknappen har aria-keyshortcuts="N" och en
 * tooltip som nämner kortkommandot".
 * ---------------------------------------------------------------------------
 */

it('bär kortkommandot på plusknappen i både sidhuvudet och flikraden', function () {
    $knapp = kortkommandoKod('components/CreateButton.vue');

    // Alla tre formerna av knappen — menyn, formuläret i vyn och länken — är
    // samma plusknapp och bär samma tre attribut. `data-create-button` är
    // märket skalet hittar den synliga knappen på när menyn ska öppnas.
    expect($knapp)->toContain(':aria-keyshortcuts="shortcut"')
        ->toContain(':title="title"')
        ->toContain("const shortcut = computed(() => t('create.shortcut'))");

    expect(preg_match_all('/data-create-button/', $knapp))->toBe(3, 'alla tre formerna av plusknappen ska bära märket');

    // Flikraden ritar samma komponent, så mobilens plusknapp bär samma
    // kortkommando utan en egen rad.
    expect(kortkommandoKod('components/MobileTabBar.vue'))->toContain('<CreateButton');

    // Tooltipen är etiketten följd av kortkommandot, och båda två är ord ur
    // katalogen: `translate()` skriver nyckeln själv när uppslaget misslyckas,
    // så en nyckel som glöms syns som `create.shortcut` i handen.
    expect(Lang::get('ui.create.label', [], 'en'))->toBe('Add');
    expect(Lang::get('ui.create.shortcut', [], 'en'))->toBe('N');
    expect(Lang::get('ui.create.title', ['label' => 'Add', 'shortcut' => 'N'], 'en'))->toBe('Add (N)');
});
