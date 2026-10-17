<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 283 · Kärnkomponenterna tar emot ikonen, se
 * [[ADR-0057 Ikoner och typsnitt]] § 2–3 och § Konsekvenser.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Ändringen syns bara i en
 * webbläsare — att ikonen i kortets rubrik och listradens slot står på samma
 * höjd som texten, att taltutan på glaset läses mot bilden. Det som går att
 * avgöra på serversidan är att rubrikraden och ikonhållaren centrerar sina
 * barn, att `UiStat` utan ikon behåller sin markup, att ikonen ritas i 24px
 * före talet, att `on-image` står på glasets tre tokens, och att
 * domänmappningen ger rollen och tonen ur tabellen — aldrig `danger`.
 *
 * Kommentarer rensas bort före varje kontroll, som i DesignsystemTest,
 * IkonerTest och YtornaTest: filerna är fulla av svensk prosa som förklarar
 * varför, och en regel som letar efter ett klassnamn ska inte kunna nöjas av
 * en mening i ett docblock.
 *
 * Hjälparna har prefixet `karnIkon` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function karnIkonUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En fil ur resources/js, relativt katalogen, med kommentarer borta.
 */
function karnIkonKod(string $sokvag): string
{
    return karnIkonUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/**
 * Domänerna i presentationsmodulen: domän → roll och ton.
 *
 * @return array<string, array{role: string, tone: string}>
 */
function karnIkonMappning(string $kod): array
{
    preg_match_all(
        "/^[ \t]*([a-z_]+):[ \t]*\{[ \t]*role:[ \t]*'([a-z-]+)',[ \t]*tone:[ \t]*'([a-z-]+)'[ \t]*\},?[ \t]*$/m",
        $kod,
        $träffar,
        PREG_SET_ORDER,
    );

    $mappning = [];

    foreach ($träffar as $träff) {
        $mappning[$träff[1]] = ['role' => $träff[2], 'tone' => $träff[3]];
    }

    return $mappning;
}

/**
 * Rollnamnen i den slutna ikonlistan.
 *
 * @return array<int, string>
 */
function karnIkonRoller(): array
{
    preg_match_all("/^[ \t]{4}'?([a-z][a-z-]*)'?:[ \t]*\[/m", karnIkonKod('icons/index.js'), $träffar);

    return $träffar[1];
}

it('centrerar en ikon i kortets rubrik mot texten', function () {
    $kod = karnIkonKod('components/UiCard.vue');

    // Rubrikraden är `inline-flex items-center`: ett `UiIcon` i slotten är ett
    // flexbarn, och på baslinjen hade en 20px-ikon hamnat snett mot 15px-text.
    expect($kod)->toMatch(
        '/<h2\b[^>]*class="[^"]*\binline-flex\b[^"]*\bitems-center\b[^"]*"/s',
        'UiCard saknar en rubrikrad som centrerar en ikon mot texten',
    );
});

it('centrerar en ikon i listradens icon-slot mot texten', function () {
    $kod = karnIkonKod('components/UiListRow.vue');

    // Hållaren är `inline-flex items-center`. Raden centrerar redan sina barn;
    // det som saknades var att hållaren själv slutade lägga svg:en på en
    // baslinje med descenderutrymme under.
    expect($kod)->toMatch(
        '/<span\b[^>]*v-if="\$slots\.icon"[^>]*class="[^"]*\binline-flex\b[^"]*\bitems-center\b[^"]*"/s',
        'UiListRow saknar en ikonhållare som centrerar ikonen mot texten',
    );
});

it('ritar UiStat utan ikon som förut', function () {
    $kod = karnIkonKod('components/UiStat.vue');

    // Standardformen och textrollerna är de gamla. Utan `tone` faller
    // uppslaget tillbaka på `surface`, och då är klasserna exakt som förut.
    expect($kod)->toContain("box: 'border-border bg-surface'")
        ->toContain("value: 'text-ink'")
        ->toContain("label: 'text-ink-subtle'");

    // Värdet ritas i en span med samma klasser när ingen ikon finns — den
    // flexade varianten är förbehållen ikonen.
    expect($kod)->toMatch(
        '/<span v-else class="text-heading font-semibold" :class="toneClasses\.value">\{\{ value \}\}<\/span>/',
        'UiStat utan ikon ritar inte värdet som förut',
    );

    // Och ikonen är villkorad: `v-if="icon"` sitter på hållaren, inte på
    // komponenten, så hela raden försvinner när proppen är tom.
    expect($kod)->toMatch('/<span\s+v-if="icon"/s');
});

it('ritar UiStat med ikon i 24 px före talet', function () {
    $kod = karnIkonKod('components/UiStat.vue');

    expect($kod)->toContain('icon: { type: String, default: null }');

    $ikon = strpos($kod, '<UiIcon');
    $varde = strpos($kod, '{{ value }}');

    expect($ikon)->not->toBeFalse('UiStat ritar ingen UiIcon');
    expect($varde)->not->toBeFalse('UiStat ritar inget värde');

    // Ikonen står före talet i mallen.
    expect($ikon)->toBeLessThan($varde);

    $tag = substr($kod, $ikon, strpos($kod, '>', $ikon) - $ikon);

    // `toContain` tar nål, inte förklaring — budskapet får en egen rad.
    expect($tag)->toContain(':size="24"');
});

it('ger UiStat formen on-image glaset och vit text', function () {
    $kod = karnIkonKod('components/UiStat.vue');

    expect($kod)->toContain('tone: { type: String, default: null }');

    preg_match(
        "/'on-image':\s*\{\s*box:\s*'([^']+)',\s*value:\s*'([^']+)',\s*label:\s*'([^']+)',?\s*\}/s",
        $kod,
        $träff,
    );

    expect($träff)->not->toBeEmpty('UiStat saknar formen on-image');

    // Ytan är glaset ur ADR-0057 § 6, och både talet och etiketten är vita.
    expect($träff[1])->toContain('bg-glass')->toContain('border-glass-border');
    expect($träff[2])->toBe('text-ink-on-accent');
    expect($träff[3])->toBe('text-ink-on-accent');
});

it('ger varje domän rollen och tonen ur tabellen', function () {
    $mappning = karnIkonMappning(karnIkonKod('components/iconPresentation.js'));

    // Tabellen ur [[ADR-0057 Ikoner och typsnitt]] § 3, domän för domän.
    $tabell = [
        'attachment' => ['document', 'accent'],
        'cost_entry' => ['cost', 'warning'],
        'schedule' => ['task', 'success'],
        'schedule_occurrence' => ['task', 'success'],
        'schedule_dependency' => ['task', 'success'],
        'occurrence_dependency' => ['task', 'success'],
        'item' => ['item', 'neutral'],
        'item_link' => ['link', 'neutral'],
        'loan' => ['loan', 'neutral'],
        'tag' => ['tag', 'neutral'],
        'category' => ['category', 'neutral'],
        'container' => ['container', 'neutral'],
        'access' => ['share', 'neutral'],
        'invitation' => ['share', 'neutral'],
        'ownership_transfer' => ['share', 'neutral'],
    ];

    // Femton domäner, räknade: glider mappningen och provet isär blir loopen
    // nedan tyst om en rad, och ett tyst prov är värre än inget.
    expect($mappning)->toHaveCount(count($tabell));

    foreach ($tabell as $domän => [$roll, $ton]) {
        expect($mappning[$domän] ?? null)->toBe(
            ['role' => $roll, 'tone' => $ton],
            "{$domän} saknas eller bär fel roll och ton — tabellen står i ADR-0057 § 3",
        );
    }
});

it('faller tillbaka på history och neutral för en okänd domän', function () {
    $kod = karnIkonKod('components/iconPresentation.js');

    expect($kod)->toMatch(
        "/return PRESENTATION\[domain\] \?\? \{ role: 'history', tone: 'neutral' \}/",
        'en okänd domän faller inte tillbaka på history i neutral',
    );
});

it('ger aldrig en domän tonen danger', function () {
    $mappning = karnIkonMappning(karnIkonKod('components/iconPresentation.js'));

    expect($mappning)->not->toBeEmpty();

    // Rött betyder att något har passerat, och en kostnad är inte ett fel.
    // Den tonen kommer ur datumregeln, aldrig ur domänen.
    foreach ($mappning as $domän => $post) {
        expect($post['tone'])->not->toBe(
            'danger',
            "{$domän} ger tonen danger — rött betyder passerat, inte en kostnad",
        );
    }
});

it('pekar bara på roller som finns i ikonlistan', function () {
    $roller = karnIkonRoller();
    $mappning = karnIkonMappning(karnIkonKod('components/iconPresentation.js'));

    expect($roller)->not->toBeEmpty('ikonlistan gick inte att läsa');
    expect($mappning)->not->toBeEmpty();

    foreach ($mappning as $domän => $post) {
        expect(in_array($post['role'], $roller, true))->toBeTrue(
            "{$domän} pekar på rollen {$post['role']}, som saknas i resources/js/icons/index.js",
        );
    }
});
