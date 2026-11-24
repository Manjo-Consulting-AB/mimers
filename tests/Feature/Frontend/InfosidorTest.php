<?php

// rott-pa-basen: omdöpta testfall (tre sidor blir fyra); /help-delen prövas i HjalpsidanTest, som är rött på basen.

use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 202 · Informationssidorna — /about, /privacy, /terms och
 * /subprocessors. /help ritas sedan M78 · issue 458 av Help.vue och prövas i
 * HjalpsidanTest.
 *
 * Fyra adresser ritas av EN sida (resources/js/pages/Info.vue): rutten skickar
 * vilken sida det är i `page`-proppen och komponenten slår upp rubriken ur
 * `info.<sida>.title`. Filen prövar serverns halva av det kontraktet —
 * adressen, komponenten, proppen, ruttnamnen och att texterna finns.
 *
 * **Sedan M85 · issue 478 bär rutten också `body`.** Den kommer ur
 * `App\Support\InfoPage` och `lang/{locale}/pages/{sida}.md`. /about,
 * /privacy (M86 · issue 479) och /subprocessors (M86 · issue 480) har en fil;
 * /terms ger `null` och ritar platshållaren som förut. Innehållet i /abouts
 * body prövas i OmsidanTest, i /privacys i IntegritetspolicyTest och i
 * /subprocessors i UnderbitradenTest.
 *
 * Att sidan ritas i AppLayout och inte bär en enda sträng själv prövas av
 * GenomgangTest och SprakTest, som läser varje fil under resources/js; att
 * rubriken faktiskt hamnar i <Head> och <h1> är komponentens halva och syns
 * inte här. Beskrivningen i <Head> prövas i UnderbitradenTest, som äger
 * `info.<sida>.description`.
 *
 * **Publika rutter.** Både en gäst och en inloggad ska nå dem, så de ligger
 * utanför både `auth` och `guest` (routes/web.php): en plats i någon av
 * grupperna hade skickat den ena parten vidare.
 */

it('renderar Info för var och en av de fyra sidorna för en gäst', function (string $sida) {
    withoutVite();

    $svar = get('/'.$sida)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', $sida)
        );

    if ($sida === 'terms') {
        expect($svar->inertiaProps()['body'])->toBeNull();
    } else {
        expect((string) $svar->inertiaProps()['body'])->not->toBe('');
    }
})->with(['about', 'privacy', 'terms', 'subprocessors']);

it('renderar Info också för en inloggad användare', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/about')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', 'about')
        );
});

it('har en titel för varje sida och en platshållartext', function () {
    $titlar = [
        'about' => 'About Mimers',
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of use',
        'subprocessors' => 'Subprocessors',
        'help' => 'Help',
    ];

    foreach ($titlar as $sida => $titel) {
        expect(trans("ui.info.{$sida}.title", [], 'en'))->toBe($titel);
    }

    expect(trans('ui.info.placeholder', [], 'en'))->toBe('This page is coming soon.');
});

it('namnger rutterna info.about, info.privacy, info.terms, info.subprocessors och info.help', function () {
    foreach (['about', 'privacy', 'terms', 'subprocessors', 'help'] as $sida) {
        expect(route('info.'.$sida, [], false))->toBe('/'.$sida);
    }
});
