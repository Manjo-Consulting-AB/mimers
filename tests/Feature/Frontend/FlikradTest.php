<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 100 · Flikraden. Se resources/js/components/UiTabs.vue och
 * [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Issuen bygger en
 * komponent och ingen sida: `ContainerLayout` rörs inte, och ingen rutt
 * renderar flikraden ännu — containerns flikar är issue 101 och itemets är
 * 102. Det som går att pröva på serversidan är därför formen på källkoden:
 * att etiketten och räknaren kommer från anroparen, att tangentbordet följer
 * WAI-ARIA:s mönster för en tablist, att `aria-selected` följer den aktiva
 * fliken, att den aktiva fliken läses ur adressen i stället för ur ett
 * tillstånd i komponenten, och vilken flik som är aktiv när adressen bär både
 * flikens egen parameter och en främmande.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att raden ser
 * ut som `container.jpeg` och `struktur - item.jpeg`, att fokusringen syns,
 * och att en hållen piltangent känns rätt. Handprovet står i PR-kroppen.
 *
 * Fokusringen prövas i tests/Feature/Frontend/DesignsystemTest.php och
 * träffytan i tests/Feature/Frontend/GenomgangTest.php — de två reglerna
 * gäller varje komponent och bor där de redan bor, inte i en kopia här.
 *
 * Kommentarer rensas bort före varje kontroll, precis som i de två filerna
 * ovan: docblocken är svenska med flit (AGENTS.md § Språk i koden), och en
 * regel som letar efter `page.url` ska inte kunna nöjas av en mening i en
 * kommentar.
 *
 * Hjälparna har prefixet `flikrad` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function flikradUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Komponenten med kommentarer borta.
 */
function flikradKod(): string
{
    return flikradUtanKommentarer(File::get(resource_path('js/components/UiTabs.vue')));
}

/**
 * `<script setup>`-blocket, med kommentarer borta.
 */
function flikradScript(string $kod): string
{
    preg_match('#<script setup>(.*?)</script>#s', $kod, $träffar);

    return $träffar[1] ?? '';
}

/**
 * Mallen, med kommentarer borta. Fångas med `.*` och `/s`: mallen är filens
 * sista block, och en girig matchning hit är samma block.
 */
function flikradMall(string $kod): string
{
    preg_match('#<template>(.*)</template>#s', $kod, $träffar);

    return $träffar[1] ?? '';
}

/**
 * En literal sträng som ett mönster, så att provet kan bära en förklaring.
 *
 * Pest:s `toContain()` tar emot FLERA nålar och ingen förklaring: en andra
 * sträng letas efter i stället för att skrivas ut, och provet faller på fel
 * sak. `toMatch()` tar en förklaring, och den som läser ett fallande prov ska
 * få veta VARFÖR och inte bara ATT.
 */
function flikradText(string $text): string
{
    return '/'.preg_quote($text, '/').'/';
}

/**
 * Varje öppningstag av en sort i mallen, som rå markup — samma form som
 * genomgangTaggar i GenomgangTest.
 *
 * Attributvärdena matchas med citattecken runt om, och det är inte en
 * prydnad: `:ref="(element) => ..."` bär en PIL, och en regel som läste
 * `[^>]*` hade stannat vid den och trott att fliken saknade både roll och
 * tangentbord. Provet hade då fallit på sin egen läsning i stället för på
 * komponenten.
 *
 * @return array<int, string>
 */
function flikradTaggar(string $kod, string $tag): array
{
    preg_match_all(
        '/<'.$tag.'\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
    );

    return $träffar[0];
}

it('renderar etikett och valfri räknare', function () {
    $kod = flikradKod();
    $mall = flikradMall($kod);

    expect($mall)->not->toBe('', 'UiTabs saknas eller har ingen mall');

    // Den första formen: en flik med etikett och ingenting mer. Texten kommer
    // ur fliken anroparen skickade in — `{{ tab.label }}` och ingen literal —
    // och det är hela skälet att komponenten inte kan bära en sträng
    // (SprakTest). En flik som ritar en etikett ur proppen är skillnaden
    // mellan en flik och en tom ruta.
    expect($mall)->toMatch(
        flikradText('{{ tab.label }}'),
        'fliken ritar ingen etikett ur proppen',
    );

    // Den andra formen: en flik med en räknare. Den bärs av UiBadge i dess
    // neutrala tillstånd (issue 99) — flikens egen roll är aktiv eller inte,
    // och räknaren är varken ett OK eller ett fel, så `state` sätts inte.
    expect($kod)->toMatch(
        flikradText("import UiBadge from './UiBadge.vue'"),
        'räknaren bärs inte av UiBadge',
    );

    expect($mall)->toMatch(
        '#<UiBadge\b[^>]*>\s*\{\{ tab\.count \}\}\s*</UiBadge>#s',
        'fliken ritar ingen räknare ur proppen',
    );

    // Och de två formerna skiljs av om räknaren FINNS. Vakten frågar efter
    // `null` och inte efter ett sanningsvärde: en flik med noll dokument har
    // ett tal, och `v-if="tab.count"` hade tystat just den fliken. Noll är
    // ett svar, inte ett tomrum.
    expect($mall)->toMatch(
        '#<UiBadge\b[^>]*\bv-if="[^"]*tab\.count[^"]*"#s',
        'brickan ritas utan att fråga om räknaren finns',
    );

    expect($mall)->not->toMatch(
        '/v-if="tab\.count"/',
        'vakten läser räknaren som ett sanningsvärde — en nolla hade försvunnit',
    );
});

it('flyttar mellan flikar med piltangenter och lämnar raden med Tab', function () {
    $kod = flikradKod();
    $script = flikradScript($kod);
    $mall = flikradMall($kod);

    expect($script)->not->toBe('', 'UiTabs har inget <script setup>');

    // Raden tar emot tangentbordet på fliken, inte på <ul>: det är fliken som
    // har fokus, och en lyssnare på föräldern hade fått leta upp den.
    $flikar = flikradTaggar($mall, 'Link');

    expect(count($flikar))->toBe(1, 'UiTabs ritar ingen <Link> — fliken är ingen flik');

    expect($flikar[0])->toMatch(flikradText('@keydown='), 'fliken tar inte emot tangentbordet');

    // Båda riktningarna, och fokus flyttas — inte markeringen. `focus()` är
    // hela verbet: en flik är en adress, och att aktivera den hade skickat en
    // förfrågan per nedtryckning.
    expect($script)->toMatch(flikradText('ArrowRight'), 'piltangenten höger hanteras inte');
    expect($script)->toMatch(flikradText('ArrowLeft'), 'piltangenten vänster hanteras inte');
    expect($script)->toMatch(flikradText('.focus()'), 'piltangenterna flyttar inget fokus');

    // Och `Tab` hanteras INTE. Den som fångar Tab fångar användaren: raden
    // hade blivit en fälla i stället för en rad man passerar. Frågan ställs
    // mot scriptet, där en avlyssnad Tab hade stått som en sträng.
    expect($script)->not->toMatch(
        flikradText("'Tab'"),
        'flikraden fångar Tab och släpper inte ut användaren',
    );

    // `Tab` lämnar raden därför att bara EN flik är tabbbar: roving tabindex.
    // Värdet är bundet — `0` för den aktiva, `-1` för de andra — och ett
    // konstant `tabindex="0"` på varje flik hade tvingat tangentbordet genom
    // hela raden för att komma förbi den.
    preg_match('/:tabindex="([^"]*)"/', $mall, $träff);
    $tabindex = $träff[1] ?? '';

    expect($tabindex)->not->toBe('', 'flikarna bär ingen bunden tabindex');
    expect($tabindex)->toMatch('/\b0\b/', 'ingen flik är tabbbar');
    expect($tabindex)->toMatch('/-1/', 'alla flikar är tabbbara — Tab lämnar då inte raden');
});

it('sätter aria-selected på den aktiva fliken', function () {
    $kod = flikradKod();
    $mall = flikradMall($kod);

    // Rollerna först: `aria-selected` betyder ingenting utan en tablist. Utan
    // dem är flikraden en lista av länkar, och skärmläsaren läser varken
    // tillståndet eller antalet flikar.
    expect($mall)->toMatch(flikradText('role="tablist"'), 'raden är ingen tablist');

    $flikar = flikradTaggar($mall, 'Link');

    expect(count($flikar))->toBe(1, 'UiTabs ritar ingen <Link> — fliken är ingen flik');

    expect($flikar[0])->toMatch(
        flikradText('role="tab"'),
        'flikarna är inga flikar för en skärmläsare',
    );

    expect($mall)->toMatch(
        flikradText('role="presentation"'),
        '<li> ligger mellan tablist och tab utan roll',
    );

    // Attributet sitter på VARJE flik med sitt värde bundet. En `v-if` hade
    // lämnat de andra utan tillstånd, och en skärmläsare som frågar "är den
    // vald?" hade fått tystnad i stället för ett nej.
    preg_match('/:aria-selected="([^"]*)"/', $mall, $träff);
    $uttryck = $träff[1] ?? '';

    expect($uttryck)->not->toBe('', 'aria-selected binds inte alls');
    expect($uttryck)->toMatch(
        flikradText("'false'"),
        'aria-selected bär inget nej för de ovalda flikarna',
    );

    // Och frågan är densamma som den synliga markeringen ställer. Två villkor
    // för samma sak glider isär, och då lyser en flik medan skärmläsaren säger
    // att en annan är vald.
    preg_match('/(\w+)\(tab\)/', $uttryck, $namn);

    expect($namn[1] ?? null)->not->toBeNull('aria-selected frågar inte om fliken är aktiv');

    expect($mall)->toMatch(
        '/:class="\s*'.preg_quote($namn[1], '/').'\(tab\)/s',
        'den synliga markeringen frågar inte samma sak som aria-selected',
    );
});

it('har den aktiva fliken i adressen och inte i komponentens tillstånd', function () {
    $kod = flikradKod();
    $script = flikradScript($kod);

    // Adressen är källan. Samma skäl som filtret i issue 59a § Beslut 1 och
    // förekomsten i issue 95: en flik man kan länka till är en flik man kan
    // dela, och en som bara bor i minnet försvinner vid en omladdning.
    expect($script)->toMatch(flikradText('usePage('), 'komponenten läser inte sidans adress');
    expect($script)->toMatch(flikradText('page.url'), 'komponenten läser inte adressen');

    // Och inget tillstånd håller valet. Provet läser det `aria-selected`
    // faktiskt pekar på i stället för att svartlista namn: binder uttrycket ett
    // namn som deklarerats med `ref(` eller `reactive(` är valet komponentens
    // eget, och då står fliken inte i adressen — den står i minnet. En
    // namnlista hade missat varje namn någon råkar välja; det här fångar
    // tillståndet oavsett vad det heter.
    preg_match_all('/const (\w+) = (?:ref|reactive)\(/', $script, $träffar);

    $tillstand = $träffar[1];

    preg_match('/:aria-selected="([^"]*)"/', flikradMall($kod), $selekt);
    $uttryck = $selekt[1] ?? '';

    expect($uttryck)->not->toBe('', 'aria-selected binds inte alls');

    foreach ($tillstand as $namn) {
        expect($uttryck)->not->toMatch(flikradText($namn), sprintf(
            'aria-selected läses ur %s — valet ska komma ur adressen',
            $namn,
        ));
    }

    // Positivt prov, och det som håller regeln levande: adressen läses i en
    // `computed` och jämförs med flikarnas egna adresser. Utan den är
    // `page.url` bara ett namn som nämns i förbigående.
    expect($script)->toMatch('/computed\(.*?page\.url/s', 'adressen jämförs aldrig med flikarna');
    expect($script)->toMatch('/tab\.href/', 'flikarnas adresser jämförs aldrig med sidans');
});

/*
 * Arkitektsvaret på issue 100 skärpte träffen mot adressen, och det här provet
 * är det som håller skärpningen. Den gamla regeln — hela adressen querysträng
 * inräknad, annars längsta sökväg — höll inte för en flik som bär BÅDE sin egen
 * parameter och en främmande:
 *
 *   /containers/X/items/Y?tab=relations&sort=name
 *
 * Exaktträffen missar (`&sort=name` står inte i någon fliks href), och
 * fallbacken jämför sökvägar som itemets sex flikar delar — den längsta träffen
 * är ett sexvägars oavgjort som den första fliken vinner. Översikten hade alltså
 * lyst medan relationsfliken stod tom, och `sort` är issue 59a:s filter och inte
 * flikens.
 *
 * Regeln är i stället två villkor: flikens sökväg är lika med adressens eller
 * ett prefix av den vid en segmentgräns, OCH varje parameter i flikens egen
 * `href` har samma värde i adressen. Bland de matchande vinner flest matchade
 * parametrar, sedan längst sökväg, sedan den första i listan.
 *
 * Källkodsprov som de andra i filen: regeln bor i en `computed` i komponenten,
 * och ingen rutt renderar flikraden ännu — containerns rad är issue 101 och
 * itemets 102. Det som går att pröva är därför att de två villkoren och
 * rangordningen FINNS i källkoden, och att den gamla regeln inte gör det.
 */
it('håller fliken aktiv när adressen bär både flikens parameter och en främmande', function () {
    $kod = flikradKod();
    $script = flikradScript($kod);

    expect($script)->not->toBe('', 'UiTabs har inget <script setup>');

    // Båda sidorna läses LIKADANT, och det är den ena regeln: adressen och
    // flikens href delas upp i sökväg och parametrar av samma hjälpare. En
    // jämförelse som läste den ena av dem rått kunde inte skilja `sort` från
    // `tab`. Hjälparen hittas på att den används på båda — namnet är fritt.
    preg_match_all('/function (\w+)\(/', $script, $träffar);

    $delare = array_values(array_filter(
        $träffar[1],
        fn (string $namn): bool => str_contains($script, "{$namn}(page.url)")
            && str_contains($script, "{$namn}(tab.href)"),
    ));

    expect($delare)->not->toBeEmpty(
        'adressen och flikens href delas inte upp på samma sätt — då går flikens parametrar inte att skilja från adressens',
    );

    // Och uppdelningen tar querysträngen med sig. Utan den är `?tab=relations`
    // och `?tab=overview` samma sökväg, alltså samma flik.
    expect($script)->toMatch(flikradText("split('?')"), 'adressen delas inte i sökväg och querysträng');
    expect($script)->toMatch(flikradText('URLSearchParams'), 'parametrarna läses inte ur adressen');

    // Villkor 1: prefixet prövas vid en SEGMENTGRÄNS. Utan snedstrecket hade
    // `/containers/X` matchat `/containers/XYZ`, och översikten hade lyst på en
    // främmande container.
    expect($script)->toMatch(
        '/\$\{tab\.path\}\//',
        'prefixet prövas utan segmentgräns — /containers/X matchar då /containers/XYZ',
    );

    // Villkor 2, och riktningen är hela poängen: loopen går över FLIKENS
    // parametrar och slår upp dem i adressen. Den som loopar över adressens
    // parametrar kräver i stället att fliken bär varje parameter adressen råkar
    // ha, och då släcker `sort` fliken man står på.
    expect($script)->toMatch(
        '/of tab\.params\)/',
        'flikens egna parametrar läses inte — främmande parametrar kan då inte ignoreras',
    );

    expect($script)->toMatch(
        '/params\.get\(/',
        'ingen parameter slås upp i adressen — flikens parametrar jämförs inte med något',
    );

    // Och den gamla regeln är borta. `tab.href === url` är hela adressen mot
    // flikens, och den faller på `?tab=relations&sort=name`: ingen fliks href
    // bär `sort`, så ingen flik matchar alls.
    expect($script)->not->toMatch(
        '/tab\.href\s*==|==\s*tab\.href/',
        'hela adressen jämförs med flikens href — en främmande parameter släcker då fliken',
    );

    // Rangordningen: flest matchade parametrar FÖRE längst sökväg. Båda behövs
    // och i den ordningen — `?tab=relations` slår översikten på en matchad
    // parameter, och översikten slår undersidorna på längst sökväg.
    expect($script)->toMatch(
        '/matched\s*>\s*\w+\.matched.*?path\.length/s',
        'rangordningen jämför sökvägen före antalet matchade parametrar',
    );
});
