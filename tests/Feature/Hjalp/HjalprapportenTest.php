<?php

use App\Console\ReportsHelpUsage;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

/*
 * M82 · issue 472 — den nattliga hjälprapporten och `help:feedback`,
 * [[ADR-0094 Hjälpcentret]] § 9, [[ADR-0079 Övervakning]] § 4 och
 * [[ADR-0017 Missbruksvektorer]] § Beslut.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här.
 * Hjälparna har prefixet hjalprapport* för att inte krocka med de globala
 * funktionerna i andra Feature-filer (Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/** En rad i hjälpens händelsetabell för dagen (eller den givna dagen). */
function hjalprapportHandelse(string $event, int $count, string $articleId = '', string $context = '', ?string $date = null): void
{
    $nu = now();

    DB::table('help_event_daily')->insert([
        'date' => $date ?? $nu->toDateString(),
        'event' => $event,
        'article_id' => $articleId,
        'context' => $context,
        'count' => $count,
        'created_at' => $nu,
        'updated_at' => $nu,
    ]);
}

/** En sökfråga utan träff för dagen (eller den givna dagen). */
function hjalprapportFraga(string $query, int $count, ?string $date = null): void
{
    $nu = now();

    DB::table('help_search_gap_daily')->insert([
        'date' => $date ?? $nu->toDateString(),
        'query' => $query,
        'count' => $count,
        'created_at' => $nu,
        'updated_at' => $nu,
    ]);
}

/**
 * En rad *Was this helpful?* satt direkt — sessionen blandas inte in.
 *
 * @param  array<string, mixed>  $attribut
 */
function hjalprapportFeedback(array $attribut = []): void
{
    $nu = now();

    DB::table('help_feedback')->insert([
        'ulid' => (string) Str::ulid(),
        'article_id' => $attribut['article_id'] ?? 'article-a',
        'locale' => 'en',
        'helpful' => $attribut['helpful'] ?? false,
        'reason' => $attribut['reason'] ?? null,
        'comment' => $attribut['comment'] ?? null,
        'user_id' => $attribut['user_id'] ?? null,
        'created_at' => $attribut['created_at'] ?? $nu,
        'updated_at' => $attribut['updated_at'] ?? ($attribut['created_at'] ?? $nu),
    ]);
}

/**
 * Kör rapporten och returnerar de loggmeddelanden som heter `help.report`.
 *
 * @return list<object>
 */
function hjalprapportKorning(): array
{
    $meddelanden = [];

    Log::listen(function ($meddelande) use (&$meddelanden): void {
        $meddelanden[] = $meddelande;
    });

    (new ReportsHelpUsage)->handle();

    return array_values(array_filter(
        $meddelanden,
        fn ($meddelande): bool => $meddelande->message === 'help.report',
    ));
}

/*
 * Klart när: med kända rader i de tre tabellerna skriver rapporten en loggrad
 * help.report med de fält och värden som står i issuen.
 */
it('skriver en loggrad help.report med rapportens fält och värden', function () {
    Carbon::setTestNow('2026-11-24 00:30:00');

    hjalprapportHandelse('article_view', 12, 'article-a');
    hjalprapportHandelse('article_view', 3, 'article-b');
    hjalprapportHandelse('search', 4);
    hjalprapportHandelse('search_click', 1);
    hjalprapportHandelse('context_open', 10, context: 'items.show');
    hjalprapportHandelse('context_unknown', 2, context: 'zzz.unknown');

    hjalprapportFraga('zzqx', 5);

    foreach (range(1, 3) as $i) {
        hjalprapportFeedback(['article_id' => 'article-a', 'helpful' => true]);
    }

    hjalprapportFeedback(['article_id' => 'article-a', 'helpful' => false, 'reason' => 'unclear']);
    hjalprapportFeedback([
        'article_id' => 'article-a',
        'helpful' => false,
        'reason' => 'outdated',
        'comment' => 'Saknar en skärmbild.',
    ]);

    $meddelanden = hjalprapportKorning();

    expect($meddelanden)->toHaveCount(1);
    expect($meddelanden[0]->level)->toBe('info');

    $rapport = $meddelanden[0]->context;

    expect($rapport['views_total'])->toBe(15)
        ->and($rapport['searches_total'])->toBe(4)
        ->and($rapport['search_clicks_total'])->toBe(1)
        ->and($rapport['click_through'])->toBe(0.25)
        ->and($rapport['top_articles'])->toBe([
            ['id' => 'article-a', 'views' => 12],
            ['id' => 'article-b', 'views' => 3],
        ])
        ->and($rapport['search_gaps'])->toBe([['query' => 'zzqx', 'count' => 5]])
        ->and($rapport['unhelpful'])->toBe([
            ['id' => 'article-a', 'answers' => 5, 'unhelpful' => 2, 'share' => 0.4],
        ])
        ->and($rapport['reasons'])->toBe([
            'unclear' => 1,
            'incomplete' => 0,
            'outdated' => 1,
            'incorrect' => 0,
            'missing' => 0,
        ])
        ->and($rapport['contexts'])->toBe(['items.show' => 10])
        ->and($rapport['unknown_contexts'])->toBe(['zzz.unknown' => 2])
        ->and($rapport['new_comments'])->toBe(1);
});

/*
 * Klart när: click_through är null utan sökningar — inte noll och inte en
 * division med noll.
 */
it('lämnar click_through null när inga sökningar finns', function () {
    $rapport = hjalprapportKorning()[0]->context;

    expect($rapport['searches_total'])->toBe(0)
        ->and($rapport['click_through'])->toBeNull();
});

/*
 * Klart när: en sökfråga med summa fyra under perioden saknas i search_gaps;
 * med fem finns den. Rader äldre än sju dygn räknas inte.
 */
it('visar en sökfråga först vid summa fem och räknar bara de senaste sju dygnen', function () {
    Carbon::setTestNow('2026-11-24 00:30:00');

    // Summan fyra över två dagar — under tröskeln.
    hjalprapportFraga('fyra', 2, now()->toDateString());
    hjalprapportFraga('fyra', 2, now()->subDays(3)->toDateString());

    hjalprapportFraga('fem', 3, now()->toDateString());
    hjalprapportFraga('fem', 2, now()->subDays(2)->toDateString());

    // Åtta dygn gammal — utanför fönstret, trots hög räkning.
    hjalprapportFraga('gammal', 99, now()->subDays(8)->toDateString());

    $gaps = collect(hjalprapportKorning()[0]->context['search_gaps'])
        ->pluck('count', 'query')
        ->all();

    expect($gaps)->toHaveKey('fem')
        ->and($gaps['fem'])->toBe(5)
        ->and($gaps)->not->toHaveKey('fyra')
        ->and($gaps)->not->toHaveKey('gammal');
});

/*
 * Klart när: en artikel med fyra svar saknas i unhelpful; med fem finns den
 * med rätt andel.
 */
it('visar en artikel i unhelpful först vid fem svar, med andelen Nej', function () {
    foreach (range(1, 4) as $i) {
        hjalprapportFeedback(['article_id' => 'article-fyra', 'helpful' => false, 'reason' => 'unclear']);
    }

    hjalprapportFeedback(['article_id' => 'article-fem', 'helpful' => true]);
    hjalprapportFeedback(['article_id' => 'article-fem', 'helpful' => true]);

    foreach (range(1, 3) as $i) {
        hjalprapportFeedback(['article_id' => 'article-fem', 'helpful' => false, 'reason' => 'unclear']);
    }

    expect(hjalprapportKorning()[0]->context['unhelpful'])->toBe([
        ['id' => 'article-fem', 'answers' => 5, 'unhelpful' => 3, 'share' => 0.6],
    ]);
});

/*
 * Klart när: rapporten skriver ingenting i databasen — radantal och
 * updated_at i de tre tabellerna är orörda.
 */
it('skriver ingenting i databasen', function () {
    Carbon::setTestNow('2026-11-24 00:30:00');

    hjalprapportHandelse('article_view', 3, 'article-a');
    hjalprapportHandelse('context_open', 1, context: 'items.show');
    hjalprapportFraga('zzqx', 5);
    hjalprapportFeedback(['article_id' => 'article-a', 'comment' => 'En kommentar.']);

    $rader = fn (): array => [
        'help_event_daily' => DB::table('help_event_daily')->orderBy('id')->get()->map(fn ($rad): array => (array) $rad)->all(),
        'help_search_gap_daily' => DB::table('help_search_gap_daily')->orderBy('id')->get()->map(fn ($rad): array => (array) $rad)->all(),
        'help_feedback' => DB::table('help_feedback')->orderBy('id')->get()->map(fn ($rad): array => (array) $rad)->all(),
    ];

    $fore = $rader();

    // En timme senare: skrev rapporten något oväntat syns det på raderna.
    Carbon::setTestNow(now()->addHour());

    (new ReportsHelpUsage)->handle();

    expect($rader())->toBe($fore);
});

/*
 * Klart när: rapporten är schemalagd dagligen med Schedule::call.
 */
it('är schemalagd dagligen med Schedule::call', function () {
    // artisan(...) bootstrapar konsol-kerneln, som laddar routes/console.php.
    artisan('inspire');

    $handelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'report-help-usage');

    expect($handelse)->not->toBeNull();
    expect($handelse->getExpression())->toBe('30 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($handelse->command ?? null)->toBeNull();
});

/*
 * Klart när: help:feedback --days=7 visar en kommentar från i går men inte en
 * från för tio dagar sedan, och utdata innehåller varken e-post eller
 * användar-id.
 */
it('visar kommentarer från de senaste sju dygnen utan e-post eller användar-id', function () {
    Carbon::setTestNow('2026-11-24 12:00:00');

    $person = User::factory()->create([
        'name' => 'Hemligt Namn',
        'email' => 'hemlig@example.com',
    ]);

    hjalprapportFeedback([
        'article_id' => 'article-a',
        'helpful' => false,
        'reason' => 'unclear',
        'comment' => 'Gårdagens kommentar',
        'user_id' => $person->id,
        'created_at' => now()->subDay(),
    ]);

    hjalprapportFeedback([
        'article_id' => 'article-a',
        'helpful' => false,
        'reason' => 'outdated',
        'comment' => 'Gammal kommentar',
        'created_at' => now()->subDays(10),
    ]);

    artisan('help:feedback', ['--days' => 7])
        ->expectsOutputToContain('Gårdagens kommentar')
        ->doesntExpectOutputToContain('Gammal kommentar')
        ->doesntExpectOutputToContain('hemlig@example.com')
        ->doesntExpectOutputToContain('@')
        ->doesntExpectOutputToContain('Hemligt Namn')
        ->doesntExpectOutputToContain('user_id')
        ->assertSuccessful();
});

/*
 * `--article` begränsar listan till en artikel.
 */
it('filtrerar kommentarerna på artikel med --article', function () {
    Carbon::setTestNow('2026-11-24 12:00:00');

    hjalprapportFeedback(['article_id' => 'article-a', 'helpful' => false, 'comment' => 'På artikel A']);
    hjalprapportFeedback(['article_id' => 'article-b', 'helpful' => false, 'comment' => 'På artikel B']);

    artisan('help:feedback', ['--article' => 'article-a'])
        ->expectsOutputToContain('På artikel A')
        ->doesntExpectOutputToContain('På artikel B')
        ->assertSuccessful();
});

/*
 * Listornas tak läses ur config/help.php — tio artiklar, 25 sökfrågor och tio
 * otrevliga artiklar är förvalen, inte inkodade konstanter.
 */
it('kapar listorna vid taken i config/help.php', function () {
    config([
        'help.report_top_articles' => 2,
        'help.report_search_gaps_limit' => 1,
        'help.report_unhelpful_limit' => 1,
    ]);

    foreach (range(1, 3) as $i) {
        hjalprapportHandelse('article_view', $i, 'article-'.$i);
        hjalprapportFraga('fraga-'.$i, 5);

        foreach (range(1, 5) as $j) {
            hjalprapportFeedback([
                'article_id' => 'article-'.$i,
                'helpful' => $j <= $i,
                'reason' => $j <= $i ? null : 'unclear',
            ]);
        }
    }

    $rapport = hjalprapportKorning()[0]->context;

    expect($rapport['top_articles'])->toHaveCount(2)
        ->and($rapport['search_gaps'])->toHaveCount(1)
        ->and($rapport['unhelpful'])->toHaveCount(1);
});
