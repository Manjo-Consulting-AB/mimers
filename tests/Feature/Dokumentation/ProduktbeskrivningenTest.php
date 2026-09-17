<?php

/*
 * Issue 81. Fem ytor beskrev produkten som byggd för en nisch och sade
 * därmed emot beslutet om produktens omfång. Det här testet vaktar de fem
 * ytorna — påståendena bor i prosan, så de läses som text.
 *
 * ADR:er och stängda milstolpars backlogfiler står utanför grinden med
 * flit: skrivna beslut skrivs inte om (ADR-0032 § Beslut), så de behåller
 * sina båtexempel. Det sista testet visar att historiken faller igenom
 * grinden i stället för att fastna i den.
 */

/** De fem ytorna issue 81 skriver om. Historiken står inte med. */
function produktYtor(): array
{
    return [
        'README.md',
        'docs/00 Index.md',
        'docs/Översikt.md',
        'docs/Konkurrens.md',
        'composer.json',
    ];
}

/** Formuleringen besluten vilar på. */
function generiskFormulering(): string
{
    return 'äger, använder eller arbetar med';
}

/** Formuleringar som pekar ut produkten som byggd för en nisch. */
function smalaFormuleringar(): array
{
    return [
        'byggd för en nisch',
        'byggd för nischen',
        'båt, husvagn, stuga eller bil',
        'båtar, husvagnar, stugor och bilar',
        'ett ägt ting',
        'Svenska och engelska',
    ];
}

/** Läser en fil ur repot, eller faller med besked om den inte går att läsa. */
function ytansText(string $yta): string
{
    $text = file_get_contents(base_path($yta));

    if ($text === false) {
        throw new RuntimeException("Kunde inte läsa {$yta}.");
    }

    return $text;
}

/** Avsnittet under en rubrik på nivå två, fram till nästa rubrik. */
function avsnittsText(string $yta, string $rubrik): string
{
    $text = '';
    $inne = false;

    foreach (explode("\n", ytansText($yta)) as $rad) {
        if (preg_match('/^##\s+(.+?)\s*$/', $rad, $träff) === 1) {
            $inne = $träff[1] === $rubrik;

            continue;
        }

        if ($inne && preg_match('/^#\s/', $rad) === 1) {
            break;
        }

        if ($inne) {
            $text .= $rad."\n";
        }
    }

    return $text;
}

/** Filerna under docs/ADR och docs/Backlog — valvets historik. */
function historikFiler(): array
{
    $filer = [];

    foreach (['docs/ADR', 'docs/Backlog'] as $katalog) {
        foreach (glob(base_path($katalog).'/*.md') ?: [] as $fil) {
            $filer[] = $fil;
        }
    }

    return $filer;
}

/** Bär filen någon av de smala formuleringarna? */
function bärSmalFormulering(string $fil): bool
{
    $text = file_get_contents($fil);

    if ($text === false) {
        return false;
    }

    foreach (smalaFormuleringar() as $formulering) {
        if (mb_stripos($text, $formulering) !== false) {
            return true;
        }
    }

    return false;
}

it('saknar varje smal formulering', function (string $yta) {
    $text = ytansText($yta);

    foreach (smalaFormuleringar() as $formulering) {
        expect(mb_stripos($text, $formulering))->toBeFalse(
            "{$yta} bär kvar den smala formuleringen \"{$formulering}\"."
        );
    }
})->with(produktYtor());

it('beskriver produkten som en plats för det användaren äger, använder eller arbetar med', function (string $yta) {
    expect(ytansText($yta))->toContain(generiskFormulering());
})->with(['README.md', 'docs/00 Index.md', 'composer.json']);

it('definierar containern som ett sammanhang i Kärnidén', function () {
    $kärnidén = avsnittsText('docs/Översikt.md', 'Kärnidén');

    expect($kärnidén)
        ->toContain('ett sammanhang')
        ->toContain('en båt, en bil, en fastighet, en kund eller ett projekt');
});

it('säger engelska i Avgränsning', function () {
    expect(avsnittsText('docs/Översikt.md', 'Avgränsning'))->toContain('- Engelska');
});

it('säger att segmenten är den första marknaden, med tabellens rader orörda', function () {
    $vemBetalar = avsnittsText('docs/Översikt.md', 'Vem betalar');

    expect($vemBetalar)
        ->toContain('första marknaden')
        ->toContain('inte produktens definition')
        ->toContain('| Privatperson |')
        ->toContain('| Nybyggnadsvarv |')
        ->toContain('| Mäklare |')
        ->toContain('| Servicevarv |')
        ->toContain('| Charterbolag |');
});

it('bär kvar de fyra argumenten i Konkurrens', function () {
    $linjen = avsnittsText('docs/Konkurrens.md', 'Den gemensamma linjen');

    expect($linjen)
        ->toContain('**1. Tiden — systemet knackar på av sig självt.**')
        ->toContain('**2. Flera parter, olika behörighet, på samma objekt.**')
        ->toContain('**3. Strukturerade fält som systemet kan räkna på.**')
        ->toContain('**4. Objektets historik följer objektet, inte personen.**');
});

it('låter Konkurrens-exemplen spänna över bredden', function () {
    $linjen = avsnittsText('docs/Konkurrens.md', 'Den gemensamma linjen');

    $sammanhang = array_filter(
        ['båt', 'bil', 'fastighet', 'kund', 'projekt'],
        fn (string $ord) => str_contains($linjen, $ord)
    );

    expect(count($sammanhang))->toBeGreaterThanOrEqual(3);
});

it('har en generisk beskrivning och samma paket som låsfilen bygger på', function () {
    $paket = json_decode(ytansText('composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $lås = json_decode(ytansText('composer.lock'), true, 512, JSON_THROW_ON_ERROR);

    expect($paket['description'])->toContain(generiskFormulering());

    $låsta = array_column(array_merge($lås['packages'], $lås['packages-dev']), 'name');
    $krav = array_merge(array_keys($paket['require']), array_keys($paket['require-dev']));

    // Plattformen själv — php, ext-*, lib-* — står i require men aldrig i låsfilen.
    $krav = array_values(array_filter($krav, fn (string $namn) => str_contains($namn, '/')));

    $saknade = array_values(array_diff($krav, $låsta));

    expect($saknade)->toBe([], 'Paket i composer.json som låsfilen inte känner igen: '.implode(', ', $saknade));
});

it('låter historiken falla igenom grinden i stället för att fastna', function () {
    $historik = historikFiler();

    expect($historik)->not->toBeEmpty();

    // Historiken bär fortfarande de smala formuleringarna — det är avsiktligt.
    // Grinden är därför riktad mot de fem ytorna och inte mot valvet.
    $träffar = array_filter($historik, fn (string $fil) => bärSmalFormulering($fil));

    expect(count($träffar))->toBeGreaterThan(
        0,
        'Historiken borde bära kvar minst en av formuleringarna; annars är undantaget tomt.'
    );

    foreach (produktYtor() as $yta) {
        expect($yta)->not->toStartWith('docs/ADR/');
        expect($yta)->not->toStartWith('docs/Backlog/');
    }
});
