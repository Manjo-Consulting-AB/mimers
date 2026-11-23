<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M81 · issue 467 — sökrutan i hjälpen och träfflistan,
 * [[ADR-0094 Hjälpcentret]] § 5.
 *
 * Serverns del prövas mot den riktiga katalogen under lang/en/help, för det
 * är den sökrutan möter. Det som händer i webbläsaren — att pilarna flyttar
 * markeringen, att Enter öppnar träffen och att Escape stänger listan — är
 * klientsidans och prövas som källkod; flödet prövas för hand i PR:en.
 */

/** Källkoden med kommentarer borta — en regel ska inte nöjas av en mening. */
function sokrutaKod(string $sokvag): string
{
    $kod = File::get(resource_path('js/'.$sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: `GET /help?q=share` renderar `Help` med `search.results` där
 * första träffen är `how-do-i-share-a-container`, och HTML-svaret har
 * `noindex`.
 */
it('renderar träfflistan på /help?q=share med noindex', function () {
    withoutVite();

    $svar = get('/help?q=share')->assertOk();

    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Help')
        ->where('q', 'share')
        ->where('search.query', 'share')
        ->where('search.partial', false)
        ->has('search.results')
        ->where('search.results.0.id', 'how-do-i-share-a-container')
        ->where('search.results.0.url', '/help/articles/how-do-i-share-a-container')
        ->where('search.results.0.category.title', 'Sharing')
        ->where('search.results.0.title', [
            ['text' => 'How do I ', 'match' => false],
            ['text' => 'share', 'match' => true],
            ['text' => ' a container?', 'match' => false],
        ])
        ->has('search.results.0.snippet'));

    $svar->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('<title inertia>Search: share · '.config('app.name').'</title>', false);
});

/*
 * Klart när: `GET /help` utan `q` har ingen `search`-prop och inget
 * `noindex`.
 */
it('lämnar startsidan utan sökning när q saknas', function () {
    withoutVite();

    $svar = get('/help')->assertOk();

    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Help')
        ->missing('q')
        ->missing('search'));

    $svar->assertDontSee('<meta name="robots"', false);
});

it('renderar en tom träfflista med noindex när inget matchar', function () {
    withoutVite();

    get('/help?q=zebraquokka')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Help')
            ->has('search.results', 0))
        ->assertSee('<meta name="robots" content="noindex">', false);
});

/*
 * Klart när: källkodsprov för combobox-mönstret — `role="combobox"`,
 * `aria-expanded`, `aria-activedescendant`, `role="listbox"`,
 * `role="option"` och en `aria-live`-region.
 */
it('bär combobox-mönstret med listbox och en live-region', function () {
    $kod = sokrutaKod('components/HelpSearchBox.vue');

    expect($kod)->toContain('role="combobox"')
        ->toContain('aria-expanded')
        ->toContain('aria-controls')
        ->toContain('aria-activedescendant')
        ->toContain('role="listbox"')
        ->toContain('role="option"')
        ->toContain('aria-live="polite"');
});

/*
 * Klart när: komponenten och `Help.vue` använder inte `v-html` för
 * sökträffar, och `<mark>` ritas ur `match`.
 */
it('ritar träffarna som segment med mark och aldrig ur en HTML-sträng', function () {
    $komponent = sokrutaKod('components/HelpSearchBox.vue');
    $sida = sokrutaKod('pages/Help.vue');

    foreach ([$komponent, $sida] as $kod) {
        expect($kod)->toContain('<mark v-if="segment.match"');
        expect($kod)->not->toContain('v-html');
    }
});

/*
 * Klart när: tangenterna ArrowDown, ArrowUp, Enter och Escape hanteras, och
 * ett äldre svar kastas.
 */
it('hanterar pilarna, Enter och Escape och kastar ett äldre svar', function () {
    $kod = sokrutaKod('components/HelpSearchBox.vue');

    expect($kod)->toContain("'ArrowDown'")
        ->toContain("'ArrowUp'")
        ->toContain("'Enter'")
        ->toContain("'Escape'")
        ->toContain('isStale(')
        ->toContain('!== requestId');
});

it('söker från två tecken efter 250 ms med taket åtta', function () {
    $kod = sokrutaKod('components/HelpSearchBox.vue');

    expect($kod)->toContain('MIN_QUERY_LENGTH = 2')
        ->toContain('SEARCH_DELAY_MS = 250')
        ->toContain('/help/search?q=')
        ->toContain('limit=8');
});

/* Klart när: träfflänkarna bär `ref=search`. */
it('låter träfflänkarna bära ref=search', function () {
    expect(sokrutaKod('components/HelpSearchBox.vue'))->toContain('?ref=search');
    expect(sokrutaKod('pages/Help.vue'))->toContain('?ref=search');
});

/* Klart när: rutan finns på startsidan, kategorisidan och artikelsidan. */
it('sätter rutan på startsidan, kategorisidan och artikelsidan', function () {
    $startsida = sokrutaKod('pages/Help.vue');

    expect($startsida)->toContain('<HelpSearchBox')
        ->toContain('size="large"')
        ->toContain(':initial-query="q ?? \'\'"');

    foreach (['pages/Help/Category.vue', 'pages/Help/Article.vue'] as $sokvag) {
        expect(sokrutaKod($sokvag))->toContain('import HelpSearchBox')
            ->toContain('<HelpSearchBox size="compact"');
    }
});

/*
 * Träfflistan ersätter kategorikorten: rubriken nämner frågan,
 * *Clear search* leder tillbaka, och den tomma listan säger att inget
 * matchade (ADR-0094 § 5).
 */
it('ritar träfflistan i stället för kategorikorten', function () {
    $kod = sokrutaKod('pages/Help.vue');

    expect($kod)->toContain('v-if="search"')
        ->toContain("t('help.search.results_for'")
        ->toContain("t('help.search.clear'")
        ->toContain('v-if="!search.results.length"');
});

/* Klart när: de nya nycklarna under `help.search.` finns i lang/en/ui.php. */
it('har sökrutans ord i katalogen', function () {
    expect(trans('ui.help.search.label', [], 'en'))->toBe('Search help')
        ->and(trans('ui.help.search.placeholder', [], 'en'))->toBe('Search for an answer…')
        ->and(trans('ui.help.search.all', [], 'en'))->toBe('See all results')
        ->and(trans('ui.help.search.loading', [], 'en'))->toBe('Searching…')
        ->and(trans('ui.help.search.empty', [], 'en'))->toBe('No results for “:query”. Try fewer or different words.')
        ->and(trans('ui.help.search.error', [], 'en'))->toBe('The search could not be completed. Try again.')
        ->and(trans('ui.help.search.count', [], 'en'))->toBe(':count result|:count results')
        ->and(trans('ui.help.search.home', [], 'en'))->toBe('Go to the help home')
        ->and(trans('ui.help.search.results_for', [], 'en'))->toBe('Results for “:query”')
        ->and(trans('ui.help.search.clear', [], 'en'))->toBe('Clear search')
        ->and(trans('ui.help.search.title', [], 'en'))->toBe('Search: :query');
});
