<?php

use App\Actions\Help\CountHelpEvent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Help\SearchGapQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M82 · issue 470 — hjälpens mätning och sökfrågor utan träff,
 * [[ADR-0094 Hjälpcentret]] § 8 och [[ADR-0079 Övervakning]] § 4.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här. Den
 * befintliga mätningen (usage_event_daily) rörs inte — de proven bor kvar i
 * tests/Feature/Kvot/AnvandningsmatningTest.php.
 *
 * Hjälparna har prefixet hjalpmatning* för att inte krocka med de globala
 * hjälparna i andra Feature-filer (sokrutaKod() i SokrutanTest med flera).
 */

/** Antal gånger en händelse räknats för dagen. */
function hjalpmatningCount(string $event, string $articleId = '', string $context = ''): int
{
    return (int) DB::table('help_event_daily')
        ->where('date', now()->toDateString())
        ->where('event', $event)
        ->where('article_id', $articleId)
        ->where('context', $context)
        ->value('count');
}

/** Den sparade frågan utan träff för dagen, eller null. */
function hjalpmatningFraga(string $query): ?int
{
    $count = DB::table('help_search_gap_daily')
        ->where('date', now()->toDateString())
        ->where('query', $query)
        ->value('count');

    return $count === null ? null : (int) $count;
}

/** Källkoden med kommentarer borta — en regel ska inte nöjas av en mening. */
function hjalpmatningKod(string $sokvag): string
{
    $kod = File::get(resource_path('js/'.$sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: två visningar samma dag ger en rad article_view med count 2; en
 * med `?ref=search` ger dessutom search_click 1.
 */
it('räknar artikelvisningar och sökklikk', function () {
    withoutVite();

    get('/help/articles/how-do-i-share-a-container')->assertOk();
    get('/help/articles/how-do-i-share-a-container')->assertOk();

    expect(hjalpmatningCount(CountHelpEvent::ARTICLE_VIEW, 'how-do-i-share-a-container'))->toBe(2)
        ->and(DB::table('help_event_daily')->count())->toBe(1);

    get('/help/articles/how-do-i-share-a-container?ref=search')->assertOk();

    expect(hjalpmatningCount(CountHelpEvent::ARTICLE_VIEW, 'how-do-i-share-a-container'))->toBe(3)
        ->and(hjalpmatningCount(CountHelpEvent::SEARCH_CLICK, 'how-do-i-share-a-container'))->toBe(1);
});

/*
 * Klart när: en partiell Inertia-laddning räknas inte.
 */
it('räknar inte en partiell Inertia-laddning', function () {
    withoutVite();

    get('/help/articles/how-do-i-share-a-container', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Help/Article',
        'X-Inertia-Partial-Data' => 'article',
    ])->assertOk();

    expect(DB::table('help_event_daily')->count())->toBe(0);
});

/*
 * Klart när: `/help/search?q=share` utan `log` räknar ingenting; med `log=1`
 * räknas search 1 och ingen fråga sparas; `q=zzqx&log=1` räknar search och
 * sparar zzqx.
 */
it('räknar JSON-sökningen bara med log=1 och sparar frågor utan träff', function () {
    get('/help/search?q=share')->assertOk();

    expect(DB::table('help_event_daily')->count())->toBe(0)
        ->and(DB::table('help_search_gap_daily')->count())->toBe(0);

    get('/help/search?q=share&log=1')->assertOk();

    expect(hjalpmatningCount(CountHelpEvent::SEARCH))->toBe(1)
        ->and(hjalpmatningFraga('share'))->toBeNull();

    get('/help/search?q=zzqx&log=1')->assertOk();

    expect(hjalpmatningCount(CountHelpEvent::SEARCH))->toBe(2)
        ->and(hjalpmatningFraga('zzqx'))->toBe(1);
});

/*
 * Klart när: `/help?q=zzqx` räknar på samma sätt. Träfflistan är en sökning
 * även när den kom som en vanlig GET.
 */
it('räknar träfflistan på /help?q= och sparar frågor utan träff', function () {
    withoutVite();

    get('/help?q=share')->assertOk();
    get('/help?q=zzqx')->assertOk();

    expect(hjalpmatningCount(CountHelpEvent::SEARCH))->toBe(2)
        ->and(hjalpmatningFraga('share'))->toBeNull()
        ->and(hjalpmatningFraga('zzqx'))->toBe(1);
});

/*
 * Klart när: `sanitize('Anna anna@exempel.se ABC123 456')` ger `anna abc# #`,
 * `sanitize('a@b.se')` ger null, och en fråga över 60 tecken kapas på
 * ordgräns.
 */
it('avidentifierar frågan innan den sparas', function () {
    expect(SearchGapQuery::sanitize('Anna anna@exempel.se ABC123 456'))->toBe('anna abc# #')
        ->and(SearchGapQuery::sanitize('a@b.se'))->toBeNull()
        ->and(SearchGapQuery::sanitize('zzqx'))->toBe('zzqx')
        ->and(SearchGapQuery::sanitize('a'))->toBeNull();
});

it('kapar en lång fråga på ordgräns och tar bort för långa ord', function () {
    $lang = trim(str_repeat('ord ', 20));
    $kapad = SearchGapQuery::sanitize($lang);

    expect($kapad)->not->toBeNull();
    expect(mb_strlen((string) $kapad))->toBeLessThanOrEqual(SearchGapQuery::MAX_LENGTH);
    expect($kapad)->toEndWith('ord');

    // Ett ord längre än 30 tecken är ingen sökfråga och tas bort.
    expect(SearchGapQuery::sanitize(str_repeat('a', 31).' bra'))->toBe('bra');
});

/*
 * Klart när: ingen av tabellerna har en kolumn för användare, konto, IP eller
 * session.
 */
it('har ingen kolumn för användare, konto, IP eller session', function () {
    expect(Schema::getColumnListing('help_event_daily'))
        ->toEqualCanonicalizing(['id', 'date', 'event', 'article_id', 'context', 'count', 'created_at', 'updated_at']);

    expect(Schema::getColumnListing('help_search_gap_daily'))
        ->toEqualCanonicalizing(['id', 'date', 'query', 'count', 'created_at', 'updated_at']);
});

/*
 * Klart när: med 1000 rader för dagen läggs ingen ny fråga till, men en
 * befintlig räknas upp.
 */
it('stoppar nya frågor vid taket men räknar upp en befintlig', function () {
    $dag = now()->toDateString();
    $rader = [];

    for ($i = 1; $i <= 999; $i++) {
        $rader[] = [
            'date' => $dag, 'query' => "frage {$i}", 'count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    $rader[] = [
        'date' => $dag, 'query' => 'befintlig', 'count' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('help_search_gap_daily')->insert($rader);

    $counter = new CountHelpEvent;
    $counter->searchGap('ny fraga');
    $counter->searchGap('befintlig');

    expect(DB::table('help_search_gap_daily')->where('date', $dag)->count())->toBe(1000)
        ->and(hjalpmatningFraga('befintlig'))->toBe(2)
        ->and(hjalpmatningFraga('ny fraga'))->toBeNull();
});

/*
 * Klart när: context_open och context_unknown räknas med sitt sammanhang.
 */
it('räknar öppnade och okända sammanhang med sammanhanget', function () {
    get('/help/context/container.sharing')
        ->assertRedirect('/help/articles/how-do-i-share-a-container');

    get('/help/context/finns.inte')->assertRedirect('/help');

    expect(hjalpmatningCount(CountHelpEvent::CONTEXT_OPEN, 'how-do-i-share-a-container', 'container.sharing'))->toBe(1)
        ->and(hjalpmatningCount(CountHelpEvent::CONTEXT_UNKNOWN, '', 'finns.inte'))->toBe(1);
});

/*
 * Granskningens fynd 2: ruttmönstret för sammanhang har ingen längdgräns, men
 * kolumnen är VARCHAR(100). Ett långt, okänt sammanhang kapas i stället för
 * att fälla upserten — annars är felloggen den enda spärren mot spam.
 */
it('kapar ett okänt sammanhang som är längre än kolumnen', function () {
    $langt = 'a.'.str_repeat('b', 120);

    expect(mb_strlen($langt))->toBeGreaterThan(CountHelpEvent::CONTEXT_MAX_LENGTH);

    get('/help/context/'.$langt)->assertRedirect('/help');

    expect(hjalpmatningCount(
        CountHelpEvent::CONTEXT_UNKNOWN,
        '',
        mb_substr($langt, 0, CountHelpEvent::CONTEXT_MAX_LENGTH),
    ))->toBe(1)
        ->and(DB::table('help_event_daily')->count())->toBe(1);
});

it('stoppar nya okända sammanhang vid taket men räknar upp ett befintligt', function () {
    $dag = now()->toDateString();
    $rader = [];

    for ($i = 1; $i <= 999; $i++) {
        $rader[] = [
            'date' => $dag, 'event' => CountHelpEvent::CONTEXT_UNKNOWN,
            'article_id' => '', 'context' => "sammanhang.{$i}", 'count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    $rader[] = [
        'date' => $dag, 'event' => CountHelpEvent::CONTEXT_UNKNOWN,
        'article_id' => '', 'context' => 'befintligt', 'count' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('help_event_daily')->insert($rader);

    $counter = new CountHelpEvent;
    $counter->event(CountHelpEvent::CONTEXT_UNKNOWN, '', 'nytt.sammanhang');
    $counter->event(CountHelpEvent::CONTEXT_UNKNOWN, '', 'befintligt');

    expect(hjalpmatningCount(CountHelpEvent::CONTEXT_UNKNOWN, '', 'nytt.sammanhang'))->toBe(0)
        ->and(hjalpmatningCount(CountHelpEvent::CONTEXT_UNKNOWN, '', 'befintligt'))->toBe(2);
});

/*
 * Klart när: ett fel i upserten ger ändå 200 på sidan och en loggrad.
 */
it('sväljer ett fel i uppräkningen och loggar det', function () {
    withoutVite();

    $logg = Log::spy();

    Schema::drop('help_event_daily');

    get('/help/articles/how-do-i-share-a-container')->assertOk();

    $logg->shouldHaveReceived('warning')->once();
});

/*
 * Klart när: källkodsprov — HelpSearchBox.vue skickar log=1 efter 1500 ms
 * stillhet eller vid Enter, en gång per fråga.
 */
it('skickar log=1 efter 1500 ms stillhet eller vid Enter, en gång per fråga', function () {
    $kod = hjalpmatningKod('components/HelpSearchBox.vue');

    expect($kod)->toContain('LOG_DELAY_MS = 1500');
    expect($kod)->toContain('LOG_QUERY_LENGTH = 3');
    expect($kod)->toContain('loggedQuery');
    expect($kod)->toContain('term === loggedQuery');

    // Parametern sätts på ett ställe — mätfrågan — och inte på förslagen.
    expect(substr_count($kod, 'log=1'))->toBe(1);

    // Både stilleståndstimern och Enter-anropet går genom logSearch().
    expect(substr_count($kod, 'logSearch();'))->toBeGreaterThanOrEqual(2);
});

/*
 * Granskningens fynd 1: Enter räknar bara när en träff är markerad. De andra
 * två vägarna — *See all results* och Enter utan markering — leder till
 * `/help?q=…`, där HelpController::index räknar frågan på servern. Ett anrop
 * även i rutan hade gett `search` 2 per fråga.
 */
it('räknar Enter bara när en träff är markerad', function () {
    $kod = hjalpmatningKod('components/HelpSearchBox.vue');

    $enterPos = strpos($kod, "event.key === 'Enter'");
    $resultPos = $enterPos === false ? false : strpos($kod, 'const result = results.value[activeIndex.value];', $enterPos);

    expect($enterPos)->not->toBeFalse()
        ->and($resultPos)->not->toBeFalse();

    $enter = substr($kod, (int) $enterPos);

    // Anropet står inuti `if (result) { … }` — den gren där sidan inte
    // navigerar till /help?q=…
    expect(preg_match('/if \(result\) \{\s*logSearch\(\);/', $enter))->toBe(1);

    // Och inget anrop före markeringen, där formuläret tar över.
    $foreResultatet = substr($enter, 0, (int) $resultPos - (int) $enterPos);

    expect($foreResultatet)->not->toContain('logSearch();');
});

/*
 * API-svaret är oförändrat: mätningen lägger inget i JSON-kroppen.
 */
it('lämnar söksvaret orört', function () {
    $svar = get('/help/search?q=share&log=1')->assertOk()->json();

    expect($svar)->toHaveKeys(['query', 'partial', 'results']);
});
