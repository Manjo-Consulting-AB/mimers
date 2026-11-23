<?php

use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpMarkdown;
use App\Support\Help\HelpRendered;
use Illuminate\Support\Facades\Cache;

/*
 * M80 · issue 463 — hjälpartiklarnas rendering ur [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Proven ritar markdown direkt med HelpMarkdown och prövar den HTML som
 * league/commonmark ger. Sidan som visar resultatet byggs i 464; här prövas
 * bara ritningen — faktarutor, interna länkar, bilder, video, ankare,
 * externa länkar och de säkerhetsinställningar 461 lämnade efter sig.
 */

/** Ritar markdownen med appens inställningar. */
function hjalpRitning(string $markdown): HelpRendered
{
    return (new HelpMarkdown)->render($markdown);
}

it('ritar en faktaruta med rubriken ur ui.php', function () {
    $html = hjalpRitning("> [!WARNING]\n> Careful now.\n")->html;

    expect($html)->toContain('<aside class="help-callout help-callout-warning" role="note">')
        ->and($html)->toContain('<p class="help-callout-title">Warning</p>')
        ->and($html)->toContain('Careful now.')
        ->and($html)->not->toContain('[!WARNING]');
});

it('ritar note, tip och warning med sina egna rubriker', function () {
    expect(hjalpRitning("> [!NOTE]\n> A note.\n")->html)
        ->toContain('help-callout help-callout-note')
        ->toContain('<p class="help-callout-title">Note</p>');

    expect(hjalpRitning("> [!TIP]\n> A tip.\n")->html)
        ->toContain('help-callout help-callout-tip')
        ->toContain('<p class="help-callout-title">Tip</p>');
});

it('låter ett vanligt citat vara blockquote', function () {
    $html = hjalpRitning("> Ett vanligt citat.\n")->html;

    expect($html)->toContain('<blockquote>')
        ->and($html)->not->toContain('help-callout');
});

it('skiljer markören från ett citat som börjar med annan text', function () {
    $html = hjalpRitning("> [!WARNING] med text på samma rad\n")->html;

    expect($html)->toContain('<blockquote>')
        ->and($html)->not->toContain('help-callout');
});

it('ritar en intern länk med ankare till artikelsidan', function () {
    $html = hjalpRitning('[x](help:how-do-i-share-a-container#step-2)')->html;

    expect($html)->toContain('href="/help/articles/how-do-i-share-a-container#step-2"');
});

it('ritar en intern länk utan ankare och en till ett okänt id', function () {
    expect(hjalpRitning('[x](help:how-do-i-share-a-container)')->html)
        ->toContain('href="/help/articles/how-do-i-share-a-container"');

    // Okänt id ritas ändå; det är HelpValidator som fäller det.
    expect(hjalpRitning('[x](help:finns-inte)')->html)
        ->toContain('href="/help/articles/finns-inte"');
});

it('ritar bara bilder ur help-media, med alt och lazy loading', function () {
    $html = hjalpRitning('![Dialog](help-media/a.png)')->html;

    preg_match('~<img[^>]*>~', $html, $bild);

    expect($bild[0] ?? '')->toContain('src="/help-media/a.png"')
        ->and($bild[0] ?? '')->toContain('alt="Dialog"')
        ->and($bild[0] ?? '')->toContain('loading="lazy"');
});

it('ritar ingen bild med extern adress, annan sökväg eller data-adress', function () {
    foreach ([
        '![x](https://example.com/a.png)',
        '![x](/annat/a.png)',
        '![x](data:image/png;base64,AAAA)',
    ] as $markdown) {
        $html = hjalpRitning($markdown)->html;

        expect($html)->not->toContain('<img')
            ->and($html)->not->toContain('<p></p>');
    }
});

it('ritar en video som en ruta med en vanlig länk och ingen iframe', function () {
    $html = hjalpRitning('[Sharing](https://youtu.be/dQw4w9WgXcQ)')->html;

    expect($html)->toContain('<figure class="help-video" data-youtube-id="dQw4w9WgXcQ">')
        ->and($html)->toContain('<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" class="help-video-start">Sharing</a>')
        ->and($html)->not->toContain('<iframe');
});

it('ritar watch-adressen som video och en länk mitt i en mening som länk', function () {
    expect(hjalpRitning('[Se den](https://www.youtube.com/watch?v=dQw4w9WgXcQ)')->html)
        ->toContain('data-youtube-id="dQw4w9WgXcQ"');

    $html = hjalpRitning('En mening med [Sharing](https://youtu.be/dQw4w9WgXcQ) i.')->html;

    expect($html)->not->toContain('help-video')
        ->and($html)->toContain('href="https://youtu.be/dQw4w9WgXcQ"');
});

it('ritar ingen video för ett id som inte är elva tecken', function () {
    $html = hjalpRitning('[Sharing](https://youtu.be/kort)')->html;

    expect($html)->not->toContain('help-video')
        ->and($html)->toContain('href="https://youtu.be/kort"');
});

it('ger rubrikerna id ur texten och löser krockar med -2 och -3', function () {
    $rendered = hjalpRitning("## Rubrik\n\n## Rubrik\n\n## Rubrik\n\n### Steg två {#step-2}\n");

    expect($rendered->headings)->toBe([
        ['id' => 'rubrik', 'title' => 'Rubrik', 'level' => 2],
        ['id' => 'rubrik-2', 'title' => 'Rubrik', 'level' => 2],
        ['id' => 'rubrik-3', 'title' => 'Rubrik', 'level' => 2],
        ['id' => 'step-2', 'title' => 'Steg två', 'level' => 3],
    ]);

    expect($rendered->html)
        ->toContain('<h2 id="rubrik">Rubrik</h2>')
        ->toContain('<h2 id="rubrik-2">Rubrik</h2>')
        ->toContain('<h2 id="rubrik-3">Rubrik</h2>')
        ->toContain('<h3 id="step-2">Steg två</h3>');
});

it('använder det egna id:t och räknar det som upptaget', function () {
    $rendered = hjalpRitning("## Steg två {#step-2}\n\n## Steg två\n");

    expect(array_column($rendered->headings, 'id'))->toBe(['step-2', 'steg-tva']);
});

it('ger rubrikens titel utan formatering i rubriklistan', function () {
    $rendered = hjalpRitning("## Steg **två**\n");

    expect($rendered->headings)->toBe([
        ['id' => 'steg-tva', 'title' => 'Steg två', 'level' => 2],
    ]);
});

it('ger ren text utan taggar och med blanksteg ihopslagna', function () {
    $text = hjalpRitning("## Rubrik\n\nEtt   stycke\n\nmed **fet** text och [en länk](https://example.com).\n")->text;

    expect($text)->toBe('Rubrik Ett stycke med fet text och en länk.');
});

it('tar bort script, iframe och javascript-länkar', function () {
    $html = hjalpRitning("<script>alert(1)</script>\n\n<iframe src=\"https://example.com\"></iframe>\n\n[x](javascript:alert(1))")->html;

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('javascript:');
});

it('ger en extern länk rel noopener noreferrer och target blank', function () {
    $html = hjalpRitning('[x](https://example.com/sida)')->html;

    expect($html)->toContain('rel="noopener noreferrer"')
        ->and($html)->toContain('target="_blank"');
});

it('ger en intern hjälplänk ingen target', function () {
    $html = hjalpRitning('[x](help:how-do-i-share-a-container)')->html;

    expect($html)->not->toContain('target="_blank"');
});

it('behåller toHtml som ett tunt omslag', function () {
    expect((new HelpMarkdown)->toHtml(''))->toBeNull()
        ->and((new HelpMarkdown)->toHtml('   '))->toBeNull()
        ->and((new HelpMarkdown)->toHtml('**fet**'))->toBe('<p><strong>fet</strong></p>'.PHP_EOL);
});

it('ger tom html, tom text och inga rubriker för tom markdown', function () {
    $rendered = hjalpRitning('');

    expect($rendered->html)->toBe('')
        ->and($rendered->text)->toBe('')
        ->and($rendered->headings)->toBe([]);
});

it('ritar och cachar en artikel genom katalogen', function () {
    // Butiken serialiserar värdet i appen (config/cache.php:
    // serializable_classes => false), så provet slår på serialiseringen och
    // låter HelpCatalog hämta det cachade resultatet en andra gång.
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');

    $katalog = (new HelpLibrary(__DIR__.'/fixtures/katalog'))->catalog('en');
    $artikel = $katalog->article('how-do-i-share-a-container');

    expect($artikel)->not->toBeNull();

    $första = $katalog->rendered($artikel);
    $andra = $katalog->rendered($artikel);

    expect($första->html)->toContain('<strong>Share</strong>')
        ->and($första->text)->toContain('Open the container and choose Share.')
        ->and($första->headings)->toBe([])
        ->and($andra->toArray())->toBe($första->toArray());
});
