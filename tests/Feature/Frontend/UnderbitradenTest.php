<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M86 Integritetspolicyn · issue 480 (GitHub #1620) — registret över
 * underbiträden på /subprocessors och de juridiska länkarna.
 *
 * Sidan är den fjärde informationssidan och ritas av samma Info.vue som de
 * andra tre: `'subprocessors'` i listan i routes/web.php, en titelnyckel och
 * `lang/en/pages/subprocessors.md`. Texten ritas på servern av
 * `App\Support\InfoPage` med `HelpMarkdown`, [[ADR-0094 Hjälpcentret]] § 4,
 * och proven läser den färdiga HTML:en i `body` — samma grepp som
 * IntegritetspolicyTest och OmsidanTest.
 *
 * Filen äger också de två ytorna som inte är sidan själv: beskrivningen i
 * `<head>` (som Info.vue ritar ur `info.<sida>.description`) och raden med de
 * juridiska länkarna i inloggningssidan och sidfoten. Båda raderna läser
 * `auth.landing.links.*`, så provet prövar att nycklarna finns och att de
 * fyra adresserna står i sidfoten — att inloggningssidan har dem prövas också
 * av InloggningssidaTest.
 *
 * Hjälparna har prefixet `underbitraden` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * `body`-proppen från /subprocessors.
 */
function underbitradenKropp(): string
{
    withoutVite();

    $svar = get('/subprocessors')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'subprocessors')
    );

    $body = $svar->inertiaProps()['body'];

    expect($body)->toBeString();

    return (string) $body;
}

/**
 * Källkoden för en fil under resources/js, utan kommentarer — så att en
 * förklaring i ett docblock inte kan få ett prov att tro att koden gör något.
 */
function underbitradenKalla(string $sokvag): string
{
    $kod = File::get(resource_path('js/'.$sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);

    return (string) preg_replace('#<!--.*?-->#s', '', $kod);
}

it('renderar Info för en gäst och för en inloggad', function () {
    withoutVite();

    get('/subprocessors')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'subprocessors')
        );

    actingAs(User::factory()->create())
        ->get('/subprocessors')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'subprocessors')
        );
});

it('namnger rutten info.subprocessors', function () {
    expect(route('info.subprocessors', [], false))->toBe('/subprocessors');
});

it('visar uppdateringsdatumet och ritar tabellen med Inleed, Mailgun och Sentry', function () {
    $body = underbitradenKropp();

    expect(substr_count($body, '<table>'))->toBe(1)
        ->and($body)->toContain('Last updated: 23 November 2026')
        ->and($body)->toContain('<th>Provider</th>')
        ->and($body)->toContain('<th>Purpose</th>')
        ->and($body)->toContain('<th>Data and processing scope</th>')
        ->and($body)->toContain('<th>Processing location</th>');

    // Varje rad bär leverantören, syftet och platsen — inte bara namnet.
    $rader = [
        'Inleed' => ['Hosting and infrastructure', 'Sweden and France (EU)'],
        'Mailgun' => ['Email delivery', 'EU region'],
        'Sentry' => ['Technical error monitoring', 'EU region'],
    ];

    foreach ($rader as $leverantör => [$syfte, $plats]) {
        expect($body)->toContain('<td>'.$leverantör.'</td>')
            ->and($body)->toContain('<td>'.$syfte.'</td>')
            ->and($body)->toContain('<td>'.$plats.'</td>');
    }
});

it('har ingen Paddle-rad i tabellen och nämner aldrig Resend eller Amazon SES', function () {
    $body = underbitradenKropp();

    $tabell = substr($body, (int) strpos($body, '<table>'), (int) strpos($body, '</table>') - (int) strpos($body, '<table>'));

    expect($tabell)->not->toContain('Paddle')
        ->and($body)->not->toContain('Resend')
        ->and($body)->not->toContain('Amazon SES');
});

it('beskriver Paddle som merchant of record under tabellen med en länk till /privacy', function () {
    $body = underbitradenKropp();

    $slut = strpos($body, '</table>');

    expect($slut)->not->toBeFalse()
        ->and(strpos($body, 'Paddle'))->not->toBeFalse()
        ->and((int) strpos($body, 'Paddle'))->toBeGreaterThan((int) $slut)
        ->and($body)->toContain('Merchant of Record')
        ->and($body)->toContain('<a href="/privacy">Privacy Policy</a>');
});

it('har titeln Subprocessors och en beskrivning per informationssida', function () {
    expect(trans('ui.info.subprocessors.title', [], 'en'))->toBe('Subprocessors');

    $beskrivningar = [
        'about' => 'What Mimers is, why it exists and who builds it.',
        'privacy' => 'How Manjo Consulting AB processes personal data in Mimers.',
        'terms' => 'The terms for using Mimers.',
        'subprocessors' => 'The service providers that process personal data on behalf of Mimers.',
    ];

    foreach ($beskrivningar as $sida => $beskrivning) {
        expect(trans("ui.info.{$sida}.description", [], 'en'))->toBe($beskrivning);
    }

    // Raden på inloggningssidan och i sidfoten delar nycklarna — ingen egen
    // kopia under en annan nyckel.
    expect(trans('ui.auth.landing.links.subprocessors', [], 'en'))->toBe('Subprocessors')
        ->and(trans('ui.auth.landing.links.about', [], 'en'))->toBe('About Mimers')
        ->and(trans('ui.auth.landing.links.privacy', [], 'en'))->toBe('Privacy policy')
        ->and(trans('ui.auth.landing.links.terms', [], 'en'))->toBe('Terms of use');
});

it('ritar <meta name="description"> ur info.<sida>.description i Info.vue', function () {
    $kod = underbitradenKalla('pages/Info.vue');

    expect($kod)->toContain('name="description"')
        ->and($kod)->toContain('info.${page}.description')
        ->and($kod)->toContain('head-key="description"');

    // Taggen står i <Head>, annars hamnar den i <body> och når aldrig <head>.
    preg_match('/<Head\b.*<\/Head>/s', $kod, $head);

    expect($head[0] ?? '')->toContain('name="description"')
        ->and($head[0] ?? '')->toContain('head-key="description"');
});

it('länkar de fyra juridiska sidorna från sidfoten med auth.landing.links.*', function () {
    $kod = underbitradenKalla('layouts/AppLayout.vue');

    preg_match('/<footer\b.*?<\/footer>/s', $kod, $fot);

    $sidfot = $fot[0] ?? '';

    expect($sidfot)->not->toBe('', 'AppLayout.vue har ingen <footer>');

    foreach (['about' => '/about', 'privacy' => '/privacy', 'terms' => '/terms', 'subprocessors' => '/subprocessors'] as $nyckel => $adress) {
        expect($sidfot)->toContain('<Link href="'.$adress.'"')
            ->and($sidfot)->toContain("auth.landing.links.{$nyckel}");
    }

    // Raden står ovanför copyrightraden, som issuen säger.
    expect(strpos($sidfot, 'auth.landing.links.about'))->toBeLessThan(strpos($sidfot, 'common.footer'));

    // Mobilskalet ändras inte: sidfoten ritas bara över `md:`.
    expect($sidfot)->toContain('md:block')
        ->and($sidfot)->toContain('hidden');
});

it('lämnar /about, /privacy och /terms som de var', function () {
    withoutVite();

    $about = get('/about')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'about')
    );

    expect((string) $about->inertiaProps()['body'])->not->toBe('');

    $privacy = get('/privacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'privacy')
    );

    expect((string) $privacy->inertiaProps()['body'])->not->toBe('');

    $terms = get('/terms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'terms')
    );

    expect($terms->inertiaProps()['body'])->toBeNull();
});
