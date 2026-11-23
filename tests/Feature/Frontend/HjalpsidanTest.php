<?php

use App\Actions\Help\LoadHelpArticles;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M78 · issue 458 — hjälpsidan. Texterna är markdownfiler under lang/en/help/,
 * LoadHelpArticles läser dem och HelpController skickar dem till Help.vue.
 */

function hjälpKategorier(): array
{
    return (new LoadHelpArticles)->handle(lang_path('en/help'));
}

it('renderar Help med nio kategorier i filordning för en gäst och en inloggad', function () {
    withoutVite();

    $kontroll = fn (AssertableInertia $page) => $page
        ->component('Help')
        ->has('categories', 9)
        ->where('categories.0.slug', 'getting-started')
        ->where('categories.0.title', 'Getting started')
        ->where('categories.8.slug', 'privacy-and-data');

    get('/help')->assertOk()->assertInertia($kontroll);
    actingAs(User::factory()->create())->get('/help')->assertOk()->assertInertia($kontroll);
});

it('ger kategorin sharing frågorna i filordning med ankare', function () {
    $sharing = collect(hjälpKategorier())->firstWhere('slug', 'sharing');

    expect($sharing['questions'][0]['question'])->toBe('How do I share a container?')
        ->and($sharing['questions'][0]['anchor'])->toBe('how-do-i-share-a-container');
});

it('har unika ankare för kategorier och frågor tillsammans', function () {
    $ankare = [];
    foreach (hjälpKategorier() as $kategori) {
        $ankare[] = $kategori['slug'];
        foreach ($kategori['questions'] as $fråga) {
            $ankare[] = $fråga['anchor'];
        }
    }

    expect($ankare)->toBe(array_values(array_unique($ankare)));
});

it('ger bara tasks en ingress', function () {
    foreach (hjälpKategorier() as $kategori) {
        if ($kategori['slug'] === 'tasks') {
            expect($kategori['intro_html'])->toContain('Getting Things Done');
        } else {
            expect($kategori['intro_html'])->toBeNull();
        }
    }
});

it('släpper inte igenom HTML-kommentarer och ritar priset som tabell', function () {
    $svar = [];
    foreach (hjälpKategorier() as $kategori) {
        expect($kategori['intro_html'] ?? '')->not->toContain('<!--');
        foreach ($kategori['questions'] as $fråga) {
            expect($fråga['answer_html'])->not->toContain('<!--');
            $svar[$fråga['question']] = $fråga['answer_html'];
        }
    }

    expect($svar['What does Mimers cost?'])->toContain('<table>');
});

it('tar bort script och javascript-länkar ur en testfil', function () {
    $katalog = sys_get_temp_dir().'/hjalp-'.uniqid();
    File::makeDirectory($katalog);
    File::put($katalog.'/01-test.md', "# Test\n\n## Fråga?\n\n<script>alert(1)</script>\n\n[klick](javascript:alert(1))\n");

    $html = (new LoadHelpArticles)->handle($katalog)[0]['questions'][0]['answer_html'];
    File::deleteDirectory($katalog);

    expect($html)->not->toContain('<script');
    expect($html)->not->toContain('javascript:');
});

it('har nio filer, inget TODO(Tony) och inget kvar av utkastkatalogen', function () {
    expect(glob(lang_path('en/help/*.md')))->toHaveCount(9);
    expect(is_dir(base_path('docs/Design/mimers-faq-utkast')))->toBeFalse();

    foreach (glob(lang_path('*/help/*')) as $fil) {
        expect(file_get_contents($fil))->not->toContain('TODO(Tony)');
    }
});

it('har sidans ord i katalogen och behåller ruttnamnet', function () {
    expect(trans('ui.info.help.title', [], 'en'))->toBe('Help')
        ->and(trans('ui.info.help.subtitle', [], 'en'))->toBe('Answers to common questions about Mimers.')
        ->and(trans('ui.info.help.contents', [], 'en'))->toBe('Contents')
        ->and(route('info.help', [], false))->toBe('/help');
});
