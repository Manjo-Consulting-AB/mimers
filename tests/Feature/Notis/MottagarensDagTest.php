<?php

use App\Console\GeneratesLoanNotifications;
use App\Console\GeneratesTaskNotifications;
use App\Models\Account;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

/*
 * Issue 516 · Bakgrundsjobben och kalenderflödet räknar mottagarens dag.
 * Se [[ADR-0044 Användarens dag]] § Beslut 2, App\Console\
 * GeneratesTaskNotifications, App\Console\GeneratesLoanNotifications,
 * App\Support\Notification\IcsDocument och
 * App\Http\Controllers\CalendarFeedDownloadController.
 *
 * **Filen prövar den halvan som de enskilda notistesterna inte når: att
 * dagen är MOTTAGARENS och inte kontots.** Två medlemmar i samma konto, i
 * samma container, med samma förekomst och samma lån kan få olika svar under
 * de timmar deras kalenderdatum skiljer sig — och det är rätt, de lever på
 * var sin dag (ADR-0044 § Konsekvenser). Hjälparna har prefixet `mottagar`:
 * Pest lägger alla testfiler i samma namnrymd när hela sviten körs.
 *
 * **Klockan är vald för att ligga i fönstret där de tre datumen skiljer sig.**
 * 2026-09-25 02:00 UTC är serverns den 25:e, 04:00 samma dag i
 * Europe/Stockholm (CEST, UTC+2) och 22:00 den 24:e i America/New_York
 * (EDT, UTC−4). Stockholmarens dag är alltså den 25:e, newyorkarens den
 * 24:e — samma förekomst är försenad för den ene och dagens för den andre.
 *
 * "Oförändrat mitt på dagen" prövas inte här: de befintliga notis- och
 * ICS-testerna fryser klockan mitt på dagen och går oförändrade, vilket är
 * just det beviset. Den svenska 23:30-halvan prövas i respektive fils eget
 * test (UppgiftsnotisTest, UtlaningsnotisTest, IcsFeedTest).
 *
 * kontoMedMedlem(), oppnaForekomst() och sättPlangräns() är globala
 * testhjälpare i tests/Support/Testhjalpare.php.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-25 02:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto i Europe/Stockholm med två ägare: en vars egen tidszon är
 * America/New_York och en utan egen tidszon, som därför ärver kontots. Pro-
 * planen sätts för att låna ut notispåminnelser (Beslut 6 i issue 76).
 *
 * @return array{0: Account, 1: User, 2: User}
 */
function mottagardagKonto(): array
{
    $account = Account::factory()->create(['timezone' => 'Europe/Stockholm']);

    $newYork = User::factory()->create(['timezone' => 'America/New_York']);
    $stockholm = User::factory()->create(['timezone' => null]);

    $account->users()->attach($newYork, ['role' => 'owner']);
    $account->users()->attach($stockholm, ['role' => 'owner']);

    $proPlan = Plan::query()->where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account, 'account')->for($proPlan, 'plan')->create();

    return [$account, $newYork, $stockholm];
}

/**
 * En container under $account.
 */
function mottagardagParm(Account $account): Container
{
    return Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);
}

/**
 * En öppen förekomst på ett item i $container, med `due_at` = $due och
 * `visible_from` = $due.
 */
function mottagardagUppgift(Container $container, User $user, string $titel, string $due): ScheduleOccurrence
{
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $titel,
        'created_by_user_id' => $user->id,
    ]);

    [, $occurrence] = oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => $due,
        'lead_days' => 0,
    ]);

    return $occurrence;
}

/**
 * En calendar_feed-rad med känd klartext; returnerar token.
 */
function mottagardagFeedMedToken(Container $container, User $user): string
{
    $token = Str::random(64);

    CalendarFeed::factory()->create([
        'container_id' => $container->id,
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
    ]);

    return $token;
}

it('ger varje mottagare sin egen dag i uppgiftsnotisen', function () {
    [$account, $newYork, $stockholm] = mottagardagKonto();
    $container = mottagardagParm($account);

    expect($stockholm->today()->toDateString())->toBe('2026-09-25')
        ->and($newYork->today()->toDateString())->toBe('2026-09-24');

    // Den 24:e är gårdagen för stockholmaren och i dag för newyorkaren.
    $occurrence = mottagardagUppgift($container, $stockholm, 'Byt impeller', '2026-09-24');

    app(GeneratesTaskNotifications::class)->handle();

    $typer = Notification::query()->pluck('type', 'user_id');

    expect(Notification::query()->count())->toBe(2)
        ->and($typer[$stockholm->id])->toBe(Notification::TYPE_TASK_OVERDUE)
        ->and($typer[$newYork->id])->toBe(Notification::TYPE_TASK_DUE)
        ->and(Notification::query()->firstOrFail()->subject_id)->toBe($occurrence->id);
});

it('ger varje mottagare sin egen dag i påminnelsefönstret för lån', function () {
    [$account, $newYork, $stockholm] = mottagardagKonto();
    $container = mottagardagParm($account);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Impeller',
        'created_by_user_id' => $stockholm->id,
    ]);

    // Fönstret är `due_at <= användarens dag + remind_days_before` (3):
    // den 28:e för stockholmaren, den 27:e för newyorkaren. Lånet förfaller
    // på stockholmarens sista dag och faller alltså utanför newyorkarens.
    $lån = Loan::factory()->for($item, 'item')->create([
        'borrower_name' => 'Anna Andersson',
        'borrower_email' => null,
        'lent_at' => '2026-09-01',
        'due_at' => '2026-09-28',
        'returned_at' => null,
    ]);

    app(GeneratesLoanNotifications::class)->handle();

    expect(Notification::query()->where('user_id', $stockholm->id)->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $newYork->id)->count())->toBe(0)
        ->and(Notification::query()->firstOrFail()->subject_id)->toBe($lån->id);
});

it('ger varje mottagare sin egen dag i kalenderflödet', function () {
    [$account, $newYork, $stockholm] = mottagardagKonto();
    $container = mottagardagParm($account);

    $svenskToken = mottagardagFeedMedToken($container, $stockholm);
    $amerikanskToken = mottagardagFeedMedToken($container, $newYork);

    // Den 24:e är stockholmarens gårdag och newyorkarens i dag: samma
    // förekomst är försenad i den ena kalendern och dagens i den andra.
    $item = Item::factory()->for($container, 'container')->create(['name' => 'Impeller']);
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Byt impeller',
        'is_active' => true,
    ]);
    ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'due_at' => '2026-09-24',
        'visible_from' => '2026-09-24',
        'status' => 'open',
    ]);

    $svensk = get("/kalender/{$svenskToken}.ics")->assertOk()->getContent();
    $amerikansk = get("/kalender/{$amerikanskToken}.ics")->assertOk()->getContent();

    expect($svensk)->toContain('SUMMARY:Overdue: Byt impeller')
        ->and($amerikansk)->toContain('SUMMARY:Byt impeller')
        ->and($amerikansk)->not->toContain('Overdue');
});
