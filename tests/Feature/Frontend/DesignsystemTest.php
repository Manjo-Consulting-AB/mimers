<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 424 · Designtokens i @theme, se [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Issuen bygger ingenting
 * som syns: den flyttar fjorton färgroller, fem typsteg och tre radier ur
 * ADR:ens tabell och in i `@theme`, och bevisar dem på två befintliga
 * komponenter. Det som går att pröva på serversidan är därför formen på
 * källkoden — att varje roll ADR:en namnger finns som token, att de två
 * komponenterna inte längre bär råa färgklasser, och att fokusringen blev en
 * token i stället för en nollställd outline.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att värdena ser
 * ut som bilderna, att kontrasten håller, och att tangentbordet hittar ringen.
 * Handprovet står i PR-kroppen.
 *
 * Kommentarer rensas bort före varje kontroll, precis som i
 * tests/Feature/Frontend/GenomgangTest.php: filerna är fulla av svensk prosa
 * som förklarar varför, och en regel som letar efter `text-slate-800` ska inte
 * kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `design` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer.
 */
function designUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * De två komponenter issuen migrerar — och bara dem — med kommentarer borta.
 *
 * Resten av resources/js bär råa färgklasser med flit: knappen och
 * formulärkontrollerna ägs av issue 98, kortet, brickan, listraden,
 * tom-tillståndet och taltutan av issue 99. En regel över hela katalogen hade
 * fällt trettiosex filer i dag och sagt ingenting om den här ändringen.
 *
 * @return array<string, string> relativ sökväg → källkod utan kommentarer
 */
function designKomponenter(): array
{
    $filer = [];

    foreach (['components/FormField.vue', 'components/FlashMessage.vue'] as $sokvag) {
        $filer[$sokvag] = designUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
    }

    return $filer;
}

/**
 * Varje `class`-attribut i en fil, som rå klass-sträng.
 *
 * Attributvärdet matchas med citattecken runt om, så ett `>` inuti ett värde
 * inte avslutar något i förtid — samma form som genomgangTaggar. `[^"]` går
 * över radbrytning, så ett attribut skrivet på flera rader fångas som ett.
 *
 * @return array<int, array{rad: int, klasser: string}>
 */
function designKlasser(string $kod): array
{
    preg_match_all('/class="([^"]*)"/', $kod, $träffar, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $träff): array => [
        'rad' => substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
        'klasser' => $träff[0],
    ], $träffar[1]);
}

it('har varje roll i ADR-0042 som token', function () {
    // Adressen till sanningen är ADR-0042 § Beslut. VÄRDENA prövas inte här —
    // de bor i ADR:en, och en tabell som står på två ställen glider isär. Det
    // som prövas är att varje roll finns: en roll som saknas är en komponent
    // som faller tillbaka på en palettfärg, och då är issuen ogjord.
    $css = designUtanKommentarer(File::get(resource_path('css/app.css')));

    $fargroller = [
        '--color-shell',
        '--color-shell-active',
        '--color-surface',
        '--color-surface-muted',
        '--color-surface-sunken',
        '--color-border',
        '--color-ink',
        '--color-ink-muted',
        '--color-ink-subtle',
        '--color-accent',
        '--color-accent-soft',
        '--color-danger',
        '--color-warning',
        '--color-success',
    ];

    $typsteg = ['--text-meta', '--text-body', '--text-title', '--text-heading', '--text-display'];

    $radier = ['--radius-card', '--radius-control', '--radius-pill'];

    // Siffrorna är ADR:ens, inte mina: fjorton färgroller i tabellen, fem
    // typsteg och tre radier i meningen under den.
    expect(count($fargroller))->toBe(14);
    expect(count($typsteg))->toBe(5);
    expect(count($radier))->toBe(3);

    foreach ([...$fargroller, '--color-focus', ...$typsteg, ...$radier] as $token) {
        // Kolon följt av ett värde, inte bara namnet: en token som nämns i
        // förbigående är ingen token.
        expect($css)->toMatch(
            '/'.preg_quote($token, '/').':\s*\S/',
            "{$token} saknas i @theme — rollen står i ADR-0042 § Beslut",
        );
    }
});

it('bär inga råa färgklasser i FormField och FlashMessage', function () {
    // Ett `text-slate-800` är en färg någonstans i markupen; ett `text-ink` är
    // en roll. Provet gäller bara de två filerna — de trettiosex andra
    // migreras av issue 98 och 99, och att fälla dem här hade varit att göra
    // deras arbete i förväg.
    $palett = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $verktyg = 'text|bg|border|ring|outline|divide|from|via|to|fill|stroke|placeholder|decoration|shadow|accent|caret';

    foreach (designKomponenter() as $sokvag => $kod) {
        preg_match_all(
            '/\b(?:'.$verktyg.')-(?:(?:'.$palett.')-\d{2,3}|white|black)\b/',
            $kod,
            $träffar,
            PREG_OFFSET_CAPTURE,
        );

        $avvikelser = array_map(fn (array $träff): string => sprintf(
            '%s:%d använder %s — rollen ska komma ur @theme',
            $sokvag,
            substr_count(substr($kod, 0, $träff[1]), "\n") + 1,
            $träff[0],
        ), $träffar[0]);

        expect($avvikelser)->toBe([]);
    }
});

it('gör fokusringen till en token och inte en nollställd outline', function () {
    // Regeln ur [[ADR-0042 Designsystemet]] § Beslut, och skälet till att den
    // står i FormFields docblock: 68a och 68b gick igenom hela frontenden med
    // tangentbord. En `outline-none` utan ersättning river det arbetet.
    $komponenter = designKomponenter();

    foreach ($komponenter as $sokvag => $kod) {
        foreach (designKlasser($kod) as $klass) {
            if (! str_contains($klass['klasser'], 'outline-none')) {
                continue;
            }

            $var = "{$sokvag}:{$klass['rad']}";

            // Ringen tar över efter outline: två pixlar, som ADR:en säger.
            expect($klass['klasser'])->toMatch(
                '/focus(-visible)?:ring-2/',
                "{$var} nollställer outline utan en ring som tar över",
            );

            // Och den är `--color-focus`, inte en palettfärg. Utan de två
            // bokstäverna är ringen currentColor, alltså fel färg så fort
            // texten byter roll.
            expect($klass['klasser'])->toMatch(
                '/focus(-visible)?:ring-focus/',
                "{$var} sätter en ring utan --color-focus",
            );
        }
    }

    // Positivt prov, och det som håller regeln levande: FormField är filen
    // med en outline att ersätta — fältets felmeddelande fokuseras med kod
    // (useErrorFocus), och den som inte ser var fokus hamnade har tappat
    // tråden. Provet ovan blir tyst den dag `outline-none` försvinner; det
    // här fångar att ringen försvann med den.
    expect($komponenter['components/FormField.vue'])->toMatch(
        '/focus(-visible)?:(ring|outline)-focus/',
        'FormField sätter ingen fokusring i --color-focus',
    );
});
