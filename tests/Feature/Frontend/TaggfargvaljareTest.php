<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 165 · Färgväljaren: pricken i taggformuläret blir en knapp som öppnar
 * ett HSV-hjul, se resources/js/components/TagColorField.vue,
 * resources/js/components/ColorWheel.vue och
 * resources/js/support/colorWheel.js.
 *
 * **Källkodsprov, som i TaggvyTest och GenomgangTest.** Repot har inget
 * JS-testverktyg, så hjulets struktur prövas genom att läsa filerna som text:
 * att pricken är en knapp med rätt attribut, att hjulet ritas i en dialog med
 * nyans-, mättnads- och ljushetsdelar, och att stängningen har tre vägar.
 * Det som INTE går att avgöra så är hur hjulet ser ut och hur det känns att
 * dra i — det står i PR-kroppens `Frågor och antaganden` och måste ses i en
 * webbläsare före merge.
 *
 * **Omvandlingen prövas på riktigt.** colorWheel.js är rena funktioner utan
 * DOM, och då går de att köra i node — samma väg som BilagevyTest och
 * ItemdetaljTest använder för attachmentPresentation.js. Att `hsvToHex` och
 * `hexToHsv` är varandras invers är ett påstående om tal och inte om markup,
 * och det prövas med tal: rundturen över en rad färger ska ge tillbaka exakt
 * den färg den började i.
 *
 * **Gränsen mot servern rörs inte.** Formatet är `#rrggbb` med gemener eller
 * `null`, precis som StoreTagRequest och UpdateTagRequest redan säger
 * (Beslut 4). Ingen `hsl()`, ingen kortform `#rgb`, ingen ändrad validering —
 * det prövas här som ett mönster på varje svar hjulet kan ge.
 *
 * Hjälparna har prefixet `taggfarg` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden för en fil under resources/js, med kommentarer borta. Docblocken
 * är svenska med flit och ska inte kunna nöja ett prov som letar efter kod.
 */
function taggfargKod(string $relativSokvag): string
{
    $sokvag = resource_path('js/'.$relativSokvag);

    expect(File::exists($sokvag))->toBeTrue("resources/js/{$relativSokvag} saknas");

    $kod = File::get($sokvag);

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Varje `<button>`-element i en fil, som rå markup.
 *
 * @return array<int, string>
 */
function taggfargKnappar(string $kod): array
{
    preg_match_all('/<button\b.*?<\/button>/s', $kod, $traffar);

    return $traffar[0];
}

/**
 * Knappen som bär $nal, eller '' när ingen gör det.
 */
function taggfargKnapp(string $kod, string $nal): string
{
    foreach (taggfargKnappar($kod) as $knapp) {
        if (str_contains($knapp, $nal)) {
            return $knapp;
        }
    }

    return '';
}

/**
 * Kör ett uttryck mot resources/js/support/colorWheel.js i node och
 * returnerar det som skrivs på stdout — samma teknik som bilagevyKör() i
 * BilagevyTest.php. Modulen importerar varken Vue eller något ur
 * webbläsaren, så den går att köra utan en webbläsare.
 */
function taggfargKor(string $anrop): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/support/colorWheel.js'), JSON_UNESCAPED_SLASHES).').href);',
        "process.stdout.write(String({$anrop}));",
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

/*
 * Klart när: pricken i TagColorField är en knapp med aria-haspopup,
 * aria-expanded och en tillgänglig etikett ur t(...).
 *
 * Träffytan är 44 px som kryssrutan bredvid (Beslut 3), och knappen är det
 * ENDA stället som sätter `open` — hjulet har ingen annan dörr in.
 */
it('gör pricken till en knapp som öppnar färgväljaren', function () {
    $falt = taggfargKod('components/TagColorField.vue');

    $prick = taggfargKnapp($falt, 'aria-haspopup');

    expect($prick)->not->toBe('', 'TagColorField ritar ingen prick med aria-haspopup — hjulet går inte att öppna');

    expect($prick)->toContain('type="button"')
        ->toContain('aria-haspopup="dialog"')
        ->toContain(':aria-expanded="open"')
        ->toContain("t('container.tags.color_open')")
        ->toMatch('/\bmin-h-11\b/');

    // Hjulet ritas bara medan det är öppet, och öppnas av pricken och av
    // ingenting annat.
    expect($falt)->toContain('<ColorWheel')
        ->toContain('v-if="open"')
        ->toContain(':trigger="dot"');

    expect(substr_count($falt, 'open = true'))->toBe(1)
        ->and($prick)->toContain('open = true');
});

/*
 * Klart när: hjulet renderas i en popover som öppnas av pricken och har ett
 * nyansområde, ett mättnadsområde och ett ljushetsreglage.
 *
 * Hjulet är två CSS-gradienter och ingen canvas (Beslut 2): nyansringen är en
 * `conic-gradient` byggd ur `hsvToHex`, alltså ur samma rena funktion som
 * värdet kommer ur, och vit över mitten är en `radial-gradient`. Att blanda
 * in vitt i RGB är exakt vad mättnad är i HSV, så överlägget stämmer utan en
 * enda räknad pixel.
 *
 * Markörens plats räknas ur samma två tal med `cos` och `sin`, och vinkeln är
 * den gradienten börjar i — glider de isär pekar markören på en annan färg än
 * den ett klick ger.
 */
it('ritar ett färghjul med ljushetsreglage', function () {
    $hjul = taggfargKod('components/ColorWheel.vue');

    // Popovern: en dialog, alltså samma form som UiSheet och ParentPicker.
    expect($hjul)->toContain('<dialog')
        ->toContain('showModal()');

    // Nyansområdet runt kanten och mättnadsområdet inåt.
    expect($hjul)->toContain('conic-gradient')
        ->toContain('radial-gradient')
        ->toContain('hsvToHex(angle, 100, 100)')
        ->toContain('Math.cos(')
        ->toContain('Math.sin(');

    // Ljushetsreglaget under hjulet: ett vanligt reglage, och det tredje
    // talet kommer därifrån.
    expect($hjul)->toContain('type="range"')
        ->toContain('min="0"')
        ->toContain('max="100"')
        ->toContain('@input="onLightness"')
        ->toContain("t('container.tags.color_lightness')");

    // Och fältet som använder det ritar hjulet i sin tur.
    expect(taggfargKod('components/TagColorField.vue'))->toContain("from './ColorWheel.vue'");
});

/*
 * Klart när: hjulet skriver `#rrggbb` med gemener via update:modelValue,
 * aldrig något annat format.
 *
 * Provet är tvådelat med flit. Komponentdelen visar att värdet går genom
 * `hsvToHex` och ut på `update:modelValue`; node-delen visar att funktionen
 * faktiskt svarar sex gemena hexsiffror för varje hörn av skalan — inklusive
 * de tal en dragning kan ge: nyansen viras runt ett varv och negativa
 * nyanser blir sina positiva motsvarigheter.
 */
it('skriver valet som sex hexsiffror med gemener', function () {
    $hjul = taggfargKod('components/ColorWheel.vue');

    expect($hjul)->toContain('hsvToHex(')
        ->toContain("emit('update:modelValue', sent)");

    $fall = [[0, 100, 100], [217, 76, 96], [359, 50, 50], [0, 0, 0], [0, 0, 100], [720, 120, 80], [-30, 100, 100]];

    $svar = taggfargKor('JSON.stringify('.json_encode($fall).'.map((f) => m.hsvToHex(f[0], f[1], f[2])))');

    $hexar = json_decode($svar, true);

    expect($hexar)->toHaveCount(count($fall));

    foreach ($hexar as $hex) {
        // Sex gemena hexsiffror och ingenting annat: mönstret stänger ute
        // `hsl()`, `rgb()`, kortformen `#rgb` och versaler.
        expect($hex)->toMatch('/^#[0-9a-f]{6}$/');
    }
});

/*
 * Klart när: colorWheel.js exporterar de rena funktionerna för HSV → hex och
 * hex → HSV, och ingen av dem skriver till DOM.
 *
 * Rundturen är det som bevisar att de två hör ihop: en färg in ska ge samma
 * färg tillbaka. `hexToHsv` lämnar nyansen som flyttal av just det skälet —
 * avrundade man till hela grader först hade markören hoppat upp till två
 * kanalsteg varje gång ett värde lästes tillbaka.
 */
it('håller färgomvandlingen i ren kod utan DOM', function () {
    $modul = taggfargKod('support/colorWheel.js');

    expect($modul)->toContain('export function hsvToHex(')
        ->toContain('export function hexToHsv(');

    // Var för sig och inte i en kedja: ett `->not` mitt i en kedja tappar
    // typen och fäller phpstan, och en array hade varit "innehåller inte
    // alla" — alltså sant så snart ett av orden saknas.
    expect($modul)->not->toContain('document');
    expect($modul)->not->toContain('window');
    expect($modul)->not->toContain('import ');

    // Hörnen av skalan, och de tre kanalerna för sig.
    expect(taggfargKor('m.hsvToHex(0, 100, 100)'))->toBe('#ff0000')
        ->and(taggfargKor('m.hsvToHex(60, 100, 100)'))->toBe('#ffff00')
        ->and(taggfargKor('m.hsvToHex(120, 100, 100)'))->toBe('#00ff00')
        ->and(taggfargKor('m.hsvToHex(180, 100, 100)'))->toBe('#00ffff')
        ->and(taggfargKor('m.hsvToHex(240, 100, 100)'))->toBe('#0000ff')
        ->and(taggfargKor('m.hsvToHex(300, 100, 100)'))->toBe('#ff00ff')
        ->and(taggfargKor('m.hsvToHex(0, 0, 100)'))->toBe('#ffffff')
        ->and(taggfargKor('m.hsvToHex(0, 0, 0)'))->toBe('#000000');

    // Rundturen: samma färg ut som in, för varje färg.
    $farger = ['#ff0000', '#00ff00', '#0000ff', '#ffff00', '#00ffff', '#ff00ff',
        '#ffffff', '#000000', '#3b82f6', '#ef4444', '#15803d', '#0a0b0c', '#808080'];

    $rundtur = taggfargKor(
        'JSON.stringify('.json_encode($farger)
        .'.map((c) => { const { h, s, v } = m.hexToHsv(c); return m.hsvToHex(h, s, v); }))'
    );

    expect(json_decode($rundtur, true))->toBe($farger);

    // Versaler läses som gemener: fältet får skrivas hur användaren vill,
    // servern normaliserar, och hjulet ska hamna rätt oavsett.
    expect(taggfargKor('JSON.stringify(m.hexToHsv("#3B82F6"))'))
        ->toBe(taggfargKor('JSON.stringify(m.hexToHsv("#3b82f6"))'));
});

/*
 * Klart när: en giltig hex i textfältet flyttar markören; en ogiltig lämnar
 * hjulet som det är (Beslut 5).
 *
 * Regeln är `hexToHsv`s: den svarar `null` på allt som inte är sex
 * hexsiffror, och `null` betyder "rör inte hjulet" — inte "sätt en
 * standardfärg". Listan med ogiltiga värden är samma som TaggvyTest skickar
 * till servern, så klienten och StoreTagRequest är ense om vad som är
 * giltigt.
 */
it('följer textfältet bara när hexvärdet är giltigt', function () {
    $hjul = taggfargKod('components/ColorWheel.vue');

    expect($hjul)->toContain('watch(')
        ->toContain('hexToHsv(value)')
        ->toContain('if (parsed === null)')
        ->toContain('hsv.value = parsed');

    foreach (['3b82f6', '#3b8', '#zzzzzz', 'röd', '', '#12345g'] as $ogiltig) {
        expect(taggfargKor('String(m.hexToHsv('.json_encode($ogiltig).'))'))
            ->toBe('null', "hexToHsv({$ogiltig}) borde vara null");
    }

    foreach (['#3b82f6', '#3B82F6', '#ef4444', '#000000', '#ffffff'] as $giltig) {
        expect(taggfargKor('String(m.hexToHsv('.json_encode($giltig).') !== null)'))
            ->toBe('true', "hexToHsv({$giltig}) borde ge ett värde");
    }
});

/*
 * Klart när: är "Ingen färg" ikryssad är pricken disabled och hjulet kan inte
 * öppnas (Beslut 6).
 *
 * Det är Beslut 8 ur issue 56a om igen: `null` är ett svar och inte ett tomt
 * fält, och ett hjul som öppnades på det svaret hade behövt en standardfärg
 * att visa. Vägen till en färg är därför två steg — kryssa av, välj — och
 * avkryssningen sätter fortfarande `''` och inte en färg.
 */
it('kan inte öppna hjulet när ingen färg är vald', function () {
    $falt = taggfargKod('components/TagColorField.vue');

    $prick = taggfargKnapp($falt, 'aria-haspopup');

    expect($prick)->toContain(':disabled="modelValue === null"');

    // Kryssrutan sätter `''` — inte en färg — och textfältet är inte heller
    // det som sätter en.
    expect($falt)->toContain("emit('update:modelValue', \$event.target.checked ? null : '')")
        ->toContain(':disabled="modelValue === null"');

    // Och pricken är ENDA vägen in: sätter ingenting annat `open`, kan hjulet
    // inte öppnas på en tagg utan färg.
    expect(substr_count($falt, 'open = true'))->toBe(1);
});

/*
 * Klart när: Escape, klick utanför och "Klar" stänger, och fokus återgår till
 * pricken (Beslut 7).
 *
 * Escape är webbläsarens och inte vår: en `<dialog>` som öppnats med
 * `showModal()` fyrar `close` när Esc trycks, precis som i UiSheet och
 * ParentPicker (issue 68b § Beslut 2). Ingen egen tangenthanterare och inget
 * `aria-modal` — den fällan är den som fastnar. Alla tre vägarna går genom
 * `close()` och landar i `onClosed`, som lämnar tillbaka fokus till
 * `trigger`, alltså pricken.
 */
it('stänger med Escape, klick utanför och Klar och lämnar tillbaka fokus', function () {
    $hjul = taggfargKod('components/ColorWheel.vue');

    expect($hjul)->toContain('@close="onClosed"')
        ->toContain('function onClosed()')
        ->toContain("emit('close')")
        ->toContain('props.trigger?.focus()');

    // "Klar" stänger och behåller värdet — den är ingen avbrytning.
    $klar = taggfargKnapp($hjul, "t('container.tags.color_done')");

    expect($klar)->not->toBe('', 'ColorWheel har ingen Klar-knapp');
    expect($klar)->toContain('type="button"')
        ->toContain('@click="close"')
        ->toMatch('/\bmin-h-11\b/');

    // Tryckytan utanför panelen, som i UiSheet och ParentPicker: en knapp och
    // ingen `<div>` med `@click`, eftersom dialogen täcker hela skärmen.
    $utanfor = taggfargKnapp($hjul, "t('container.tags.color_close')");

    expect($utanfor)->not->toBe('', 'ColorWheel stänger inte på ett tryck utanför panelen');
    expect($utanfor)->toContain('@click="close"')
        ->toContain('absolute inset-0')
        ->toMatch('/\bmin-h-11\b/');
});

/*
 * Klart när: hjulet tar emot pekinmatning och tangentbord — pointerdown/
 * pointermove, setPointerCapture, touch-action: none och piltangenter
 * (Beslut 7 och 8).
 *
 * `setPointerCapture` är det som gör en dragning till en dragning: utan den
 * tappar hjulet pekaren så fort den lämnar cirkeln. `touch-action: none` är
 * det som hindrar sidan från att rulla i stället, och den står som ett
 * stilattribut för att gå att läsa rakt av.
 *
 * Panelen är full bredd under `md:` och hjulet är 192 px, alltså ryms det på
 * 360 px — men det är layout, och den verkliga kontrollen är webbläsarens.
 * Det står i PR-kroppen.
 */
it('kan styras med pekskärm och tangentbord', function () {
    $hjul = taggfargKod('components/ColorWheel.vue');

    expect($hjul)->toContain('@pointerdown="onPointerDown"')
        ->toContain('@pointermove="onPointerMove"')
        ->toContain('@pointerup="onPointerUp"')
        ->toContain('@pointercancel="onPointerUp"')
        ->toContain('setPointerCapture(')
        ->toContain('releasePointerCapture(')
        ->toContain('style="touch-action: none"');

    expect($hjul)->toContain('@keydown="onKeydown"')
        ->toContain("event.key === 'ArrowLeft'")
        ->toContain("event.key === 'ArrowRight'")
        ->toContain("event.key === 'ArrowUp'")
        ->toContain("event.key === 'ArrowDown'")
        ->toContain('event.preventDefault()');

    // Hjulet går att nå med tabb och står först i dialogen.
    expect($hjul)->toContain('tabindex="0"')
        ->toContain('wheelElement.value?.focus()');

    // Och det ryms på en telefon: panelen är full bredd under md:, hjulet är
    // en fast kvadrat.
    expect($hjul)->toContain('md:w-80')
        ->toContain('h-48 w-48');
});

/*
 * Klart när: alla nya texter finns i lang/en/ui.php under container.tags, och
 * ingen svensk eller engelsk text står hårdkodad i komponenterna.
 *
 * Nycklarna läses ur källkoden i stället för att räknas upp här, som i
 * SprakTest: byter en mening namn i komponenten följer provet med. Och
 * textnoden — allt mellan två taggar som inte är en interpolation — ska vara
 * tom, för en rubrik skriven rakt i mallen är samma fel som en sträng i en
 * konstant ([[ADR-0034 Engelska vid lansering]]).
 */
it('hämtar alla texter ur översättningsfilen', function () {
    foreach (['components/TagColorField.vue', 'components/ColorWheel.vue'] as $relativ) {
        $kod = taggfargKod($relativ);

        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $kod, $traffar);

        expect($traffar[1])->not->toBeEmpty("{$relativ} slår inte upp en enda text");

        foreach (array_unique($traffar[1]) as $nyckel) {
            expect(Lang::get("ui.{$nyckel}", [], 'en'))
                ->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas i lang/en/ui.php");
        }

        preg_match('#<template>(.*)</template>#s', $kod, $mall);

        $text = (string) preg_replace('/"[^"]*"/', '""', $mall[1] ?? '');
        $text = (string) preg_replace('/<[^>]*>/', '', $text);
        $text = (string) preg_replace('/\{\{.*?\}\}/s', '', $text);

        expect(trim($text))->toBe('', "{$relativ} bär hårdkodad text: ".trim($text));
    }

    $en = require lang_path('en/ui.php');

    foreach (['color_open', 'color_heading', 'color_wheel', 'color_lightness', 'color_done', 'color_close'] as $nyckel) {
        expect($en['container']['tags'][$nyckel])->not->toBe('', "container.tags.{$nyckel} är tom");
    }

    // Texten hör till taggformuläret och inte till skalet: `create.close` är
    // arkets ord, och en egen nyckel här gör att de kan ändras var för sig.
    expect($en['container']['tags']['color_close'])->toBe('Close');
});

/*
 * Klart när: TagCreateForm.vue och TagRow.vue är oförändrade och använder
 * fortfarande TagColorField med samma props.
 *
 * Det är hela poängen med att bygga hjulet i fältet: de två formulären har
 * ingen åsikt om hur en färg väljs, bara om att det finns ett värde. Fältets
 * tre props är desamma, och `v-model` betyder samma sak som förut.
 */
it('kräver inga ändringar i formulären som använder fältet', function () {
    foreach (['components/TagCreateForm.vue', 'components/TagRow.vue'] as $relativ) {
        $brukare = File::get(resource_path('js/'.$relativ));

        expect($brukare)->toContain('<TagColorField')
            ->toContain('v-model="form.color"')
            ->toContain(':error="form.errors.color"');
    }

    $falt = taggfargKod('components/TagColorField.vue');

    expect($falt)->toContain('modelValue: { type: String, default: null }')
        ->toContain('id: { type: String, required: true }')
        ->toContain('error: { type: String, default: null }')
        ->toContain("defineEmits(['update:modelValue'])");

    /*
     * Och värdet når dem på samma event som förut: hjulet skriver
     * `update:modelValue`, fältet skickar det vidare oförändrat, och
     * `v-model="form.color"` i de två formulären lyssnar på just det. Det är
     * därför ingen av dem behöver röras.
     *
     * Raden står här och inte i provet för hjulet av ett skäl: utan den vore
     * det här provet grönt redan på basen — konsumenterna ÄR oförändrade, och
     * allt det säger om fältet är att dess props står still. Det är
     * vidarebefordran som binder dem till den nya väljaren, och därför är det
     * den som gör provet rött utan implementationen (CI:s kontroll i
     * .github/scripts/rott-pa-basen.sh).
     */
    expect($falt)->toContain('@update:model-value="emit(\'update:modelValue\', $event)"');
});
