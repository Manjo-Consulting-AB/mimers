<?php

use App\Models\User;
use App\Support\Help\HelpLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M80 · issue 464 — artikelsidan, /help/articles/{id}, se
 * [[ADR-0094 Hjälpcentret]] § 3, § 4 och § 10.
 *
 * Varje artikel — också varje fråga — har en egen sida. Här prövas det som går
 * att avgöra på serversidan: propparna ur katalogen, grannarna i kategorins
 * ordning, de relaterade artiklarna i front matterns ordning, 404 för ett
 * okänt id och för ett utkast i produktion, och sidhuvudet som servern skriver.
 *
 * Testkatalogen under fixtures/katalog används där det riktiga innehållet
 * saknar det som ska prövas: ingen av de 66 artiklarna har `related` eller
 * rubriker. Den binds som HelpLibrary i containern, så att controllern läser
 * den i stället för lang/.
 *
 * Det som kräver en webbläsare — att videon laddar YouTube först vid klick,
 * att innehållsförteckningen bara syns vid tre rubriker och att ett `#ankare`
 * scrollas fram — prövas som källkod här och för hand i PR:en.
 */

/** En tillfällig kopia av testkatalogen, att ändra i. */
function artikelKatalog(): string
{
    $mål = sys_get_temp_dir().'/artikelsidan-'.Str::random(12);
    File::copyDirectory(__DIR__.'/fixtures/katalog', $mål);

    return $mål;
}

/** Låter HelpLibrary läsa testkatalogen i stället för lang/. */
function artikelBibliotek(string $rot): void
{
    app()->instance(HelpLibrary::class, new HelpLibrary($rot));
}

it('renderar Help/Article med artikeln, kategorin och grannarna för gäst och inloggad', function () {
    withoutVite();

    $kontroll = fn (AssertableInertia $page) => $page
        ->component('Help/Article')
        ->where('article.id', 'how-do-i-share-a-container')
        ->where('article.title', 'How do I share a container?')
        ->where('article.type', 'faq')
        ->where('article.description', null)
        ->where('article.updated', '2026-11-22')
        ->where('article.html', fn (string $html): bool => str_contains($html, 'Open the container'))
        ->has('article.headings', 0)
        ->where('category.id', 'sharing')
        ->where('category.title', 'Sharing')
        ->has('related', 0)
        ->where('previous', null)
        ->where('next.id', 'what-can-the-people-i-share-with-do')
        ->where('next.title', 'What can the people I share with do?');

    get('/help/articles/how-do-i-share-a-container')->assertOk()->assertInertia($kontroll);
    actingAs(User::factory()->create())
        ->get('/help/articles/how-do-i-share-a-container')
        ->assertOk()
        ->assertInertia($kontroll);
});

it('ger de relaterade artiklarna i front matterns ordning', function () {
    withoutVite();

    $rot = artikelKatalog();
    $fil = $rot.'/en/help/articles/how-do-i-share-a-container.md';

    File::put($fil, (string) preg_replace(
        '/^related:.*$/m',
        'related: [container-limits, saknas, what-can-the-people-i-share-with-do]',
        File::get($fil),
    ));

    artikelBibliotek($rot);

    get('/help/articles/how-do-i-share-a-container')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Help/Article')
            // Ordningen är front matterns, inte katalogens: container-limits
            // står i en tidigare kategori än what-can-the-people-i-share-with-do.
            ->has('related', 2)
            ->where('related.0.id', 'container-limits')
            ->where('related.0.title', 'Container limits')
            ->where('related.0.type', 'guide')
            ->where('related.1.id', 'what-can-the-people-i-share-with-do')
            ->where('related.1.type', 'faq'),
    );
});

it('ger bara rubrikerna på nivå 2 till innehållsförteckningen', function () {
    withoutVite();

    $rot = artikelKatalog();
    $fil = $rot.'/en/help/articles/how-do-i-share-a-container.md';

    File::put($fil, <<<'MD'
---
id: how-do-i-share-a-container
title: How do I share a container?
type: faq
category: sharing
order: 10
updated: "2026-11-22"
---
Open the container.

## First step

Do this.

## Second step

Do that.

### A detail

Not in the contents.

## Third step

Done.
MD);

    artikelBibliotek($rot);

    get('/help/articles/how-do-i-share-a-container')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Help/Article')
            ->has('article.headings', 3)
            ->where('article.headings.0.id', 'first-step')
            ->where('article.headings.0.title', 'First step')
            ->where('article.headings.1.id', 'second-step')
            ->where('article.headings.2.id', 'third-step'),
    );
});

it('ger 404 för ett okänt id', function () {
    get('/help/articles/finns-inte')->assertNotFound();
});

it('visar ett utkast utanför produktion och ger 404 i produktion', function () {
    withoutVite();

    artikelBibliotek(__DIR__.'/fixtures/katalog');

    get('/help/articles/draft-article')->assertOk();

    app()->detectEnvironment(fn (): string => 'production');

    get('/help/articles/draft-article')->assertNotFound();
});

it('registrerar artikelsidan före kategorisidan', function () {
    $rutter = Route::getRoutes()->getRoutes();

    $artikel = null;
    $kategori = null;

    foreach ($rutter as $index => $rutt) {
        if ($rutt->getName() === 'help.article') {
            $artikel = $index;
        }

        if ($rutt->getName() === 'help.category') {
            $kategori = $index;
        }
    }

    expect($artikel)->not->toBeNull()
        ->and($kategori)->not->toBeNull()
        ->and($artikel)->toBeLessThan($kategori)
        ->and(route('help.article', 'how-do-i-share-a-container', false))
        ->toBe('/help/articles/how-do-i-share-a-container');
});

it('skriver titel, beskrivning och kanonisk adress i HTML:en', function () {
    withoutVite();

    $svar = get('/help/articles/how-do-i-share-a-container')->assertOk();

    /** @var array{title: string, description: string, canonical: string} $meta */
    $meta = $svar->viewData('meta');

    expect($meta['title'])->toBe('How do I share a container?')
        ->and($meta['canonical'])->toBe(route('help.article', 'how-do-i-share-a-container'))
        // En fråga utan `description` får de första 160 tecknen av texten.
        ->and(mb_strlen($meta['description']))->toBe(160)
        ->and($meta['description'])->toStartWith('Open the container, go to Sharing and invite someone by email.');

    $svar->assertSee(
        '<title inertia>How do I share a container? · '.config('app.name').'</title>',
        false,
    )
        ->assertSee('<meta name="description" content="', false)
        ->assertSee(
            '<link rel="canonical" href="'.route('help.article', 'how-do-i-share-a-container').'"',
            false,
        );
});

it('använder artikelns egen beskrivning när den finns', function () {
    withoutVite();

    artikelBibliotek(__DIR__.'/fixtures/katalog');

    /** @var array{title: string, description: string, canonical: string} $meta */
    $meta = get('/help/articles/container-limits')->assertOk()->viewData('meta');

    expect($meta['description'])->toBe('What a container may hold and how large it may be.');
});

it('skriver meta för kategorisidan och startsidan också', function () {
    withoutVite();

    get('/help/sharing')->assertOk()->assertSee(
        '<link rel="canonical" href="'.route('help.category', 'sharing').'"',
        false,
    );

    get('/help')->assertOk()->assertSee(
        '<meta name="description" content="Answers to common questions about Mimers."',
        false,
    );
});

it('lämnar sidhuvudet orört på en sida utan meta', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('<meta name="description"', false)
        ->assertSee('<title inertia>'.config('app.name').'</title>', false);
});

it('har artikelsidans ord i katalogen', function () {
    expect(trans('ui.help.types.faq', [], 'en'))->toBe('Question')
        ->and(trans('ui.help.types.guide', [], 'en'))->toBe('Guide')
        ->and(trans('ui.help.types.reference', [], 'en'))->toBe('Reference')
        ->and(trans('ui.help.types.troubleshooting', [], 'en'))->toBe('Troubleshooting')
        ->and(trans('ui.help.updated', [], 'en'))->toBe('Updated :date')
        ->and(trans('ui.help.contents', [], 'en'))->toBe('On this page')
        ->and(trans('ui.help.related', [], 'en'))->toBe('Related')
        ->and(trans('ui.help.previous', [], 'en'))->toBe('Previous')
        ->and(trans('ui.help.next', [], 'en'))->toBe('Next');
});

/*
 * Källkodsprov. Beteendet kräver en webbläsare — att klicket byter rutan mot
 * en spelare, att tröskeln håller en kort artikel utan innehållsförteckning —
 * och det som går att avgöra på serversidan är att koden gör det issuen säger.
 */
it('ritar innehållsförteckningen bara vid minst tre rubriker', function () {
    $kod = File::get(resource_path('js/pages/Help/Article.vue'));

    expect($kod)->toContain('v-if="article.headings.length >= 3"');
});

it('byter in en iframe mot youtube-nocookie först vid klick', function () {
    $kod = File::get(resource_path('js/pages/Help/Article.vue'));

    expect($kod)
        ->toContain('.help-video-start')
        ->toContain("addEventListener('click', startVideo)")
        ->toContain('document.createElement')
        ->toContain('https://www.youtube-nocookie.com/embed/')
        ->toContain('preventDefault')
        // Id:t prövas mot YouTubes eget mönster innan det blir en adress.
        ->toContain('/^[A-Za-z0-9_-]{11}$/');
});
