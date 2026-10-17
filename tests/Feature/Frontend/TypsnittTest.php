<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 280 · Typsnittet, se [[ADR-0057 Ikoner och typsnitt]] § 4–5.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Ändringen syns bara i en
 * webbläsare — att Inter faktiskt ritas, att reserven tar över medan filen
 * hämtas och att vikterna ser ut som bilden. Det som går att avgöra på
 * serversidan är att filen ligger i repot med sin licens, att bygget paketerar
 * den och att manifestet pekar på den, att ingen extern typsnittsvärd nämns i
 * resources/, och att typskalans vikter är de ADR:en säger.
 *
 * Kommentarer rensas bort före varje innehållskontroll, som i
 * DesignsystemTest och GenomgangTest: filerna är fulla av svensk prosa som
 * förklarar varför, och en regel som letar efter en värd ska inte kunna nöjas
 * av en mening i ett docblock.
 *
 * Hjälparna har prefixet `typsnitt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function typsnittUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Värdet på en token i `@theme`, utan kommentarer och utan citattecken runt
 * hela värdet. Ett värde som sträcker sig över flera rader läses som ett.
 */
function typsnittToken(string $css, string $token): ?string
{
    if (! preg_match('/'.preg_quote($token, '/').':\s*([^;]+);/s', $css, $träffar)) {
        return null;
    }

    return trim(preg_replace('/\s+/', ' ', $träffar[1]));
}

it('har Inter och dess licens i resources/fonts', function () {
    $typsnitt = resource_path('fonts/InterVariable.woff2');
    $licens = resource_path('fonts/LICENSE.txt');

    expect(file_exists($typsnitt))->toBeTrue(
        'resources/fonts/InterVariable.woff2 saknas — filen hämtas ur Inters officiella release.'
    );

    // En tom fil är inte ett typsnitt. Den variabla woff2:an är cirka 350 kB;
    // golvet fångar en misslyckad nedladdning som lämnat en HTML-sida kvar.
    expect(filesize($typsnitt))->toBeGreaterThan(100_000);

    expect(file_exists($licens))->toBeTrue(
        'resources/fonts/LICENSE.txt saknas — SIL OFL kräver att licensen följer filen.'
    );

    $licenstext = File::get($licens);

    expect($licenstext)
        ->toContain('The Inter Project Authors')
        ->toContain('SIL Open Font License');
});

it('paketerar typsnittet och pekar manifestet på det', function () {
    // Samma förutsättning som UtrullningsartefaktTest: bygget körs före
    // sviten i CI. Utan manifestet är den här kontrollen tyst, och ett tyst
    // prov är värre än inget.
    $manifestPath = public_path('build/manifest.json');

    expect(file_exists($manifestPath))->toBeTrue(
        'public/build saknas — kör "npm run build" innan testsviten.'
    );

    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

    $ingång = 'resources/fonts/InterVariable.woff2';

    expect($manifest)->toHaveKey($ingång);

    $byggd = $manifest[$ingång]['file'];

    expect(file_exists(public_path("build/{$byggd}")))->toBeTrue(
        "manifestet pekar på {$byggd}, men filen finns inte i public/build."
    );

    // CSS-posten listar typsnittet bland sina assets. Det är den kopplingen
    // som gör att `@font-face`-adressen i app.css hamnar i det paketerade
    // bygget och inte blir en förfrågan mot en sökväg som bara finns i
    // källträdet.
    $css = $manifest['resources/css/app.css'];

    expect($css['assets'] ?? [])->toContain($byggd);
});

it('refererar ingen extern typsnittsvärd i resources', function () {
    // ADR-0057 § 4: inte Google Fonts och inte något annat CDN. En
    // tredjepartsförfrågan skickar användarens IP-adress till någon annan,
    // och [[Registerförteckning]] hade behövt redovisa den.
    $externa = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'fonts.bunny.net',
        'use.typekit.net',
        'cdn.jsdelivr.net',
    ];

    $granskade = 0;

    foreach (File::allFiles(resource_path()) as $fil) {
        // Den binära typsnittsfilen läses inte: den kan inte bära en värd,
        // och 350 kB skräp i en sträng säger ingenting.
        if (! in_array($fil->getExtension(), ['css', 'js', 'vue', 'php'], true)) {
            continue;
        }

        $granskade++;

        $kod = typsnittUtanKommentarer(File::get($fil->getPathname()));

        foreach ($externa as $värd) {
            expect(str_contains($kod, $värd))->toBeFalse(sprintf(
                '%s refererar %s — typsnittet ska komma från vår egen server.',
                'resources/'.$fil->getRelativePathname(),
                $värd,
            ));
        }
    }

    // Ett prov som letar i en tom mängd är tyst.
    expect($granskade)->toBeGreaterThan(50);
});

it('börjar --font-sans med Inter och behåller systemets reserv', function () {
    $css = typsnittUtanKommentarer(File::get(resource_path('css/app.css')));

    $sans = typsnittToken($css, '--font-sans');

    expect($sans)->not->toBeNull('--font-sans saknas i @theme');

    // Första posten är vårt typsnitt, och namnet matchar @font-face.
    expect($sans)->toStartWith("'Inter',");

    // Reserven står kvar: ett system utan woff2-stöd, eller en hämtning som
    // aldrig blir klar, ska få systemets sans-serif och inte en serif.
    expect($sans)->toContain('ui-sans-serif', 'system-ui');

    // Den generiska reserven sist i sans-serif-kedjan (emoji-typsnitten efter
    // den ritar bara emoji).
    expect($sans)->toContain('sans-serif');

    // Och @font-face pekar på den lokala filen med `swap`, så texten syns
    // medan filen hämtas.
    expect($css)->toMatch('/@font-face\s*\{[^}]*InterVariable\.woff2[^}]*\}/s');
    expect($css)->toMatch('/@font-face\s*\{[^}]*font-display:\s*swap[^}]*\}/s');
});

it('gör title medelfet och låter kortets rubrik stå kvar halvfet', function () {
    // ADR-0057 § 5: typskalans `title` blir 15px medelfet (500). Kortets
    // rubrik står kvar som halvfet (600), och vikten sätts där, i UiCard —
    // en listradstitel och en kortrubrik är olika nivåer.
    $css = typsnittUtanKommentarer(File::get(resource_path('css/app.css')));

    expect(typsnittToken($css, '--text-title--font-weight'))->toBe('500');

    $kort = typsnittUtanKommentarer(File::get(resource_path('js/components/UiCard.vue')));

    // Rubriken bär både typskalan och den egna vikten. Utan den explicita
    // klassen hade `text-title` gett den 500, alltså samma vikt som en
    // listrad — och då hade de två nivåerna sett likadana ut.
    expect($kort)->toMatch(
        '/<h2[^>]*class="[^"]*\btext-title\b[^"]*\bfont-semibold\b[^"]*"[^>]*>/',
        'UiCards rubrik bär inte både text-title och font-semibold',
    );
});
