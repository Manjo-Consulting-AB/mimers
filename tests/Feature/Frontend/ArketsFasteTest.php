<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 323 · Arket öppnas uppåt när platsen under inte räcker, se
 * [[M41 Fynd 2026-10-19]] § 323.
 *
 * **Fästet är en räkning, och räkningen prövas i node.** `UiSheet` väljer sida
 * ur öppnarens rektangel och fönstrets höjd: under knappen när platsen under
 * räcker till 70vh eller är större än platsen över, annars över. Den regeln
 * hade inte gått att bevisa med en assertion på en avskrift i PHP — den hade
 * prövat provet självt. Funktionen `sheetAnchor` är därför ren och läses ur
 * komponenten, samma grepp som DatumregelTest läser sin modul, och körs mot
 * givna rektanglar.
 *
 * **Resten är formen, inte räkningen.** Att variablerna bara läses i sin
 * `md:`-form — så att mobilens bottenfäste står orört — och att anropen av
 * `UiSheet` bär samma proppar som förut går att avgöra ur källkoden. Att
 * panelen faktiskt hamnar rätt i en webbläsare står i PR-kroppens handprov.
 *
 * Hjälparna har prefixet `arketsfaste` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som PlusknappTest rensar —
 * docblocken är svenska med flit, och en regel som letar efter markup ska inte
 * kunna nöjas av en mening i en kommentar.
 */
function arketsfasteKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Kör `sheetAnchor` ur UiSheet.vue mot en rektangel och en fönsterhöjd.
 *
 * @return array{top: string, bottom: string, maxHeight: string}
 */
function arketsfaste(int $viewportHeight, int $top, int $bottom): array
{
    $kalla = File::get(resource_path('js/components/UiSheet.vue'));

    preg_match('#function sheetAnchor\(.*?\n\}#s', $kalla, $traff);

    expect($traff)->not->toBeEmpty('UiSheet.vue saknar funktionen sheetAnchor');

    $skript = implode("\n", [
        'const sheetAnchor = new Function('.json_encode('return ('.$traff[0].')').')();',
        sprintf(
            'process.stdout.write(JSON.stringify(sheetAnchor({ top: %d, bottom: %d }, %d)));',
            $top,
            $bottom,
            $viewportHeight,
        ),
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return json_decode(implode("\n", $rader), true, 512, JSON_THROW_ON_ERROR);
}

/*
 * Klart när: "över `md:` öppnas arket under knappen när platsen räcker".
 *
 * 1 000 px hög skärm ger 70vh = 700 px. Under knappen finns
 * 1 000 − 140 − 8 − 16 = 836 px, alltså mer än 70vh, och panelen fästs under
 * med hela 70vh som tak.
 */
it('fäster arket under knappen när platsen under räcker', function () {
    expect(arketsfaste(1000, 100, 140))->toBe([
        'top' => '148px',
        'bottom' => '',
        'maxHeight' => '700px',
    ]);
});

/*
 * Klart när: "över `md:` öppnas arket under knappen när platsen räcker" —
 * andra halvan av regeln. Här räcker platsen under inte till 70vh (636 px),
 * men den är större än platsen över (276 px), och då stannar panelen under
 * knappen. Taket blir platsen som finns, inte 70vh.
 */
it('fäster arket under knappen när platsen under är större än platsen över', function () {
    expect(arketsfaste(1000, 300, 340))->toBe([
        'top' => '348px',
        'bottom' => '',
        'maxHeight' => '636px',
    ]);
});

/*
 * Klart när: "över `md:` öppnas arket över knappen när platsen under inte
 * räcker och platsen över är större".
 *
 * Knappen sitter långt ned: under finns 136 px, över finns 576 px. Panelen
 * fästs över med `bottom` = 800 − 600 + 8 = 208 px, och taket är 70vh — den
 * sidan har mer plats än så.
 */
it('fäster arket över knappen när platsen under inte räcker och platsen över är större', function () {
    expect(arketsfaste(800, 600, 640))->toBe([
        'top' => '',
        'bottom' => '208px',
        'maxHeight' => '560px',
    ]);
});

/*
 * Klart när: "arkets högsta höjd ryms på skärmen på den valda sidan".
 *
 * Samma val som ovan, men platsen över (476 px) är mindre än 70vh. Då är det
 * platsen som är taket — panelen rullar hellre än går förbi skärmens kant.
 */
it('räknar högsta höjden ur platsen på den valda sidan', function () {
    expect(arketsfaste(800, 500, 540))->toBe([
        'top' => '',
        'bottom' => '308px',
        'maxHeight' => '476px',
    ]);
});

/*
 * Klart när: "under `md:` är arket oförändrat".
 *
 * Fästet är CSS-variabler, och varje variabel läses bara i sin `md:`-form.
 * Under brytpunkten gäller bottenfästet som förut: panelen ligger sist i en
 * flexkolumn som justeras nedåt, med rundade hörn uppåt.
 */
it('läser fästet ur CSS-variabler bara över md:', function () {
    $ark = arketsfasteKod('components/UiSheet.vue');

    expect($ark)->toContain("'--sheet-top': anchor.top")
        ->toContain("'--sheet-right': anchor.right")
        ->toContain("'--sheet-bottom': anchor.bottom")
        ->toContain("'--sheet-max-height': anchor.maxHeight")
        ->toContain('md:top-[var(--sheet-top)]')
        ->toContain('md:bottom-[var(--sheet-bottom)]')
        ->toContain('md:right-[var(--sheet-right)]')
        ->toContain('md:max-h-[var(--sheet-max-height)]');

    // Variablerna förekommer i klasslistan en gång var, och alltid med `md:`.
    foreach (['top', 'bottom', 'right', 'max-h'] as $egenskap) {
        expect(substr_count($ark, "{$egenskap}-[var(--sheet-"))->toBe(
            1,
            "{$egenskap} läses utanför sin md:-form eller på två ställen",
        );
    }

    // Den statiska höjden är borta: taket räknas nu fram.
    expect($ark)->not->toContain('md:max-h-[70vh]');

    // Mobilens klassrad står kvar ordagrant.
    expect($ark)->toContain('flex h-full w-full flex-col justify-end')
        ->toContain('relative flex max-h-full flex-col gap-2 overflow-y-auto rounded-t-card bg-surface p-4');
});

/*
 * Klart när: "anropen av `UiSheet` är oförändrade": fästet räknas inne i
 * arket, och anroparen bär samma tre proppar som förut. Provet räknar upp
 * varje anrop som finns i repot; ett nytt anrop utanför listan faller inte,
 * men ett nytt fästeargument på ett av dem gör det.
 */
it('ändrar inte anropen av UiSheet', function () {
    $ark = arketsfasteKod('components/UiSheet.vue');

    expect($ark)->toContain('open: { type: Boolean, default: false }')
        ->toContain('heading: { type: String, required: true }')
        ->toContain('trigger: { type: Object, default: null }');

    foreach ([
        'components/CreateMenu.vue',
        'components/ItemIconPicker.vue',
        'components/ContainerCoverSheet.vue',
    ] as $sokvag) {
        $kod = arketsfasteKod($sokvag);

        preg_match('#<UiSheet\b(.*?)>#s', $kod, $anrop);

        expect($anrop)->not->toBeEmpty("{$sokvag} anropar inte UiSheet");

        preg_match_all('/([@:]?[a-z][a-z-]*)(?==)/', $anrop[1], $attribut);

        $attribut = array_values(array_unique($attribut[1]));
        sort($attribut);

        expect($attribut)->toBe(
            [':heading', ':open', ':trigger', '@close'],
            "{$sokvag} skickar något annat än de tre propparna och close",
        );
    }
});
