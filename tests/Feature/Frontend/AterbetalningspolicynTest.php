<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M86 Integritetspolicyn · återbetalningspolicyn på /refund-policy.
 *
 * Texten bor i `lang/en/pages/refund-policy.md` och ritas på servern av
 * `App\Support\InfoPage` med `HelpMarkdown`, [[ADR-0094 Hjälpcentret]] § 4.
 * Sidan `Info.vue` får HTML:en i `body` och ritar den med `v-html`; ingen ny
 * kod behövs utöver en rad i ruttlistan, en nycklar i `lang/en/ui.php` och
 * länken i sidfoten och på inloggningssidan.
 *
 * Sidan är Mimers egen: Mail Shields allmänna text om Paddle som Merchant of
 * Record gäller, men Lifetime-köp, sticky addresses, inbjudningsbonusar och
 * kontonivån *Regular* hör till Mail Shield och står inte här.
 * Kontaktvägen är formuläret på /contact, aldrig en e-postadress.
 *
 * Hjälparna har prefixet `aterbetalningspolicyn` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * `body`-proppen från /refund-policy.
 */
function aterbetalningspolicynKropp(): string
{
    withoutVite();

    $svar = get('/refund-policy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'refund-policy')
    );

    $body = $svar->inertiaProps()['body'];

    expect($body)->toBeString();

    return (string) $body;
}

/**
 * Källkoden för en fil under resources/, utan kommentarer — så att en
 * förklaring i ett docblock inte kan få ett prov att tro att koden gör något.
 */
function aterbetalningspolicynKalla(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);

    return (string) preg_replace('#<!--.*?-->#s', '', $kod);
}

/*
 * Klart när: `GET /refund-policy` ger `Info` med `page = refund-policy` och en
 * `body` som inte är `null`, med de fem numrerade rubrikerna som `<h2>`, raden
 * *Last updated: 25 November 2026* och ingen `<h1>`.
 */
it('visar de fem numrerade rubrikerna som h2 och uppdateringsdatumet', function () {
    $body = aterbetalningspolicynKropp();

    $rubriker = [
        '1. 30-day money-back guarantee',
        '2. How to ask for a refund',
        '3. What happens to your account',
        '4. Cancelling without a refund',
        '5. After 30 days',
    ];

    $senast = -1;

    foreach ($rubriker as $rubrik) {
        expect($body)->toContain('>'.$rubrik.'</h2>');

        $position = strpos($body, $rubrik);

        expect($position)->not->toBeFalse("rubriken \"{$rubrik}\" saknas i body");
        expect($position)->toBeGreaterThan($senast, "rubriken \"{$rubrik}\" står i fel ordning");

        $senast = $position;
    }

    expect(substr_count($body, '<h2'))->toBe(5)
        ->and($body)->toContain('Last updated: 25 November 2026')
        ->and($body)->not->toContain('<h1');
});

/*
 * Klart när: gäst och inloggad får `200` med samma `body`.
 */
it('ger en gäst och en inloggad samma body', function () {
    $gast = aterbetalningspolicynKropp();

    withoutVite();

    $inloggad = actingAs(User::factory()->create())
        ->get('/refund-policy')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'refund-policy')
        );

    expect($inloggad->inertiaProps()['body'])->toBe($gast);
});

/*
 * Klart när: ruttnamnet är `info.refund-policy`, och sidans titel och
 * beskrivning kommer ur `info.refund-policy.title` och
 * `info.refund-policy.description`.
 */
it('heter info.refund-policy och hämtar titel och beskrivning ur ui.php', function () {
    expect(route('info.refund-policy', [], false))->toBe('/refund-policy')
        ->and(trans('ui.info.refund-policy.title', [], 'en'))->toBe('Refund policy')
        ->and(trans('ui.info.refund-policy.description', [], 'en'))->toBe('How to get your money back for Mimers Pro and Pro+.');
});

/*
 * Klart när: `body` innehåller *within 30 days*, *Merchant of Record*,
 * *founder price*, *Billing &amp; Subscription* (som HTML), *read-only*,
 * *Manage billing* och länken `https://paddle.net`.
 */
it('bär garantin, Paddle som Merchant of Record, grundarpriset och knappen', function () {
    $body = aterbetalningspolicynKropp();

    expect($body)->toContain('within 30 days')
        ->and($body)->toContain('Merchant of Record')
        ->and($body)->toContain('founder price')
        ->and($body)->toContain('Billing &amp; Subscription')
        ->and($body)->toContain('read-only')
        ->and($body)->toContain('Manage billing')
        ->and($body)->toContain('href="https://paddle.net"');
});

/*
 * Klart när: `body` länkar till `/contact` (två ställen), `/terms` och
 * `/privacy`, och har ingen `mailto:`, ingen e-postadress, inget `manjo.me` och
 * varken *Lifetime*, *sticky*, *Regular* eller *Mail Shield*.
 */
it('länkar /contact, /terms och /privacy utan e-postadress eller Mail Shield-ord', function () {
    $body = aterbetalningspolicynKropp();

    expect(substr_count($body, 'href="/contact"'))->toBe(2)
        ->and($body)->toContain('href="/terms"')
        ->and($body)->toContain('href="/privacy"')
        ->and($body)->not->toContain('mailto:')
        ->and($body)->not->toContain('@')
        ->and($body)->not->toContain('manjo.me')
        ->and($body)->not->toContain('Lifetime')
        ->and($body)->not->toContain('sticky')
        ->and($body)->not->toContain('Regular')
        ->and($body)->not->toContain('Mail Shield');
});

/*
 * Klart när: länken *Refund policy* till `/refund-policy` finns i sidfoten och
 * på inloggningssidan, efter *Terms of use*. Länkarna under kortet bor sedan
 * issue 1671 i skalet som /login och /register delar.
 */
it('lägger Refund policy efter Terms of use i sidfoten och på inloggningssidan', function () {
    $sidfot = aterbetalningspolicynKalla('js/layouts/AppLayout.vue');
    $inloggning = aterbetalningspolicynKalla('js/components/AuthSplitLayout.vue');

    foreach (['js/layouts/AppLayout.vue' => $sidfot, 'js/components/AuthSplitLayout.vue' => $inloggning] as $fil => $kod) {
        expect($kod)->toContain('href="/refund-policy"')
            ->and($kod)->toContain("t('auth.landing.links.refund_policy')");

        expect((int) strpos($kod, 'href="/terms"'))
            ->toBeLessThan((int) strpos($kod, 'href="/refund-policy"'), "{$fil} lägger Refund policy före Terms of use");
    }

    expect(trans('ui.auth.landing.links.refund_policy', [], 'en'))->toBe('Refund policy');
});

/*
 * Klart när: `/about` och `/subprocessors` ritar som förut.
 */
// vakt: regressionsvakt — /about och /subprocessors ska rita som förut, så provet är grönt på basen med flit.
it('lämnar /about och /subprocessors orörda', function () {
    withoutVite();

    $about = get('/about')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'about')
    );

    $underbitraden = get('/subprocessors')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Info')
        ->where('page', 'subprocessors')
    );

    expect((string) $about->inertiaProps()['body'])->toContain('Structure on your terms.')
        ->and((string) $underbitraden->inertiaProps()['body'])->toContain('Inleed');
});
