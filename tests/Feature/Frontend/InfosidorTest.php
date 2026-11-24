<?php

// rott-pa-basen: omdöpta testfall (tre sidor blir fyra); /help-delen prövas i HjalpsidanTest, som är rött på basen.

use App\Models\User;
use Illuminate\Support\Facades\File;
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
 * `App\Support\InfoPage` och `lang/{locale}/pages/{sida}.md`. Alla fyra har en
 * fil sedan M86: /about (issue 478), /privacy (issue 479), /subprocessors
 * (issue 480) och /terms (issue 489). Innehållet i /abouts body prövas i
 * OmsidanTest, i /privacys i IntegritetspolicyTest, i /subprocessors i
 * UnderbitradenTest och i /terms i VillkorssidanTest.
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
 *
 * **Bredden och storleken** (M86 · GitHub #1645) prövas i källkoden: `body`
 * ritas i `max-w-5xl` i stället för `max-w-prose`, och modifieraren
 * `help-content--wide` ger informationssidorna 16 px brödtext och en tabell
 * som bryter rader på skrivbordsbredd. Hjälpartiklarna, som delar
 * `help-content`, ska rita i `var(--text-body)` som förut.
 */

it('renderar Info för var och en av de fyra sidorna för en gäst', function (string $sida) {
    withoutVite();

    $svar = get('/'.$sida)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Info')
            ->where('page', $sida)
        );

    expect((string) $svar->inertiaProps()['body'])->not->toBe('');
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

/**
 * Källkoden för en fil under resources/, utan kommentarer — så att en
 * förklaring i ett docblock inte kan få ett prov att tro att koden gör något.
 */
function infosidorKalla(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);

    return (string) preg_replace('#<!--.*?-->#s', '', $kod);
}

it('ritar body i en bred spalt med help-content--wide i Info.vue', function () {
    $kod = infosidorKalla('js/pages/Info.vue');

    // 65 tecken räckte till löptext men inte till underbiträdestabellens fyra
    // kolumner (M86 · GitHub #1645); behållaren är max-w-5xl i stället.
    expect($kod)->not->toContain('max-w-prose');

    preg_match('/<div\b[^>]*v-html="body"[^>]*>/s', $kod, $tagg);

    $ruta = $tagg[0] ?? '';

    expect($ruta)->not->toBe('', 'Info.vue ritar ingen behållare för body');

    foreach (['help-content', 'help-content--wide', 'max-w-5xl', 'mx-auto', 'text-left'] as $klass) {
        expect($ruta)->toMatch(
            '/class="[^"]*\b'.preg_quote($klass, '/').'\b[^"]*"/',
            "body-behållaren saknar {$klass}",
        );
    }
});

it('ger informationssidorna 16 px brödtext utan att ändra hjälpartiklarna', function () {
    $css = infosidorKalla('css/app.css');

    // Modifieraren lyfter brödtexten ett steg: 1rem = 16 px, samma som
    // Tailwinds text-base. Rubrikerna står i em och följer med.
    expect($css)->toMatch('/\.help-content--wide\s*\{[^}]*font-size:\s*(?:1rem|16px)\s*;/');

    // Den delade regeln står kvar: hjälpartiklarna ritar i var(--text-body).
    expect($css)->toMatch('/\.item-notes-content,\s*\.help-content\s*\{[^}]*font-size:\s*var\(--text-body\)/');

    // Bara Info.vue sätter modifieraren — Article.vue och Category.vue ritar
    // sitt .help-content som förut.
    foreach (['js/pages/Help/Article.vue', 'js/pages/Help/Category.vue'] as $fil) {
        expect(infosidorKalla($fil))->not->toContain('help-content--wide');
    }
});

it('låter tabellen skrolla på mobilen och bryta rader på skrivbordet', function () {
    $css = infosidorKalla('css/app.css');

    // Den delade regeln står kvar: på smal skärm skrollar tabellen i sidled.
    expect($css)->toMatch('/\.item-notes-content table,\s*\.help-content table\s*\{[^}]*display:\s*block;[^}]*overflow-x:\s*auto/');

    // Cellerna bryter mellan ord — annars delar `overflow-wrap: anywhere`
    // *Provider* mitt i ordet på skrivbordsbredd (M86 · GitHub #1645).
    expect($css)->toMatch('/\.help-content--wide th,\s*\.help-content--wide td\s*\{[^}]*overflow-wrap:\s*normal/');

    // Modifieraren tar vanlig tabellayout över brytpunkten, så att cellerna
    // bryter rader och ingen kolumn klipps (M86 · GitHub #1645).
    expect($css)->toMatch('/@media\s*\(min-width:\s*48rem\)\s*\{\s*\.help-content--wide table\s*\{[^}]*display:\s*table;/');
});
