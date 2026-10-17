<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 281 · Skuggan, glaset och de mjuka tonerna, se [[ADR-0057 Ikoner och
 * typsnitt]] § 6.
 *
 * **Det här är ett källkodsprov, inte ett sidprov.** Ändringen syns bara i en
 * webbläsare — att skuggan lyfter kortet lagom mycket, att glaset släpper
 * igenom hjältens bild och att de mjuka tonerna bär ikonrutans färg. Det som
 * går att avgöra på serversidan är att varje roll ur ADR:ens tabell finns i
 * `@theme` med sitt värde, att kortet bär skuggan och ingen annan, och att
 * ingen komponent hittar på ett eget skuggvärde bredvid rollen.
 *
 * VÄRDENA prövas här, till skillnad från DesignsystemTest: den provar att
 * ADR-0042:s roller finns och lämnar värdena till ADR:en. Här är värdena
 * själva ärendet — en skugga som glider isär från tabellen är en annan skugga,
 * och då är issuen inte gjord.
 *
 * Kommentarer rensas bort före varje kontroll, som i DesignsystemTest,
 * GenomgangTest och TypsnittTest: filerna är fulla av svensk prosa som
 * förklarar varför, och en regel som letar efter `shadow-[` ska inte kunna
 * nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `ytorna` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function ytornaUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Innehållet i `@theme`-blocket. En token utanför blocket är ingen token:
 * Tailwind läser bara katalogen inuti och genererar ingen verktygsklass för
 * resten, hur rätt värdet än ser ut.
 */
function ytornaTema(string $css): string
{
    preg_match('/@theme\s*\{([^}]*)\}/s', $css, $träffar);

    return $träffar[1] ?? '';
}

/**
 * Värdet på en token, med whitespace normaliserad till en rad. Ett värde som
 * sträcker sig över flera rader läses som ett.
 */
function ytornaToken(string $css, string $token): ?string
{
    if (! preg_match('/'.preg_quote($token, '/').':\s*([^;]+);/s', $css, $träffar)) {
        return null;
    }

    return trim(preg_replace('/\s+/', ' ', $träffar[1]));
}

it('har skuggan, glaset och de tre mjuka tonerna i @theme', function () {
    // Adressen till sanningen är ADR-0057 § 6. Sex rader i tabellen, och
    // `accent` och `neutral` står med flit inte här: deras mjuka ytor finns
    // redan som `--color-accent-soft` och `--color-surface-sunken`.
    $css = ytornaUtanKommentarer(File::get(resource_path('css/app.css')));
    $tema = ytornaTema($css);

    expect($tema)->not->toBe('', '@theme-blocket saknas i app.css');

    $värden = [
        '--shadow-card' => '0 1px 2px rgb(15 23 42 / 0.04), 0 2px 6px rgb(15 23 42 / 0.04)',
        '--color-glass' => 'rgb(255 255 255 / 0.12)',
        '--color-glass-border' => 'rgb(255 255 255 / 0.28)',
        '--color-success-soft' => '#ecfdf3',
        '--color-warning-soft' => '#fff7ed',
        '--color-danger-soft' => '#fef2f2',
    ];

    // Sex roller, räknade — glider tabellen och provet isär blir loopen nedan
    // tyst om en rad, och ett tyst prov är värre än inget.
    expect($värden)->toHaveCount(6);

    foreach ($värden as $token => $värde) {
        expect(ytornaToken($tema, $token))->toBe(
            $värde,
            "{$token} saknas i @theme eller har fel värde — rollen står i ADR-0057 § 6",
        );
    }
});

it('låter varje UiCard bära skuggan och ingen annan', function () {
    // Kortet är panelen varje sida ritar, och skuggan är det som skiljer det
    // från botten. Den ska komma ur rollen: `shadow-card` genereras av
    // `--shadow-card`, medan ett eget värde hade blivit en skugga utanför
    // tabellen.
    $kort = ytornaUtanKommentarer(File::get(resource_path('js/components/UiCard.vue')));

    expect($kort)->toMatch(
        '/<section[^>]*class="[^"]*\bshadow-card\b[^"]*"/',
        'UiCard saknar shadow-card — skuggan bor i --shadow-card',
    );

    preg_match_all('/\bshadow-[a-z0-9-]+\b/', $kort, $träffar);

    expect(array_values(array_unique($träffar[0])))->toBe(
        ['shadow-card'],
        'UiCard bär en skugga utöver rollen',
    );
});

it('skriver inget eget skuggvärde i någon .vue-fil', function () {
    // ADR-0057 § 6: skuggan är en roll. Ett `shadow-[0_1px_2px_...]` i en
    // komponent är samma färgnamn-i-stället-för-roll som ADR-0042 stängde för
    // färgerna, och den som byter skuggan i `@theme` hade inte nått fram.
    //
    // Regeln gäller alla .vue-filer, inte bara UiCard: den som lägger skuggan
    // på en ny yta ska mötas av rollen och inte av fri hand. `shadow-sm` och
    // de andra inbyggda stegen räknas inte hit — de är Tailwinds skala, inte
    // ett värde skrivet i filen.
    $granskade = 0;

    foreach (File::allFiles(resource_path('js')) as $fil) {
        if ($fil->getExtension() !== 'vue') {
            continue;
        }

        $granskade++;

        $kod = ytornaUtanKommentarer(File::get($fil->getPathname()));

        expect(str_contains($kod, 'shadow-['))->toBeFalse(sprintf(
            '%s skriver ett eget skuggvärde — använd --shadow-card eller en roll.',
            'resources/js/'.$fil->getRelativePathname(),
        ));
    }

    // Ett prov som letar i en tom mängd är tyst. Det finns 162 .vue-filer i
    // dag; golvet fångar en glob som slutat gå djupt nog.
    expect($granskade)->toBeGreaterThan(100);
});
