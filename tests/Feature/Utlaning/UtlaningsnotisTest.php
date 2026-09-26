<?php

use App\Console\DeliversNotifications;
use App\Console\GeneratesLoanNotifications;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Notification\EmailChannel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

/*
 * Issue 76 · Utlåningsnotiserna — loan.due för öppna utlåningar vars
 * förfallodatum närmar sig. Se App\Console\GeneratesLoanNotifications,
 * [[Notiser]] § Kön och § notification, [[Items och organisation]] § loan
 * samt [[ADR-0017 Missbruksvektorer]] § 7.
 *
 * kontoMedMedlem(), beviljaAccess(), epostLeverans() och mejletsÄmne() är
 * globala testhjälpare i tests/Support/Testhjalpare.php.
 *
 * Klockan fryses där due_at-fönstret avgör utfallet: notiserna skapas för
 * `due_at <= idag + remind_days_before` (default 3 dagar), så utan en fryst
 * tid beror utfallet på klockslaget när sviten körs. Varje "Klart när"-punkt
 * i issuen motsvarar ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en ägare och, när $pro är sant, en pro-plan; plus en
 * container och ett item på kontot. Ett konto utan prenumeration faller på
 * gratisplanen, där `loan_reminders` är false (Beslut 6).
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function utlaningsnotisKontext(bool $pro = true): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);

    if ($pro) {
        $proPlan = Plan::query()->where('code', 'pro')->firstOrFail();
        Subscription::factory()->for($account, 'account')->for($proPlan, 'plan')->create();
    }

    $container = Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Impeller',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $container, $item];
}

/**
 * Ett öppet lån på $item som förfaller 2026-09-07 — tre dagar efter den
 * frysta dagens datum 2026-09-04, så det ligger i påminnelsefönstret.
 */
function utlaningsnotisLan(Item $item, array $attribut = []): Loan
{
    return Loan::factory()->for($item, 'item')->create(array_merge([
        'borrower_name' => 'Anna Andersson',
        'borrower_email' => null,
        'lent_at' => '2026-09-01',
        'due_at' => '2026-09-07',
        'returned_at' => null,
    ], $attribut));
}

/**
 * Kör generatorn precis som schemaläggningen gör.
 */
function utlaningsnotisKor(): void
{
    app(GeneratesLoanNotifications::class)->handle();
}

it('en öppen utlåning på pro-kontot med due_at i fönstret ger en loan.due till ägarkontots medlem', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user, $container, $item] = utlaningsnotisKontext();
    $lån = utlaningsnotisLan($item);

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(1);
    $notis = Notification::query()->firstOrFail();
    expect($notis->type)->toBe(Notification::TYPE_LOAN_DUE);
    expect($notis->user_id)->toBe($user->id);
    expect($notis->account_id)->toBe($account->id);
    expect($notis->container_id)->toBe($container->id);
    expect($notis->subject_id)->toBe($lån->id);
    expect($notis->dedupe_key)->toBe('loan.due:'.$lån->ulid.':'.$user->ulid);

    // Payloaden bär item, container, borrower och datum — borrower är
    // borrower_name, aldrig borrower_email (Beslut 5).
    expect($notis->payload)->toBe([
        'item' => 'Impeller',
        'container' => 'Vindil',
        'borrower' => 'Anna Andersson',
        'date' => '2026-09-07',
    ]);
});

it('en utlåning vars due_at ligger bortom fönstret genererar ingen notis', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [, , , $item] = utlaningsnotisKontext();
    utlaningsnotisLan($item, ['due_at' => '2026-10-01']);

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(0);
});

it('jobbet kört två gånger samma dygn ger fortfarande en notisrad och en leveransrad', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user, $container, $item] = utlaningsnotisKontext();
    utlaningsnotisLan($item);

    utlaningsnotisKor();
    utlaningsnotisKor();

    // dedupe_key saknar datumdel (Beslut 3): ett lån får exakt en påminnelse,
    // inte en per dygn så länge det är öppet.
    expect(Notification::query()->count())->toBe(1);
    expect(NotificationDelivery::query()->count())->toBe(1);
});

/*
 * Klart när (issue 516): klockan 23:30 UTC räknas påminnelsefönstret från
 * användarens dag, både vid kanten innanför och vid kanten utanför.
 * Serverns datum är den 24:e, den svenska användarens är den 25:e, så
 * fönstret är `due_at <= 2026-09-28` — en dag längre fram än serverns
 * `Carbon::today()` gav. Två lån läggs ut på var sin sida om gränsen för att
 * visa att det är just kanten som flyttats och inte filtret i sig
 * ([[ADR-0044 Användarens dag]] § Beslut 2).
 */
it('räknar påminnelsefönstret från användarens dag klockan 23:30 UTC', function () {
    Carbon::setTestNow('2026-09-24 23:30:00');

    [$account, $user, $container, $item] = utlaningsnotisKontext();

    expect($user->today()->toDateString())->toBe('2026-09-25')
        ->and(Carbon::today()->toDateString())->toBe('2026-09-24');

    $innanfor = utlaningsnotisLan($item, ['due_at' => '2026-09-28']);
    utlaningsnotisLan($item, ['due_at' => '2026-09-29']);

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(1);
    expect(Notification::query()->firstOrFail()->subject_id)->toBe($innanfor->id);
});

it('en registrerad användare med låntagarens adress och utan åtkomst får noll notiser och noll mejl', function () {
    Mail::fake();
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$konto, $ägare, $container, $item] = utlaningsnotisKontext();
    $lån = utlaningsnotisLan($item, ['borrower_email' => 'låntagare@exempel.se']);

    // En registrerad användare vars adress är exakt lånets borrower_email,
    // på ett eget konto, utan någon åtkomst till containern.
    [$låntagarKonto, $låntagare] = kontoMedMedlem();
    $låntagare->update(['email' => 'låntagare@exempel.se']);

    utlaningsnotisKor();

    // borrower_email får aldrig dyka upp i ett User-uppslag (Beslut 2):
    // låntagaren är inte medlem i ägarkontot och får ingen rad alls.
    expect(Notification::query()->where('user_id', $låntagare->id)->count())->toBe(0);
    expect(Notification::query()->where('user_id', $ägare->id)->count())->toBe(1);

    // Leveransloopen körs med Mail::fake: det enda mejlet går till ägaren,
    // inget utskick har låntagarens adress som mottagare.
    app(DeliversNotifications::class)->handle();

    expect(NotificationDelivery::query()->where('status', NotificationDelivery::STATUS_SENT)->count())->toBe(1);
    $mejl = Mail::sent(NotificationMail::class);
    expect($mejl)->toHaveCount(1);
    expect($mejl->first()->hasTo('låntagare@exempel.se'))->toBeFalse();
    expect($mejl->first()->hasTo($ägare->email))->toBeTrue();
});

it('ett gratiskonto får ingen notis för samma utlåning', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user, $container, $item] = utlaningsnotisKontext(pro: false);
    utlaningsnotisLan($item);

    // Sätt gränsen explicit — raden är testets premiss, inte dess mekanik
    // (Beslut 10): seedet har redan false på gratisplanen, men ett test vars
    // förutsättning står i en migrering byter tyst påstående den dag radens
    // värde ändras.
    sättPlangräns('free', 'loan_reminders', false);

    utlaningsnotisKor();

    // Grinden läses, den kastar inte (Beslut 6): kontot hoppas över i
    // stället för att logga ett fångat undantag.
    expect(Notification::query()->count())->toBe(0);
});

it('ett pro-konto med loan_reminders satt till false får ingen notis för samma utlåning', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [, , , $item] = utlaningsnotisKontext();
    utlaningsnotisLan($item);

    // Det test som faktiskt bevisar Beslut 6: grinden läser
    // planLimit('loan_reminders'), inte planens slug. `=== false` mot en
    // gratisplan går igenom även om implementationen råkar vara
    // `$plan->slug === 'free'`; ett pro-konto vars gräns är false gör det inte.
    sättPlangräns('pro', 'loan_reminders', false);

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(0);
});

it('en återlämnad utlåning genererar ingen notis', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [, , , $item] = utlaningsnotisKontext();
    utlaningsnotisLan($item, ['returned_at' => '2026-09-03']);

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(0);
});

it('en utlåning utan due_at genererar ingen notis', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [, , , $item] = utlaningsnotisKontext();
    utlaningsnotisLan($item, ['due_at' => null]);

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(0);
});

it('en mjukraderad :rad ger ingen notis', function (string $rad) {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user, $container, $item] = utlaningsnotisKontext();
    $lån = utlaningsnotisLan($item);

    match ($rad) {
        'utlåning' => $lån->delete(),
        'item' => $item->delete(),
        'container' => $container->delete(),
        default => throw new RuntimeException("Okänd rad att mjukradera: {$rad}"),
    };

    utlaningsnotisKor();

    expect(Notification::query()->count())->toBe(0);
})->with(['utlåning', 'item', 'container']);

it('en användare med bara giltig container_access får ingen utlåningsnotis', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$ägarKonto, $ägare, $container, $item] = utlaningsnotisKontext();
    utlaningsnotisLan($item);

    // Gästen når containern via den delegerade åtkomsten, men påminnelser
    // går bara till ägarkontots medlemmar (Beslut 2).
    [$gästKonto, $gäst] = kontoMedMedlem();
    beviljaAccess($container, $gäst, 'read', 'guest');

    utlaningsnotisKor();

    expect(Notification::query()->where('user_id', $gäst->id)->count())->toBe(0);
    expect(Notification::query()->where('user_id', $ägare->id)->count())->toBe(1);
});

it('loan_due finns i språkfilen och e-postkanalen renderar notisen utan att kasta', function () {
    // Beslut 4: EmailChannel slår upp notiser.loan_due och kastar om nyckeln
    // saknas i mottagarens språk — en generator som skapar rader utan mall
    // ger en tyst kö av trasiga leveranser.
    expect(Lang::has('notiser.loan_due', 'en', false))->toBeTrue();

    Mail::fake();
    [$konto, $användare] = kontoMedMedlem();
    $delivery = epostLeverans($konto, $användare, Notification::TYPE_LOAN_DUE, [
        'item' => 'Impeller',
        'container' => 'Vindil',
        'borrower' => 'Anna Andersson',
        'date' => '2026-09-07',
    ]);

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    expect($mail)->not->toBeNull();
    expect(mejletsÄmne($mail))->toBe('Impeller is due back 2026-09-07');
});

it('jobbet är schemalagt dagligen med call, inte command eller runInBackground', function () {
    // artisan(...) tvingar konsol-kerneln att bootstrapas, vilket i sin tur
    // laddar routes/console.php — den laddas annars inte under en vanlig
    // HTTP-/testrequest. "inspire" är ett ofarligt, redan existerande
    // kommando, valt bara för att trigga bootstrapet.
    artisan('inspire');

    $händelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'generate-loan-notifications');

    expect($händelse)->not->toBeNull();
    expect($händelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Driftmiljön saknar proc_open.
    // CallbackEvent (Schedule::call/job) har inget `command`-strängvärde,
    // till skillnad från Schedule::command() som bygger en "php artisan
    // ..."-sträng avsedd att köras via Symfony Process.
    expect($händelse->command ?? null)->toBeNull();
    expect($händelse->runInBackground)->toBeFalse();
});
