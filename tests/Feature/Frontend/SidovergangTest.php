<?php

use Illuminate\Support\Facades\File;

/*
 * Mjuka övergångar mellan sidorna — M31 · Flytande navigering · issue 279
 * (#838), se resources/js/app.js och resources/css/app.css samt besluten i
 * [[ADR-0056 Flytande navigering]].
 *
 * **Källkodsprov och inte sidprov.** Övergången sker i klienten: Inertia läser
 * `defaults` ur `app.js` och webbläsarens View Transitions ritar övergången,
 * och en svarskropp i en testsvit bär bara rotvyn. Det som går att pröva på
 * serversidan är därför formen på källkoden — vilken inställning, vilket
 * villkor, vilken varaktighet — samma form som GenomgangTest,
 * GlobalHistorikvyTest och ForhamtningTest. Att övergången faktiskt känns i en
 * webbläsare står i PR-kroppen.
 *
 * Kommentarer rensas bort före varje kontroll, precis som i ForhamtningTest:
 * filerna är fulla av svenska docblock som förklarar varför, och en regel som
 * letar efter `viewTransition` ska inte kunna nöjas av en mening i ett
 * docblock.
 *
 * Hjälparna har prefixet `sidovergang` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta — samma tre slag som ForhamtningTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer. CSS använder bara det
 * första slaget, så samma rensning duger för både js och css.
 */
function sidovergangUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/** En fil under resources, med kommentarer borta. */
function sidovergangKod(string $sokvag): string
{
    return sidovergangUtanKommentarer(File::get(resource_path($sokvag)));
}

/*
 * Klart när: `ett sidbyte tonar över` — `app.js` innehåller `visitOptions` och
 * `viewTransition: true` (Beslut 1).
 *
 * Att `viewTransition` står som en del av den här funktionen är själva poängen:
 * en naken förekomst någon annanstans i filen hade inte slagit på någon
 * övergång. Provet läser därför också att den sitter under `defaults` — appens
 * förval, som varje besök utan eget val läser — och inte på en enskild länk.
 *
 * Regeln tillåter ett nivå av klamrar mellan `defaults` och `visitOptions`, för
 * `prefetch` från issue 277 står emellan med ett eget objekt; den kan därför
 * inte hoppa över `defaults`-blockets avslutande klammer.
 */
it('ett sidbyte tonar över', function () {
    $kod = sidovergangKod('js/app.js');

    expect($kod)->toContain('visitOptions')
        ->toContain('viewTransition: true');

    expect($kod)->toMatch(
        '#defaults:\s*\{(?:[^{}]|\{[^{}]*\})*visitOptions#s',
        'övergången står inte som appens förval under defaults',
    );
});

/*
 * Klart när: `ett filter tonar inte` — `app.js` villkorar övergången på
 * `preserveState` (Beslut 1).
 *
 * Filterraderna går med `preserveState: true`, och en lista som tonar för varje
 * tangenttryckning i ett sökfält är värre än ingen övergång. Provet håller två
 * saker isär: att `preserveState` faktiskt ingår i villkoret, och att funktionen
 * bara returnerar nyckeln `viewTransition` eller ett tomt objekt. Inertia 3.7
 * lägger resultatet ovanpå besökets egna val, så en returnerad kopia av
 * `options` hade skrivit över dem — därför prövas formen på båda grenarna.
 */
it('ett filter tonar inte', function () {
    $kod = sidovergangKod('js/app.js');

    expect($kod)->toContain('preserveState')
        ->toContain("=== 'get'");

    expect($kod)->toMatch(
        '#!\s*options\.preserveState#',
        'övergången villkoras inte på preserveState — ett filter skulle tona',
    );

    expect($kod)->toMatch(
        '#\?\s*\{\s*viewTransition:\s*true\s*\}\s*:\s*\{\s*\}#',
        'funktionen returnerar mer än viewTransition — då skrivs besökets egna val över',
    );
});

/*
 * Klart när: `övergången är 150 ms` — `app.css` innehåller
 * `::view-transition-old(root)` och `animation-duration: 150ms` (Beslut 2).
 *
 * Webbläsarens förval är 250 ms, och under den tiden går sidan inte att klicka
 * i. Provet kräver varaktigheten på BÅDA halvorna — den gamla sidan som tonar
 * ut och den nya som tonar in — för en varaktighet som bara sitter på den ena
 * låter halvorna glida isär.
 */
it('övergången är 150 ms', function () {
    $css = sidovergangKod('css/app.css');

    expect($css)->toContain('::view-transition-old(root)')
        ->toContain('animation-duration: 150ms');

    expect($css)->toMatch(
        '#::view-transition-old\(root\)[^{]*::view-transition-new\(root\)[^{]*\{\s*animation-duration:\s*150ms#s',
        'varaktigheten står inte på både den gamla och den nya sidan',
    );
});

/*
 * Klart när: `ingen animation vid mindre rörelse` — `app.css` innehåller
 * `prefers-reduced-motion: reduce` och `animation: none` (Beslut 2).
 *
 * Avstängningen ska ligga INNANFÖR mediafrågan: en naken `animation: none`
 * någon annanstans i filen hade stängt av övergången för alla i stället för för
 * den som bett operativsystemet om mindre rörelse. Provet läser därför hela
 * blocket, från mediafrågan till `*`-reglerna och vidare till avstängningen.
 */
it('ingen animation vid mindre rörelse', function () {
    $css = sidovergangKod('css/app.css');

    expect($css)->toContain('prefers-reduced-motion: reduce')
        ->toContain('animation: none');

    expect($css)->toMatch(
        '#@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^}]*::view-transition-new\(\*\)[^}]*animation:\s*none#s',
        'mindre rörelse stänger inte av övergången',
    );
});
