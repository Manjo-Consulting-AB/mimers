<?php

use App\Actions\Item\RenderItemNotes;

/*
 * M42 · issue 324 — anteckningens ritning, se [[ADR-0066 Itemets
 * anteckning]] § 2.
 *
 * Ritningen är den ENDA platsen användarens text blir HTML: klienten ritar
 * aldrig Markdown själv (ADR-0066 § 3), så saneringen måste bevisas här och
 * inte i en vue-fil. Tre egenskaper är lätta att tappa bort, och var och en
 * har ett eget test:
 *
 * 1. Markdown-formerna — rubriker, listor, länkar, kod och tabeller — ritas.
 * 2. HTML i texten ritas som TEXT, och en `javascript:`-adress blir ingen
 *    `href`.
 * 3. En extern länk öppnas i en ny flik med `rel="noopener noreferrer
 *    nofollow"`.
 */

it('ritar rubriker, listor, länkar, kod och tabeller', function () {
    $html = (new RenderItemNotes)->handle(<<<'MARKDOWN'
        # Rubrik

        - ett
        - två

        `kod`

        ```php
        echo 'hej';
        ```

        | a | b |
        |---|---|
        | 1 | 2 |

        [Extern](https://example.com)
        MARKDOWN);

    expect($html)->toContain('<h1>Rubrik</h1>');
    expect($html)->toContain('<ul>');
    expect($html)->toContain('<li>ett</li>');
    expect($html)->toContain('<code>kod</code>');
    expect($html)->toContain('<table>');
    expect($html)->toContain('<th>a</th>');
    expect($html)->toContain('<a ');
});

/*
 * `html_input: escape` är hela skillnaden mellan en ritning och ett XSS-hål:
 * anteckningen ritas med `v-html` i vyn (ADR-0066 § 3), så allt som servern
 * släpper igenom kör webbläsaren.
 */
it('ritar HTML i texten som text', function () {
    $html = (new RenderItemNotes)->handle("<script>alert('x')</script>\n\n<b>fet</b>");

    expect($html)->toContain('&lt;script&gt;');
    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;b&gt;');
    expect($html)->not->toContain('<b>');
});

/*
 * `allow_unsafe_links: false` gör att en `javascript:`-adress renderas som
 * text — ankaret får ingen `href` och går därför inte att klicka.
 */
it('gör en javascript-länk till text utan href', function () {
    $html = (new RenderItemNotes)->handle('[klicka](javascript:alert(1))');

    expect($html)->toContain('<a>klicka</a>');
    expect($html)->not->toContain('href');
    expect($html)->not->toContain('javascript:');
});

/*
 * `ExternalLinkExtension` sätter `target="_blank"` och alla tre `rel`-tokens.
 * Ordningen är bibliotekets, så testet prövar tokens och inte strängen —
 * det som betyder något är att ingen av dem saknas.
 */
it('öppnar en extern länk i en ny flik med noopener, noreferrer och nofollow', function () {
    $html = (new RenderItemNotes)->handle('[Extern](https://example.com/sida)');

    expect($html)->toContain('href="https://example.com/sida"');
    expect($html)->toContain('target="_blank"');

    foreach (['noopener', 'noreferrer', 'nofollow'] as $token) {
        expect($html)->toContain($token);
    }
});

it('ger null för en tom eller blank anteckning', function () {
    $action = new RenderItemNotes;

    expect($action->handle(null))->toBeNull();
    expect($action->handle(''))->toBeNull();
    expect($action->handle(" \n\t "))->toBeNull();
});
