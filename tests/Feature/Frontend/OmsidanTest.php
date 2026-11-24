<?php

use App\Models\User;
use App\Support\InfoPage;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M85 Om Mimers · issue 478 (GitHub #1617) — berättelsen på /about.
 *
 * Texten bor i `lang/en/pages/about.md` och ritas på servern av
 * `App\Support\InfoPage` med `HelpMarkdown`, [[ADR-0094 Hjälpcentret]] § 4.
 * Sidan `Info.vue` får den färdiga HTML:en i `body` och ritar den med
 * `v-html`; den bär ingen egen copy.
 *
 * Filen prövar innehållet i `body`, att gäst och inloggad får samma svar,
 * `InfoPage`-klassens reserv och HTML-strippning, samt att komponenten ritar
 * `body` och inte har kopierat texten. Ruttnamnen, platshållaren för /terms
 * och rubrikerna prövas i InfosidorTest.
 *
 * Hjälparna har prefixet `omsidan` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En tom katalog att lägga provfiler i. `InfoPage` tar roten i konstruktorn,
 * som `HelpLibrary` gör.
 */
function omsidanKatalog(): string
{
    $rot = storage_path('framework/testing/omsidan-'.uniqid('', true));

    File::makeDirectory($rot, 0755, true);

    return $rot;
}

/**
 * `body`-proppen från en informationssida.
 */
function omsidanKropp(string $adress): string
{
    withoutVite();

    $svar = get($adress)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', ltrim($adress, '/'))
    );

    $body = $svar->inertiaProps()['body'];

    expect($body)->toBeString();

    return (string) $body;
}

it('visar varje avsnitt i berättelsen på /about', function () {
    $body = omsidanKropp('/about');

    $rubriker = [
        'Structure on your terms.',
        'Born out of a real need',
        'One system. Many possibilities.',
        'Information should have a life beyond ownership',
        'Powerful, without the clutter',
        'A name rooted in wisdom',
        'Built by Manjo Consulting AB',
    ];

    $senast = -1;

    foreach ($rubriker as $rubrik) {
        $position = strpos($body, $rubrik);

        expect($position)->not->toBeFalse("rubriken \"{$rubrik}\" saknas i body");
        expect($position)->toBeGreaterThan($senast, "rubriken \"{$rubrik}\" står i fel ordning");

        $senast = $position;
    }

    expect($body)->toContain('Structure on your terms.</h2>')
        ->and($body)->toContain('Mimers — Structure on your terms.');
});

it('namnger Mímir och Mímisbrunnr i avsnittet om namnet', function () {
    $body = omsidanKropp('/about');

    $start = strpos($body, 'A name rooted in wisdom');
    $slut = strpos($body, 'Built by Manjo Consulting AB');

    expect($start)->not->toBeFalse()->and($slut)->not->toBeFalse();

    $avsnitt = substr($body, (int) $start, (int) $slut - (int) $start);

    expect($avsnitt)->toContain('Mímir')->and($avsnitt)->toContain('Mímisbrunnr');
});

it('länkar Get in touch till /contact utan e-postadress', function () {
    $body = omsidanKropp('/about');

    expect($body)->toContain('href="/contact"')
        ->and($body)->toContain('Get in touch')
        ->and($body)->not->toContain('mailto:')
        ->and($body)->not->toContain('@');
});

it('nämner container och PDF-pärmen men inte kontoexporten', function () {
    $body = omsidanKropp('/about');

    expect($body)->not->toContain('copy of your account')
        ->and($body)->toContain('a complete copy of a container')
        ->and($body)->toContain('PDF binder');
});

it('ger en gäst och en inloggad samma body', function () {
    $gast = omsidanKropp('/about');

    withoutVite();

    $inloggad = actingAs(User::factory()->create())
        ->get('/about')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'about')
        );

    expect($inloggad->inertiaProps()['body'])->toBe($gast);
});

it('ger null för en sida utan fil', function () {
    expect((new InfoPage)->html('terms'))->toBeNull();
});

it('faller tillbaka på engelskan när språkets fil saknas', function () {
    $rot = omsidanKatalog();

    File::makeDirectory($rot.'/en/pages', 0755, true);
    File::put($rot.'/en/pages/about.md', "## English\n\nEngelsk text.\n");
    File::makeDirectory($rot.'/sv/pages', 0755, true);
    File::put($rot.'/sv/pages/about.md', "## Svenska\n\nSvensk text.\n");

    $info = new InfoPage($rot);

    expect((string) $info->html('about', 'sv'))->toContain('Svensk text.');
    expect((string) $info->html('about', 'de'))->toContain('Engelsk text.');

    File::deleteDirectory($rot);
});

it('släpper inte igenom rå HTML ur filen', function () {
    $rot = omsidanKatalog();

    File::makeDirectory($rot.'/en/pages', 0755, true);
    File::put($rot.'/en/pages/about.md', "<script>alert('x')</script>\n\nVanlig text.\n");

    $html = (string) (new InfoPage($rot))->html('about');

    expect($html)->toContain('Vanlig text.');
    expect($html)->not->toContain('<script>');

    File::deleteDirectory($rot);
});

it('ritar body med v-html i Info.vue och bär ingen egen copy', function () {
    $kod = File::get(resource_path('js/pages/Info.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    expect($kod)->toContain('v-html="body"');
    expect($kod)->toContain('help-content');
    expect($kod)->toMatch('/v-if="body"/');
    expect($kod)->toMatch('/v-else/');

    foreach ([
        'Structure on your terms',
        'Born out of a real need',
        'Mimers brings it all together',
        'Your information remains yours',
        'Get in touch',
        'Mímir',
    ] as $mening) {
        expect($kod)->not->toContain($mening);
    }
});
