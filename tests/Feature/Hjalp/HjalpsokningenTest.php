<?php

use App\Support\Help\HelpCatalog;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpSearch;
use App\Support\Help\HelpSearchResult;

use function Pest\Laravel\getJson;

/*
 * M81 · issue 466 — sökningen i hjälpen, [[ADR-0094 Hjälpcentret]] § 5.
 *
 * Beteendet prövas mot en egen testkatalog under fixtures/sok, så att
 * rangordningen och utdragen inte beror på hur de riktiga texterna råkar se
 * ut. Rutten prövas mot den riktiga katalogen under lang/en/help, eftersom det
 * är den sökrutan möter — och mot testkatalogen när takgränsen ska prövas 61
 * gånger utan att rita om hela innehållet varje varv.
 */

/** Testkatalogen för sökningen. */
function hjalpsokKatalog(): HelpCatalog
{
    return (new HelpLibrary(__DIR__.'/fixtures/sok'))->catalog('en');
}

/** Sök i testkatalogen. */
function hjalpsok(string $query, int $limit = 20): HelpSearchResult
{
    return (new HelpSearch)->search(hjalpsokKatalog(), $query, $limit);
}

/** Träffarnas id:n i ordning. */
function hjalpsokIdn(HelpSearchResult $result): array
{
    return array_map(static fn (array $hit): string => $hit['id'], $result->results);
}

it('hittar artikeln på titeln och lägger den först', function () {
    $resultat = hjalpsok('share');

    expect(hjalpsokIdn($resultat)[0] ?? null)->toBe('how-do-i-share-a-container')
        ->and($resultat->partial)->toBeFalse();
});

it('hittar samma artikel via synonymen invite med lägre poäng', function () {
    $share = hjalpsok('share');
    $invite = hjalpsok('invite');

    expect(hjalpsokIdn($invite))->toContain('how-do-i-share-a-container')
        ->and($invite->score('how-do-i-share-a-container'))
        ->toBeLessThan((float) $share->score('how-do-i-share-a-container'));
});

it('hittar början av ett ord med minst tre tecken', function () {
    expect(hjalpsokIdn(hjalpsok('stor')))->toContain('how-much-storage-space-do-i-have')
        ->and(hjalpsokIdn(hjalpsok('stor'))[0] ?? null)->toBe('how-much-storage-space-do-i-have');
});

it('söker på en fråga som bara består av stoppord', function () {
    $resultat = hjalpsok('how do i');

    expect($resultat->results)->not->toBeEmpty()
        ->and(hjalpsokIdn($resultat))->toContain('how-do-i-share-a-container');
});

it('tar bort stoppord när frågan har ett innehållsord', function () {
    $bara = hjalpsok('share');
    $medStoppord = hjalpsok('how do i share');

    expect(hjalpsokIdn($medStoppord)[0] ?? null)->toBe(hjalpsokIdn($bara)[0] ?? null);
});

it('rangordnar en titelträff före en artikel där ordet bara står i texten', function () {
    $idn = hjalpsokIdn(hjalpsok('paddle'));

    expect($idn[0] ?? null)->toBe('paddle-care')
        ->and(array_search('boat-care', $idn, true))->toBeGreaterThan(0);
});

it('markerar en delträff när bara hälften av orden träffar', function () {
    $resultat = hjalpsok('share zebra');

    expect($resultat->partial)->toBeTrue()
        ->and(hjalpsokIdn($resultat))->toContain('how-do-i-share-a-container');
});

it('ger inga träffar när inget ord träffar', function () {
    expect(hjalpsok('zebra quokka')->results)->toBe([]);
});

it('delar titeln i segment med träffen markerad', function () {
    $traff = hjalpsok('share')->results[0];

    expect($traff['title'])->toBe([
        ['text' => 'How do I ', 'match' => false],
        ['text' => 'share', 'match' => true],
        ['text' => ' a container?', 'match' => false],
    ])
        ->and(implode('', array_column($traff['title'], 'text')))->toBe('How do I share a container?');
});

it('klipper ett utdrag ur texten runt första träffen', function () {
    $traff = hjalpsok('kayak')->results[0];
    $utdrag = implode('', array_column($traff['snippet'], 'text'));

    expect(mb_strlen($utdrag))->toBeLessThanOrEqual(170)
        ->and(array_filter($traff['snippet'], static fn (array $segment): bool => $segment['match']))
        ->not->toBeEmpty()
        ->and($utdrag)->toContain('kayak')
        ->and($utdrag)->toStartWith('…')
        ->and($utdrag)->toEndWith('…');
});

it('normaliserar frågan', function () {
    expect(HelpSearch::normalize('What’s the 2FA?!'))->toBe('what s the 2fa')
        ->and(HelpSearch::normalize('  TWO-factor  '))->toBe('two-factor')
        ->and(HelpSearch::normalize('?!'))->toBe('');
});

it('svarar på /help/search med träffar som JSON', function () {
    $response = getJson('/help/search?q=share&limit=5');

    $response->assertOk()
        ->assertJsonPath('query', 'share')
        ->assertJsonPath('partial', false)
        ->assertJsonStructure([
            'query',
            'partial',
            'results' => [[
                'id',
                'url',
                'type',
                'category' => ['id', 'title'],
                'title' => [['text', 'match']],
                'snippet',
            ]],
        ]);

    expect($response->json('results.0.id'))->toBe('how-do-i-share-a-container')
        ->and($response->json('results.0.url'))->toBe('/help/articles/how-do-i-share-a-container')
        ->and($response->json('results.0.category.id'))->toBe('sharing')
        ->and($response->json('results.0.category.title'))->toBe('Sharing');
});

it('ger tomt resultat för en fråga som blir tom efter normalisering', function () {
    getJson('/help/search?q=%3F%21')
        ->assertOk()
        ->assertJsonPath('results', []);
});

it('kräver q och svarar med API:ets fältfel', function () {
    getJson('/help/search')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.data.fields.q.0.code', 'validation.required');
});

it('avvisar en fråga längre än 100 tecken', function () {
    getJson('/help/search?q='.str_repeat('a', 101))
        ->assertStatus(422)
        ->assertJsonPath('error.data.fields.q.0.code', 'validation.max')
        ->assertJsonPath('error.data.fields.q.0.data.max', 100);
});

it('avvisar ett limit utanför 1–20', function () {
    getJson('/help/search?q=share&limit=21')
        ->assertStatus(422)
        ->assertJsonPath('error.data.fields.limit.0.code', 'validation.between');
});

it('begränsar till 60 sökningar i minuten per IP', function () {
    // Testkatalogen gör 61 anrop billiga; den riktiga katalogen hade ritat om
    // hela innehållet i varje varv.
    app()->instance(HelpLibrary::class, new HelpLibrary(__DIR__.'/fixtures/sok'));

    // Egen IP via X-Forwarded-For — 127.0.0.1 är en betrodd proxy i testmiljön
    // (bootstrap/app.php) — så att räknaren inte delas med andra prov.
    $headers = ['X-Forwarded-For' => '198.51.100.23'];

    for ($i = 0; $i < 60; $i++) {
        getJson('/help/search?q=share', $headers)->assertOk();
    }

    getJson('/help/search?q=share', $headers)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'auth.too_many_attempts');
});
