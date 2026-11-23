<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M80 · issue 462 — kategorisidan, /help/{kategori}, se
 * [[ADR-0094 Hjälpcentret]] § 3.
 *
 * Sidan når både en gäst och en inloggad, som /help. Frågorna är `faq` och
 * kommer i katalogens ordning; ett okänt kategori-id ger 404. Det som händer i
 * webbläsaren — att `#ankaret` öppnar rätt rad — prövas som källkod i
 * tests/Feature/Frontend/HjalpsidanTest.php och för hand i PR:en.
 */

it('renderar Help/Category med kategorin och dess tolv frågor i ordning', function () {
    withoutVite();

    $kontroll = fn (AssertableInertia $page) => $page
        ->component('Help/Category')
        ->where('category.id', 'sharing')
        ->where('category.title', 'Sharing')
        ->where('category.description', 'Invite people, set access and hand over a container.')
        ->where('category.intro_html', null)
        ->has('articles', 12)
        ->where('articles.0.id', 'how-do-i-share-a-container')
        ->where('articles.0.title', 'How do I share a container?')
        ->where('articles.0.type', 'faq')
        ->where('articles.0.answer_html', fn (string $html): bool => str_contains($html, 'Open the container'))
        ->where('articles.11.id', 'does-the-new-owner-get-a-mimers-subscription');

    get('/help/sharing')->assertOk()->assertInertia($kontroll);
    actingAs(User::factory()->create())->get('/help/sharing')->assertOk()->assertInertia($kontroll);
});

it('ger 404 för en kategori som inte finns', function () {
    get('/help/finns-inte')->assertNotFound();
});

/*
 * M80 · issue 464 — varje fråga har en egen sida, och kategorisidan länkar
 * dit bredvid kopieringsknappen. Länken prövas som källkod: den ritas av
 * Vue i webbläsaren, och det som går att avgöra här är att raden bär den och
 * att ordet finns i katalogen.
 */
it('länkar varje fråga till sin egen sida', function () {
    $kod = File::get(resource_path('js/pages/Help/Category.vue'));

    expect($kod)->toContain('`/help/articles/${article.id}`')
        ->toContain("t('help.open_page')")
        ->and(trans('ui.help.open_page', [], 'en'))->toBe('Open as a page');
});

it('ritar ingressen för en kategori som har en', function () {
    withoutVite();

    get('/help/tasks')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Help/Category')
        ->where('category.id', 'tasks')
        ->where('category.intro_html', fn (string $html): bool => str_contains($html, 'Getting Things Done'))
        ->has('articles', 7));
});
