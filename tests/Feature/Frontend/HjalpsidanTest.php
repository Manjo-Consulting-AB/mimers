<?php

use App\Models\User;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCategory;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpMarkdown;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M78 · issue 458, M80 · issue 461 och 462 — hjälpsidan. Texterna är en
 * artikelfil per fråga under lang/en/help/articles/, HelpLibrary läser dem och
 * HelpController skickar dem vidare: startsidan får kategorierna och
 * ankarkartan, kategorisidan (tests/Feature/Hjalp/KategorisidanTest.php) får
 * kategorin och dess frågor.
 */

function hjälpKategorier(): array
{
    $katalog = (new HelpLibrary)->catalog('en');
    $markdown = new HelpMarkdown;

    return array_map(
        fn (HelpCategory $kategori): array => [
            'slug' => $kategori->id,
            'title' => $kategori->title,
            'intro_html' => $markdown->toHtml($kategori->intro ?? ''),
            'questions' => array_map(
                fn (HelpArticle $artikel): array => [
                    'anchor' => $artikel->id,
                    'question' => $artikel->title,
                    'answer_html' => $markdown->toHtml($artikel->markdown) ?? '',
                ],
                $katalog->inCategory($kategori->id),
            ),
        ],
        $katalog->categories(),
    );
}

it('renderar Help med nio kategorier och ankarkartan för en gäst och en inloggad', function () {
    withoutVite();

    $kontroll = fn (AssertableInertia $page) => $page
        ->component('Help')
        ->has('categories', 9)
        ->where('categories.0.id', 'getting-started')
        ->where('categories.0.title', 'Getting started')
        ->where('categories.0.description', 'The basics of Mimers, containers and items.')
        ->where('categories.0.count', 6)
        ->where('categories.8.id', 'privacy-and-data')
        ->where('categories.8.count', 6)
        // De gamla adresserna: en fråga och en kategori, ur kartan.
        ->where('anchors.how-do-i-share-a-container', '/help/sharing#how-do-i-share-a-container')
        ->where('anchors.sharing', '/help/sharing')
        // En post för varje artikel (66) och varje kategori (9).
        ->has('anchors', 75);

    get('/help')->assertOk()->assertInertia($kontroll);
    actingAs(User::factory()->create())->get('/help')->assertOk()->assertInertia($kontroll);
});

it('ger kategorin sharing frågorna i filordning med ankare', function () {
    $sharing = collect(hjälpKategorier())->firstWhere('slug', 'sharing');

    expect($sharing['questions'][0]['question'])->toBe('How do I share a container?')
        ->and($sharing['questions'][0]['anchor'])->toBe('how-do-i-share-a-container');
});

/*
 * M80 · issue 462 — de gamla adresserna. Fragmentet når aldrig servern, så
 * startsidan bär kartan och skickar webbläsaren vidare själv (ADR-0094 § 3).
 */
it('har en ankarpost för varje artikel och varje kategori', function () {
    withoutVite();

    $katalog = (new HelpLibrary)->catalog('en');

    $förväntat = [];

    foreach ($katalog->categories() as $kategori) {
        $förväntat[$kategori->id] = '/help/'.$kategori->id;
    }

    foreach ($katalog->articles() as $artikel) {
        $förväntat[$artikel->id] = '/help/'.$artikel->category.'#'.$artikel->id;
    }

    /** @var array{props: array<string, mixed>} $sida */
    $sida = get('/help')->assertOk()->viewData('page');

    expect($sida['props']['anchors'])->toBe($förväntat);
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

it('tar bort script och javascript-länkar ur markdown', function () {
    $html = (new HelpMarkdown)->toHtml("<script>alert(1)</script>\n\n[klick](javascript:alert(1))\n");

    expect($html)->not->toContain('<script');
    expect($html)->not->toContain('javascript:');
});

it('har 66 artikelfiler och inget kvar av de gamla kategorifilerna', function () {
    expect(glob(lang_path('en/help/articles/*.md')))->toHaveCount(66);
    expect(glob(lang_path('en/help/*.md')))->toBe([]);
    expect(is_file(lang_path('en/help/categories.yaml')))->toBeTrue();
    expect(is_file(lang_path('en/help/synonyms.yaml')))->toBeTrue();
    expect(is_file(app_path('Actions/Help/LoadHelpArticles.php')))->toBeFalse();
    expect(is_dir(base_path('docs/Design/mimers-faq-utkast')))->toBeFalse();

    $filer = [
        ...(glob(lang_path('*/help/*.yaml')) ?: []),
        ...(glob(lang_path('*/help/articles/*.md')) ?: []),
    ];

    foreach ($filer as $fil) {
        expect(file_get_contents($fil))->not->toContain('TODO(Tony)');
    }
});

it('låter varje artikel-id vara Str::slug av titeln', function () {
    $artiklar = (new HelpLibrary)->catalog('en')->articles();

    expect($artiklar)->toHaveCount(66);

    foreach ($artiklar as $artikel) {
        expect($artikel->id)->toBe(Str::slug($artikel->title));
    }
});

it('har exakt de gamla ankarna som artikel-id:n', function () {
    $idn = [];
    foreach (hjälpKategorier() as $kategori) {
        foreach ($kategori['questions'] as $fråga) {
            $idn[] = $fråga['anchor'];
        }
    }

    expect($idn)->toBe([
        'what-is-mimers',
        'what-is-a-container',
        'what-is-an-item',
        'how-do-i-get-an-account',
        'is-there-a-mobile-app',
        'which-languages-does-mimers-support',
        'how-do-i-organise-my-items',
        'can-i-add-my-own-fields-to-items',
        'can-i-save-and-reuse-my-own-templates',
        'how-do-i-find-something-quickly',
        'can-i-move-an-item-to-another-place',
        'can-i-keep-track-of-what-ive-lent-out',
        'can-i-record-what-things-cost',
        'i-deleted-something-by-mistake-can-i-get-it-back',
        'what-is-the-inbox',
        'do-tasks-always-have-to-be-linked-to-an-item',
        'how-do-i-set-up-recurring-maintenance',
        'whats-the-difference-between-the-two-types-of-repeating-tasks',
        'where-do-i-see-what-needs-doing',
        'how-do-reminders-reach-me',
        'can-i-see-my-tasks-in-my-own-calendar',
        'what-can-i-upload',
        'how-big-can-a-file-be',
        'how-much-storage-space-do-i-have',
        'who-pays-for-the-storage-in-a-shared-container',
        'does-uploading-the-same-file-twice-use-double-the-space',
        'what-happens-when-i-run-out-of-storage-space',
        'do-deleted-files-still-count-towards-my-storage',
        'how-do-i-share-a-container',
        'what-can-the-people-i-share-with-do',
        'can-i-share-just-part-of-a-container',
        'can-i-change-or-revoke-someones-access',
        'how-many-people-can-i-share-with',
        'can-i-hand-a-container-over-to-someone-else',
        'what-happens-to-my-access-when-i-transfer-ownership',
        'what-happens-to-other-people-who-have-access-to-the-container',
        'can-i-keep-some-information-when-transferring-a-container',
        'what-happens-to-deleted-items-and-version-history',
        'does-the-new-owner-need-a-specific-plan',
        'does-the-new-owner-get-a-mimers-subscription',
        'what-does-mimers-cost',
        'whats-included-in-every-plan',
        'whats-the-difference-between-pro-and-pro',
        'whats-the-founder-price',
        'will-the-price-go-up',
        'what-happens-if-i-downgrade-my-plan',
        'what-happens-when-my-subscription-expires',
        'will-my-items-and-information-be-deleted-if-i-downgrade',
        'how-do-i-log-in-without-a-password',
        'how-do-i-turn-on-two-factor-authentication',
        'how-do-i-change-my-email-address',
        'how-do-i-delete-my-account',
        'what-happens-if-i-stop-using-mimers',
        'can-i-use-mimers-with-claude-or-another-ai-assistant',
        'how-do-i-control-what-an-ai-agent-can-access',
        'can-other-peoples-ai-agents-access-a-shared-container',
        'what-can-an-ai-agent-do-in-my-containers',
        'how-do-i-stop-an-ai-agent',
        'can-i-see-what-an-ai-agent-has-done',
        'what-happens-if-an-ai-agent-changes-or-deletes-something-by-mistake',
        'where-is-my-data-stored',
        'where-is-my-data-processed',
        'who-can-access-my-files',
        'do-you-sell-my-data-or-show-ads',
        'can-i-export-my-data',
        'who-can-see-my-containers',
    ]);
});

it('behåller svaren ordagrant', function () {
    // trim() tar bara bort filens inledande och avslutande radbrytning; texten
    // däremellan jämförs tecken för tecken med hur den stod i kategorifilerna.
    $svar = (new HelpLibrary)->catalog('en')->articles();
    $svar = collect($svar)->keyBy('id');
    $kropp = fn (string $id): string => trim($svar[$id]->markdown);

    expect($kropp('what-is-mimers'))->toBe(<<<'MD'
Mimers helps you keep track of the things that matter to you. Store manuals, receipts, photos, maintenance records, costs and other information in one organised place.

Create a **container** for anything you want to keep track of – a boat, a house, a car, a project or even your family. Then add **items** to organise everything that belongs to it.

<!-- video -->
MD);

    expect($kropp('how-do-i-set-up-recurring-maintenance'))->toBe(<<<'MD'
Add a task to an item and choose how often it should repeat. Mimers keeps track of when it's due and reminds you when it's time to do it.

<!-- video -->
MD);

    expect($kropp('how-do-i-share-a-container'))->toBe(<<<'MD'
Open the container, go to **Sharing** and invite someone by email. If they don't have a Mimers account yet, they can create one using that email address and then accept the invitation.

You can share an entire container or give someone access to a specific item and everything inside it.

<!-- video -->
MD);

    expect($kropp('what-does-mimers-cost'))->toBe(<<<'MD'
Mimers offers three plans. Pro and Pro+ include the same features. The only difference is the amount of storage.

| | Free | Pro | Pro+ |
|---|---:|---:|---:|
| **Price per year** | €0 | €39 | €69 |
| **Founder price per year** | — | €19 | €39 |
| Containers | 1 | Unlimited | Unlimited |
| Storage | 1 GB | 10 GB | 50 GB |
| Maximum file size | 10 MB | 64 MB | 64 MB |
| People to share with per container | 1 | Unlimited | Unlimited |
| Webhooks | — | Unlimited | Unlimited |
| MCP access | — | Unlimited | Unlimited |
| Priority support | — | Yes | Yes |

All prices are annual. We do not offer monthly subscriptions.
MD);

    expect($kropp('how-do-i-control-what-an-ai-agent-can-access'))->toBe(<<<'MD'
You control access at two levels:

**1. Account-level permissions**

First, you define the maximum permission level an agent can have on your account.

**2. Container-level access**

You then enable the agent separately for each container you want it to access. For each container, you can grant the agent any permission level up to the maximum set at account level.

You can give an agent less access in a particular container, but never more than its account-level limit.

An agent can never have higher permissions than you have yourself.
MD);
});

it('har de nio kategorierna i dagens ordning med beskrivningarna och tasks-ingressen', function () {
    $katalog = (new HelpLibrary)->catalog('en');

    $rader = array_map(
        static fn (HelpCategory $kategori): array => [$kategori->id, $kategori->title, $kategori->description],
        $katalog->categories(),
    );

    expect($rader)->toBe([
        ['getting-started', 'Getting started', 'The basics of Mimers, containers and items.'],
        ['containers-and-items', 'Containers and items', 'Organise, find, move and restore your items.'],
        ['tasks', 'Tasks and reminders', 'Recurring maintenance, reminders and your calendar.'],
        ['files-and-storage', 'Files and storage', 'Uploads, file sizes and storage space.'],
        ['sharing', 'Sharing', 'Invite people, set access and hand over a container.'],
        ['plans', 'Plans and pricing', 'Prices, plans and what happens when you change plan.'],
        ['account-and-security', 'Account and security', 'Logging in, two-factor authentication and your account.'],
        ['agents', 'AI agents', 'Using Mimers with Claude and other AI assistants.'],
        ['privacy-and-data', 'Privacy and your data', 'Where your data lives and who can see it.'],
    ]);

    expect($katalog->category('tasks')?->intro)->toContain('Getting Things Done');
});

it('kopplar de tretton sammanhangen till rätt artikel', function () {
    $katalog = (new HelpLibrary)->catalog('en');

    $förväntat = [
        'container.sharing' => 'how-do-i-share-a-container',
        'container.transfer' => 'can-i-hand-a-container-over-to-someone-else',
        'container.agents' => 'can-other-peoples-ai-agents-access-a-shared-container',
        'container.tasks' => 'how-do-i-set-up-recurring-maintenance',
        'container.calendar' => 'can-i-see-my-tasks-in-my-own-calendar',
        'container.fields' => 'can-i-add-my-own-fields-to-items',
        'container.export' => 'can-i-export-my-data',
        'account.templates' => 'can-i-save-and-reuse-my-own-templates',
        'account.security' => 'how-do-i-turn-on-two-factor-authentication',
        'account.storage' => 'how-much-storage-space-do-i-have',
        'account.plan' => 'what-does-mimers-cost',
        'account.agents' => 'how-do-i-control-what-an-ai-agent-can-access',
        'trash' => 'i-deleted-something-by-mistake-can-i-get-it-back',
    ];

    foreach ($förväntat as $context => $id) {
        expect($katalog->article($id)?->contexts)->toBe([$context]);
    }
});

it('har sidans ord i katalogen och behåller ruttnamnet', function () {
    expect(trans('ui.info.help.title', [], 'en'))->toBe('Help')
        ->and(trans('ui.info.help.subtitle', [], 'en'))->toBe('Answers to common questions about Mimers.')
        ->and(trans('ui.info.help.contents', [], 'en'))->toBe('Contents')
        ->and(route('info.help', [], false))->toBe('/help')
        ->and(route('help.category', 'sharing', false))->toBe('/help/sharing');
});

/*
 * M80 · issue 462 — kategorisidans fyra nycklar. Tvågrenad form för antalet:
 * `t()` i frontenden pluraliserar inte, så Help.vue väljer gren; här prövas
 * att båda grenarna finns och att `trans_choice` läser dem som Laravel ska.
 */
it('har kategorisidans ord i katalogen', function () {
    expect(trans('ui.help.breadcrumb.home', [], 'en'))->toBe('Help')
        ->and(trans('ui.help.copy_link', [], 'en'))->toBe('Link to this question')
        ->and(trans('ui.help.link_copied', [], 'en'))->toBe('Link copied')
        ->and(trans('ui.help.questions_count', [], 'en'))->toBe(':count question|:count questions')
        ->and(trans_choice('ui.help.questions_count', 1, ['count' => 1], 'en'))->toBe('1 question')
        ->and(trans_choice('ui.help.questions_count', 3, ['count' => 3], 'en'))->toBe('3 questions');
});

/*
 * Källkodsprov: de två beteendena är klientsidans, och det som går att avgöra
 * på serversidan är att koden finns och gör det issuen säger. Det faktiska
 * flödet — att `/help#how-do-i-share-a-container` hamnar på kategorisidan med
 * frågan öppen — kräver en webbläsare. Handprovet är gjort mot appen i
 * arbetsträdet (headless Chromium via Playwright): adressen hamnar på
 * `/help/sharing#how-do-i-share-a-container` med frågan öppen och framskrollad,
 * `hashchange` öppnar nästa fråga, och *Link to this question* kopierar rätt
 * adress. Det står också i PR-kroppen.
 */
it('skickar de gamla ankarna vidare med en Inertia-visit', function () {
    $kod = File::get(resource_path('js/pages/Help.vue'));

    expect($kod)->toContain('window.location.hash')
        ->toContain('Object.hasOwn(props.anchors, anchor)')
        ->toContain("typeof address !== 'string'")
        ->toContain('props.anchors')
        ->toContain('router.visit(address, { replace: true })');
});

it('ritar frågorna som details och öppnar den som matchar ankaret', function () {
    $kod = File::get(resource_path('js/pages/Help/Category.vue'));

    expect($kod)->toContain('<details')
        ->toContain(':id="article.id"')
        ->toContain('window.location.hash')
        ->toContain('hashchange')
        ->toContain('try')
        ->toContain('target.open = true');
});
