<?php

use App\Models\Account;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

/*
 * Issue 36b · ICS-feeden som kalenderklienterna hämtar, andra halvan av
 * issue 36. Se App\Http\Controllers\CalendarFeedDownloadController,
 * App\Support\Notification\IcsDocument och routes/web.php § Beslut 1.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php, samma mönster som
 * IcsFeedTest-föregångaren KalenderfeedTest.
 *
 * Klockan fryses för varje test: "försenad" härleds av att `due_at` passerats
 * och `last_fetched_at` skrivs med now() — utan en fryst tid beror utfallet
 * på klockslaget när sviten körs.
 *
 * Feeden skapas i testerna direkt med fabriken, inte via POST:en (36a testar
 * ytan) — men med en KÄND klartext, så att GET /kalender/{token}.ics kan
 * anropas som kalenderklienten skulle. `token_hash` är sha256 av klartexten,
 * precis som produktionsvägen.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-05 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Skapar en calendar_feed-rad med känd klartext och returnerar [rad, token].
 *
 * @return array{0: CalendarFeed, 1: string}
 */
function kalenderfeedMedToken(Container $container, User $user, array $attribut = []): array
{
    $token = Str::random(64);

    $feed = CalendarFeed::factory()->create(array_merge([
        'container_id' => $container->id,
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
    ], $attribut));

    return [$feed, $token];
}

/**
 * Skapar en item→schema→förekomst-kedja under $container och returnerar
 * förekomsten. Överhoppningsbara nycklar i $attribut: `item_name`, `title`,
 * `is_active`, `due_at`, `visible_from`, `status`.
 */
function kalenderuppgift(Container $container, array $attribut = []): ScheduleOccurrence
{
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $attribut['item_name'] ?? 'Impeller',
    ]);

    $schedule = Schedule::factory()->for($item, 'item')->create([
        'title' => $attribut['title'] ?? 'Byt impeller',
        'is_active' => $attribut['is_active'] ?? true,
    ]);

    $dueAt = $attribut['due_at'] ?? '2027-05-05';

    return ScheduleOccurrence::factory()->for($schedule, 'schedule')->create([
        'due_at' => $dueAt,
        'visible_from' => $attribut['visible_from'] ?? $dueAt,
        'status' => $attribut['status'] ?? 'open',
    ]);
}

/**
 * @return array{0: Account, 1: User, 2: Container}
 */
function kalenderkonto(string $locale = 'sv_SE', string $containerNamn = 'Vindil'): array
{
    [$account, $user] = kontoMedMedlem();
    $user->forceFill(['locale' => $locale])->save();

    $container = Container::factory()->for($account, 'account')->create(['name' => $containerNamn]);

    return [$account, $user, $container];
}

it('en giltig token ger en kalender', function () {
    [, $user, $container] = kalenderkonto();
    [$feed, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller', 'item_name' => 'Impeller']);

    $response = get("/kalender/{$token}.ics");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $response->assertHeader('Content-Disposition', 'inline; filename="mimers.ics"');
    $cacheControl = $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private');
    expect($cacheControl)->toContain('max-age=3600');

    $kropp = $response->getContent();
    expect($kropp)->toStartWith('BEGIN:VCALENDAR');
    expect($kropp)->toContain('END:VCALENDAR');
    expect($kropp)->toContain('X-WR-CALNAME:Maintenance: Vindil');
    expect($kropp)->toContain('SUMMARY:Byt impeller');
    expect($kropp)->toContain('DESCRIPTION:Impeller');
});

it('en återkallad token ger 404', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user, ['revoked_at' => now()]);
    kalenderuppgift($container);

    get("/kalender/{$token}.ics")->assertNotFound();
});

it('en okänd token ger 404', function () {
    get('/kalender/'.Str::random(64).'.ics')->assertNotFound();
});

it('en manipulerad token avvisas av routern', function () {
    DB::enableQueryLog();

    $response = get('/kalender/'.str_repeat('x', 63).'.ics');

    DB::disableQueryLog();

    $response->assertNotFound();
    expect(DB::getQueryLog())->toBe([]);
});

it('öppna förekomster blir VEVENT', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller']);
    kalenderuppgift($container, ['title' => 'Serva motorn', 'item_name' => 'Motor']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect(substr_count($kropp, 'BEGIN:VEVENT'))->toBe(2);
    expect(substr_count($kropp, 'END:VEVENT'))->toBe(2);
    expect($kropp)->toContain('SUMMARY:Byt impeller');
    expect($kropp)->toContain('SUMMARY:Serva motorn');
});

it('avslutade och överhoppade förekomster utelämnas', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Öppen uppgift']);
    kalenderuppgift($container, ['title' => 'Avklarad uppgift', 'status' => 'completed']);
    kalenderuppgift($container, ['title' => 'Överhoppad uppgift', 'status' => 'skipped']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect(substr_count($kropp, 'BEGIN:VEVENT'))->toBe(1);
    expect($kropp)->toContain('SUMMARY:Öppen uppgift');
    expect($kropp)->not->toContain('SUMMARY:Avklarad uppgift');
    expect($kropp)->not->toContain('SUMMARY:Överhoppad uppgift');
});

it('en förekomst långt fram i tiden syns', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, [
        'title' => 'Byt impeller',
        'due_at' => '2027-05-05',
        'visible_from' => '2027-01-05',
    ]);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('SUMMARY:Byt impeller');
    expect($kropp)->toContain('DTSTART;VALUE=DATE:20270505');
});

it('bara den egna containerns förekomster syns', function () {
    [$account, $user] = kontoMedMedlem();
    $user->forceFill(['locale' => 'sv_SE'])->save();
    $vindil = Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);
    $bårösund = Container::factory()->for($account, 'account')->create(['name' => 'Bårösund']);

    [, $token] = kalenderfeedMedToken($vindil, $user);
    kalenderuppgift($vindil, ['title' => 'Byt impeller']);
    kalenderuppgift($bårösund, ['title' => 'Serva motorn']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('SUMMARY:Byt impeller');
    expect($kropp)->not->toContain('SUMMARY:Serva motorn');
});

it('en återkallad åtkomst tömmer feeden', function () {
    [$account] = kontoMedMedlem();
    $gäst = User::factory()->create(['locale' => 'sv_SE']);
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);
    $access = beviljaAccess($container, $gäst, 'read', 'member');

    [, $token] = kalenderfeedMedToken($container, $gäst);
    kalenderuppgift($container, ['title' => 'Byt impeller']);

    expect(get("/kalender/{$token}.ics")->getContent())->toContain('BEGIN:VEVENT');

    $access->forceFill(['revoked_at' => now()])->save();

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();
    expect($kropp)->toContain('BEGIN:VCALENDAR');
    expect($kropp)->not->toContain('BEGIN:VEVENT');
});

it('en container i papperskorgen ger en tom kalender', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller']);

    $container->delete();

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('BEGIN:VCALENDAR');
    expect($kropp)->toContain('END:VCALENDAR');
    expect($kropp)->toContain('X-WR-CALNAME:Maintenance: Vindil');
    expect($kropp)->not->toContain('BEGIN:VEVENT');
});

it('DTEND är dagen efter due_at', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller', 'due_at' => '2027-05-05']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('DTSTART;VALUE=DATE:20270505');
    expect($kropp)->toContain('DTEND;VALUE=DATE:20270506');
});

it('raderna avslutas med CRLF', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain("\r\n");
    expect(str_replace("\r\n", '', $kropp))->not->toContain("\n");
    expect($kropp)->toEndWith("END:VCALENDAR\r\n");
});

it('kommatecken och semikolon i namn flyktas', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt segel', 'item_name' => 'Vega, S/Y; skrov']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('DESCRIPTION:Vega\, S/Y\; skrov');
    expect($kropp)->not->toContain('DESCRIPTION:Vega, S/Y; skrov');

    // Och ett `\` i en titel flyktas till `\\`.
    kalenderuppgift($container, ['title' => 'Växel\\justering', 'item_name' => 'Växel']);
    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();
    expect($kropp)->toContain('SUMMARY:Växel\\\\justering');
});

it('långa rader viks utan att dela ett tecken', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    $titel = 'Årligen återkommande underhåll av rigg och mast med '.str_repeat('åäö', 30);
    kalenderuppgift($container, ['title' => $titel]);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    $rader = explode("\r\n", rtrim($kropp, "\r\n"));
    $harFortsttning = false;

    foreach ($rader as $rad) {
        expect(strlen($rad))->toBeLessThanOrEqual(75);
        expect(mb_check_encoding($rad, 'UTF-8'))->toBeTrue();

        if (str_starts_with($rad, ' ')) {
            $harFortsttning = true;
        }
    }

    expect($harFortsttning)->toBeTrue();

    // Vik upp: ta bort radbrytning + inledande mellanslag — texten ska gå
    // att sätta ihop igen till exakt den logiska raden.
    $uppvikt = str_replace("\r\n ", '', $kropp);
    expect($uppvikt)->toContain('SUMMARY:'.$titel);
});

it('UID är stabilt mellan två hämtningar', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller']);

    $första = get("/kalender/{$token}.ics")->assertOk()->getContent();
    $andra = get("/kalender/{$token}.ics")->assertOk()->getContent();

    preg_match('/UID:([^\r\n]+)/', $första, $förstaTräff);
    preg_match('/UID:([^\r\n]+)/', $andra, $andraTräff);

    expect($förstaTräff[1] ?? null)->toBeString();
    expect($andraTräff[1] ?? null)->toBe($förstaTräff[1] ?? null);
});

it('en försenad förekomst får prefix i SUMMARY', function () {
    [, $user, $container] = kalenderkonto('sv_SE');
    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller', 'due_at' => '2026-09-01']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('SUMMARY:Overdue: Byt impeller');
});

/*
 * Klart när (issue 516): klockan 23:30 UTC sätter ICS-flödet försenad-
 * prefixet på en förekomst vars `due_at` är feedens användares gårdag.
 * Serverns datum är den 24:e, hennes är den 25:e, så prefixet följer hennes
 * kalender och inte serverns klocka ([[ADR-0044 Användarens dag]] § Beslut 2).
 */
it('sätter försenad-prefixet mot feedens användares dag klockan 23:30 UTC', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [, $user, $container] = kalenderkonto('sv_SE');

    expect($user->today()->toDateString())->toBe('2026-09-25')
        ->and(Carbon::today()->toDateString())->toBe('2026-09-24');

    [, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container, ['title' => 'Byt impeller', 'due_at' => '2026-09-24']);

    $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

    expect($kropp)->toContain('SUMMARY:Overdue: Byt impeller');
});

/*
 * Kalendern är enspråkig. Två mottagare med olika locale i samma konto får
 * samma namn, och dokumentet blandar inte in ett svenskt produktnamn i den
 * engelska texten — `PRODID` bar `//SV` och "Kalenderfeed" fram till M13.
 * Se [[ADR-0034 Engelska vid lansering]].
 */
it('skriver kalendern på engelska för varje mottagare', function () {
    [$account] = kontoMedMedlem();
    $svensk = User::factory()->create(['locale' => 'sv_SE']);
    $engelsman = User::factory()->create(['locale' => 'en_GB']);
    $account->users()->attach($svensk, ['role' => 'owner']);
    $account->users()->attach($engelsman, ['role' => 'owner']);

    $container = Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);
    [, $svenskToken] = kalenderfeedMedToken($container, $svensk);
    [, $engelskToken] = kalenderfeedMedToken($container, $engelsman);
    kalenderuppgift($container, ['title' => 'Byt impeller']);

    foreach ([$svenskToken, $engelskToken] as $token) {
        $kropp = get("/kalender/{$token}.ics")->assertOk()->getContent();

        expect($kropp)->toContain('PRODID:-//Mimers//Calendar feed//EN');
        expect($kropp)->toContain('X-WR-CALNAME:Maintenance: Vindil');
    }
});

it('last_fetched_at uppdateras', function () {
    [, $user, $container] = kalenderkonto();
    [$feed, $token] = kalenderfeedMedToken($container, $user);
    kalenderuppgift($container);

    $rad = DB::table('calendar_feed')->where('ulid', $feed->ulid)->first();
    expect($rad->last_fetched_at)->toBeNull();
    $updatedInnan = $rad->updated_at;

    get("/kalender/{$token}.ics")->assertOk();

    $efter = DB::table('calendar_feed')->where('ulid', $feed->ulid)->first();
    expect($efter->last_fetched_at)->toBe('2026-09-05 10:00:00');
    expect($efter->updated_at)->toBe($updatedInnan);
});

it('en tom container ger en giltig tom kalender', function () {
    [, $user, $container] = kalenderkonto();
    [, $token] = kalenderfeedMedToken($container, $user);

    $response = get("/kalender/{$token}.ics");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    $kropp = $response->getContent();
    expect($kropp)->toContain('BEGIN:VCALENDAR');
    expect($kropp)->toContain('END:VCALENDAR');
    expect($kropp)->not->toContain('BEGIN:VEVENT');
});
