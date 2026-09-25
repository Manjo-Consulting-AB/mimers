<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 135 · Idag är användarens idag. Se App\Models\User::today(),
 * App\Models\User::preferredTimezone(), App\Models\ScheduleOccurrence::
 * scopeTodoFor(), App\Actions\Schedule\ListTodo,
 * App\Http\Resources\TodoEntryResource och
 * App\Http\Resources\ScheduleOccurrenceResource.
 *
 * **Felet som filen bevisar.** Servern räknar i UTC (`config('app.timezone')`)
 * och tidszonen bor på användaren, med kontots som reserv ([[Konton och
 * åtkomst]] § user). Mellan midnatt och klockan två svensk sommartid är
 * serverns datum fortfarande gårdagen: en uppgift som förfaller i dag låg under
 * *kommande*, en från i går var inte försenad, och en vars `visible_from` var
 * i dag syntes inte alls.
 *
 * **Klockan i proven är vald för att ligga i det fönstret.** 2026-09-24
 * 23:30 UTC är 2026-09-25 01:30 i Europe/Stockholm (CEST, UTC+2) — serverns
 * datum är den 24:e, användarens är den 25:e. Ett prov som kördes mitt på dagen
 * hade gett samma svar före och efter ändringen och bevisat ingenting, så
 * "oförändrat mitt på dagen" prövas uttryckligen för sig.
 *
 * **Datumet jämförs som datum, inte som ögonblick.** `due_at` och
 * `visible_from` är DATE-kolumner, och midnatt i Stockholm är ett annat
 * ögonblick än midnatt i UTC. `User::today()` bygger därför om användarens
 * datum till midnatt i APPENS tidszon, och frågorna läser `toDateString()`.
 * Provet "är midnatt i appens tidszon och inte i användarens" håller den
 * skillnaden fast: hade `today()` gett midnatt i Stockholm hade jämförelsen
 * mot en DATE-kolumn pekat en dag fel.
 *
 * **`upcoming` kom med issue 133** och räknas mot samma dag. Fältet ligger
 * bredvid `overdue` i TodoEntryResource, och rättningen av `overdue` var inte
 * hela felet: en rad som förföll i dag låg under *kommande* just därför att
 * "efter serverns datum" såg ut som framtid. Provet "gör en förekomst som
 * förfaller i den lokala dagen inte upcoming" håller den halvan fast.
 *
 * Kostnadsmånaden på dashboarden räknas också ur användarens tidszon sedan
 * issue 125, nu genom `User::preferredTimezone()`. Det beteendet prövas i
 * tests/Feature/Kostnad/DashboardkostnadTest.php — inklusive reserven — och
 * upprepas inte här.
 *
 * Hjälparna har prefixet `dag` — Pest lägger alla testfiler i samma namnrymd
 * när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem i. Kontots tidszon är satt och användarens är NULL
 * som förval — reserven är själva poängen, och `Account::factory()` ger
 * Europe/Stockholm.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function dagKonto(string $kontoTidszon = 'Europe/Stockholm', ?string $anvandarTidszon = null): array
{
    $konto = Account::factory()->create(['timezone' => $kontoTidszon]);
    $anvandare = User::factory()->create(['timezone' => $anvandarTidszon]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $token = $anvandare->createToken('api');

    return [$konto, $anvandare, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * En container under $konto.
 */
function dagParm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => 'Bårösund']);
}

/**
 * Ett item med ett aktivt schema och dess öppna förekomst: `due_at` = $due,
 * `visible_from` = $due minus $leadDagar.
 *
 * @return array{0: Item, 1: Schedule, 2: ScheduleOccurrence}
 */
function dagUppgift(Container $container, Account $konto, User $anvandare, string $titel, string $due, int $leadDagar = 0): array
{
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $titel,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    [$schema, $forekomst] = oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => $due,
        'lead_days' => $leadDagar,
    ]);

    return [$item, $schema, $forekomst];
}

/**
 * En grupp ur `/tasks`-svaret.
 *
 * @return list<array<string, mixed>>
 */
function dagGrupp(TestResponse $svar, string $namn): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return $grupper[$namn];
}

// --- tidszonen -------------------------------------------------------------

it('ger användarens tidszon när den är satt', function () {
    [, $anvandare] = dagKonto('Europe/Stockholm', 'America/New_York');

    expect($anvandare->preferredTimezone())->toBe('America/New_York');
});

it('faller tillbaka på kontots tidszon när användarens saknas', function () {
    [, $anvandare] = dagKonto('Europe/Stockholm', null);

    expect($anvandare->preferredTimezone())->toBe('Europe/Stockholm');
});

it('faller tillbaka på appens tidszon när användaren inte har något konto', function () {
    $anvandare = User::factory()->create(['timezone' => null]);

    expect($anvandare->preferredTimezone())->toBe(config('app.timezone'));
});

// --- användarens datum -----------------------------------------------------

it('ger användarens kalenderdatum när UTC-datumet är ett annat', function () {
    Carbon::setTestNow('2026-09-25 23:30:00');

    [, $anvandare] = dagKonto('Europe/Stockholm');

    expect(Carbon::now()->toDateString())->toBe('2026-09-25')
        ->and($anvandare->today()->toDateString())->toBe('2026-09-26');
});

/*
 * Datumet är midnatt i APPENS tidszon, inte i användarens. Hade `today()`
 * gett `Carbon::today('Europe/Stockholm')` — midnatt i Stockholm, alltså
 * 22:00 UTC dagen innan — hade jämförelsen mot en DATE-kolumn pekat en dag fel.
 */
it('är midnatt i appens tidszon och inte i användarens', function () {
    Carbon::setTestNow('2026-09-25 23:30:00');

    [, $anvandare] = dagKonto('Europe/Stockholm');

    $idag = $anvandare->today();

    expect($idag->format('Y-m-d H:i:s'))->toBe('2026-09-26 00:00:00')
        ->and($idag->getTimezone()->getName())->toBe(config('app.timezone'));
});

it('ger en användare i New York sitt eget datum klockan 02:00 UTC', function () {
    Carbon::setTestNow('2026-09-25 02:00:00');

    [, $anvandare] = dagKonto('Europe/Stockholm', 'America/New_York');

    // 2026-09-25 02:00 UTC är 2026-09-24 22:00 i New York (EDT, UTC−4).
    expect(Carbon::now()->toDateString())->toBe('2026-09-25')
        ->and($anvandare->today()->toDateString())->toBe('2026-09-24');
});

// --- todo-urvalet klockan 01:30 svensk tid ---------------------------------

it('lägger en förekomst som förfaller i dag under today på /tasks', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare] = dagKonto();
    $container = dagParm($konto);

    // Framförhållning på morgondagens uppgift: `visible_from` är `due_at` minus
    // `lead_days`, och en uppgift som ännu inte blivit synlig ligger utanför
    // urvalet oavsett grupp.
    [, , $idag] = dagUppgift($container, $konto, $anvandare, 'Byt impeller', '2026-09-25');
    [, , $imorgon] = dagUppgift($container, $konto, $anvandare, 'Byt olja', '2026-09-26', 7);

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(array_column(dagGrupp($svar, 'today'), 'ulid'))->toBe([$idag->ulid])
        ->and(array_column(dagGrupp($svar, 'upcoming'), 'ulid'))->toBe([$imorgon->ulid]);
});

it('lägger samma förekomst i /api/todo med overdue false', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = dagKonto();
    $container = dagParm($konto);

    [, , $idag] = dagUppgift($container, $konto, $anvandare, 'Byt impeller', '2026-09-25');

    $svar = getJson('/api/todo', $headers);

    $svar->assertOk();
    expect($svar->json('data.0.ulid'))->toBe($idag->ulid)
        ->and($svar->json('data.0.due_at'))->toBe('2026-09-25')
        ->and($svar->json('data.0.overdue'))->toBeFalse();
});

/*
 * Klart när: klockan 23:30 UTC är en rad som förfaller på det svenska
 * datumet inte `upcoming` för en användare i Europe/Stockholm (issue 133).
 *
 * Det är `upcoming`s halva av samma fel som provet ovanför bevisar för
 * `overdue`, och spegelbilden är hela poängen: serverns datum är den 24:e,
 * användarens är den 25:e, och en rad som förfaller den 25:e ser "framtida" ut
 * för var och en som jämför mot serverns klocka. Raden i morgon är framtida
 * för båda och står med för att visa att fältet inte bara är false.
 */
it('gör en förekomst som förfaller i den lokala dagen inte upcoming', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = dagKonto();
    $container = dagParm($konto);

    [, , $idag] = dagUppgift($container, $konto, $anvandare, 'Byt impeller', '2026-09-25');

    // Framförhållning sju dagar: visible_from blir 2026-09-19, alltså synlig
    // trots att den förfaller först i morgon.
    [, , $imorgon] = dagUppgift($container, $konto, $anvandare, 'Byt olja', '2026-09-26', 7);

    $svar = getJson('/api/todo', $headers);

    $svar->assertOk();
    expect($svar->json('data.0.ulid'))->toBe($idag->ulid)
        ->and($svar->json('data.0.overdue'))->toBeFalse()
        ->and($svar->json('data.0.upcoming'))->toBeFalse()
        ->and($svar->json('data.1.ulid'))->toBe($imorgon->ulid)
        ->and($svar->json('data.1.upcoming'))->toBeTrue();
});

/*
 * Båda förekomstformerna: TodoEntryResource genom `/api/todo` och
 * ScheduleOccurrenceResource genom schemats förekomstlista. Under serverns
 * datum är den här raden "i dag" och alltså inte försenad — det är hela felet.
 */
it('gör en förekomst från den lokala dagen innan försenad i båda formerna', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = dagKonto();
    $container = dagParm($konto);

    [$item, $schema, $igar] = dagUppgift($container, $konto, $anvandare, 'Byt impeller', '2026-09-24');

    $todo = getJson('/api/todo', $headers);
    $todo->assertOk();
    expect($todo->json('data.0.ulid'))->toBe($igar->ulid)
        ->and($todo->json('data.0.overdue'))->toBeTrue();

    $lista = getJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences",
        $headers,
    );
    $lista->assertOk();
    expect($lista->json('data.0.ulid'))->toBe($igar->ulid)
        ->and($lista->json('data.0.overdue'))->toBeTrue();
});

it('visar en förekomst vars visible_from är den lokala dagen', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$konto, $anvandare, $headers] = dagKonto();
    $container = dagParm($konto);

    // due 2026-10-30 med 35 dagars framförhållning ger visible_from 2026-09-25 —
    // användarens i dag, men inte serverns.
    [, , $synlig] = dagUppgift($container, $konto, $anvandare, 'Byt impeller', '2026-10-30', 35);

    $svar = getJson('/api/todo', $headers);

    $svar->assertOk();
    expect($synlig->visible_from->toDateString())->toBe('2026-09-25')
        ->and(collect($svar->json('data'))->pluck('ulid')->all())->toBe([$synlig->ulid]);
});

// --- oförändrat mitt på dagen ----------------------------------------------

/*
 * Vid tio UTC är UTC-datumet och det svenska datumet detsamma, och utfallet
 * ska vara exakt det det var före issue 135. Det är halvan som gör ändringen
 * till en rättning och inte en omdefiniering: de befintliga proven — som alla
 * fryser klockan mitt på dagen — går oförändrade.
 */
it('ger samma datum och samma grupper som servern mitt på dagen', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-25 10:00:00');

    [$konto, $anvandare] = dagKonto();
    $container = dagParm($konto);

    expect($anvandare->today()->toDateString())->toBe(Carbon::today()->toDateString());

    [, , $igar] = dagUppgift($container, $konto, $anvandare, 'Försenad', '2026-09-24');
    [, , $idag] = dagUppgift($container, $konto, $anvandare, 'I dag', '2026-09-25');
    [, , $imorgon] = dagUppgift($container, $konto, $anvandare, 'I morgon', '2026-09-26', 7);

    $svar = actingAs($anvandare)->get('/tasks')->assertOk();

    expect(array_column(dagGrupp($svar, 'overdue'), 'ulid'))->toBe([$igar->ulid])
        ->and(array_column(dagGrupp($svar, 'today'), 'ulid'))->toBe([$idag->ulid])
        ->and(array_column(dagGrupp($svar, 'upcoming'), 'ulid'))->toBe([$imorgon->ulid]);
});
