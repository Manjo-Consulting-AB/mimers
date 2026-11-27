<?php

use App\Actions\Notification\CreateNotification;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 127 · Notisklockan i sidhuvudet, se [[M19 Dashboarden]] § 127,
 * app/Http/Middleware/HandleInertiaRequests,
 * app/Http/Controllers/NotificationInboxController,
 * resources/js/components/NotificationBell.vue och
 * resources/js/components/notificationPresentation.js.
 *
 * **Sedan issue 647 prövas också Clear**, panelens rensning: att den är en
 * tidsstämpel och ingen radering, att listan blir tom medan siffran nollas,
 * och att inbjudningarna står kvar. Proven ligger sist i filen.
 *
 * **Klockan är ingen kanal** ([[ADR-0010 Notisarkitektur]] § Beslut). Filen
 * prövar att den LÄSER `notification` som tabellen redan är: de tjugo senaste
 * med användarens `user_id`, siffran räknad mot `user.notifications_read_at`,
 * och ingenting skrivet någon annanstans — ingen `notification_delivery`-rad,
 * ingen preferens, ingen tysta-timmar-beräkning.
 *
 * **Två props med två kostnader.** Siffran (`unreadNotificationCount`) delas
 * med varje sida och kostar EN fråga; listan (`notificationList`, hette
 * `notifications` före M60 · issue 383) är en optional
 * prop som en vanlig sidladdning aldrig rör. Båda prövas: den ena genom att
 * räkna frågor mot `notification`, den andra genom att en vanlig GET inte har
 * nyckeln alls.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att panelen
 * fälls ut, att siffran ritas som en bricka och att öppningen gör sin
 * partiella omladdning. Det är klientens väg genom samma två props, och
 * handprovet står i PR-kroppen.
 *
 * Hjälparna har prefixet `klocka` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och grannfilerna har `notis` (Notis-*),
 * `notisRad` och `notisLagen` i NotisinstallningarTest.
 */

/** Ett konto med en ägare, utan container. */
function klockaAnvandare(): User
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return $anvandare;
}

/**
 * Ett konto med en ägare, en container och ett item — för raderna som har en
 * sida att länka till.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function klockaKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En notisrad skriven DIREKT i tabellen, med en tid vi väljer.
 *
 * Fabriken och inte App\Actions\Notification\CreateNotification: proven
 * handlar om LÄSNINGEN, och ordningen "nyast först" går inte att bevisa utan
 * ett `created_at` vi väljer. Att den riktiga vägen in inte skapar en
 * leveransrad när klockan öppnas prövas för sig, nedanför.
 *
 * @param  array<string, mixed>  $payload
 */
function klockaRad(
    User $mottagare,
    string $typ = Notification::TYPE_TASK_DUE,
    ?Carbon $skapad = null,
    array $payload = [],
): Notification {
    return Notification::factory()->create([
        'user_id' => $mottagare->id,
        'type' => $typ,
        'payload' => $payload,
        'created_at' => $skapad ?? now(),
    ]);
}

/**
 * Klockans lista, hämtad som klienten hämtar den: en partiell omladdning av
 * den optionala proppen.
 *
 * Versionsheadern är den samma middleware skulle svara med — ett anrop med
 * fel version är 409, och det är inte det här provet handlar om.
 *
 * @return array<string, mixed>
 */
function klockaListan(User $anvandare, string $url = '/dashboard'): array
{
    $svar = actingAs($anvandare)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'notificationList',
    ]);

    $svar->assertOk();

    return $svar->json('props');
}

/**
 * Klockans BÅDA listor, hämtade som klienten hämtar dem vid en rensning:
 * notiserna och inbjudningarna i samma partiella omladdning
 * (resources/js/components/NotificationBell.vue).
 *
 * `klockaListan` ovan frågar bara efter `notificationList` — det är vad
 * läsningen behöver. Clear rör notiserna medan inbjudningarna ska stå kvar,
 * och då måste båda läsas i samma svar för att jämförelsen ska betyda något.
 *
 * @return array<string, mixed>
 */
function klockaPanelen(User $anvandare, string $url = '/dashboard'): array
{
    $svar = actingAs($anvandare)->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'notificationList,pendingInvitations',
    ]);

    $svar->assertOk();

    return $svar->json('props');
}

/** Antalet frågor mot `notification` under en vanlig sidladdning. */
function klockaNotisfragor(User $anvandare): int
{
    $antal = 0;

    DB::listen(function ($query) use (&$antal): void {
        // SQLite citerar tabellnamn med ", MySQL-grammatiken med ` — båda formerna ska träffa.
        if (preg_match('/from ["`]notification["`]/', $query->sql) === 1) {
            $antal++;
        }
    });

    actingAs($anvandare)->get('/dashboard')->assertOk();

    return $antal;
}

it('listar användarens tjugo senaste notiser, nyast först', function () {
    Carbon::setTestNow(now());

    $anvandare = klockaAnvandare();

    // Tjugofem rader, en per minut bakåt: den äldsta ligger utanför fönstret.
    foreach (range(1, 25) as $minuter) {
        klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinutes(25 - $minuter));
    }

    $rader = klockaListan($anvandare)['notificationList'];

    expect($rader)->toHaveCount(20);

    $skapade = array_column($rader, 'created_at');
    $sorterade = $skapade;
    rsort($sorterade);

    expect($skapade)->toBe($sorterade);

    // De tjugo NYASTE och inte de tjugo första: den nittonde minuten bakåt är
    // den äldsta som får plats, och de fem äldsta raderna är borta.
    expect($skapade[0])->toBe(now()->toIso8601String());
    expect($skapade[19])->toBe(now()->subMinutes(19)->toIso8601String());
    expect($skapade)->not->toContain(now()->subMinutes(24)->toIso8601String());
});

it('visar ingen annan användares notis', function () {
    $anvandare = klockaAnvandare();
    $annan = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now(), ['title' => 'Min uppgift']);
    klockaRad($annan, Notification::TYPE_TASK_DUE, now(), ['title' => 'Någon annans']);

    $rader = klockaListan($anvandare)['notificationList'];

    expect($rader)->toHaveCount(1);
    expect($rader[0]['payload']['title'])->toBe('Min uppgift');
});

it('svarar med en tom lista när användaren inte har några notiser', function () {
    $anvandare = klockaAnvandare();

    expect(klockaListan($anvandare)['notificationList'])->toBe([]);
});

/*
 * Siffran: rader skapade efter `notifications_read_at`, och alla när kolumnen
 * är NULL ([[M19 Dashboarden]] § 127).
 */
it('räknar alla rader när notifications_read_at är NULL', function () {
    withoutVite();

    $anvandare = klockaAnvandare();

    foreach (range(1, 3) as $i) {
        klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subDays($i));
    }

    expect($anvandare->notifications_read_at)->toBeNull();

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 3)
    );
});

it('räknar bara rader skapade efter notifications_read_at', function () {
    withoutVite();

    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subDays(3));
    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subDay());

    $anvandare->notifications_read_at = now()->subDays(2);
    $anvandare->save();

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 1)
    );
});

/*
 * Att öppna klockan sätter tidsstämpeln till NU — och det är därför
 * jämförelsen är strikt: en rad skapad i samma sekund som öppningen hör till
 * det lästa, annars hade siffran stått kvar på samma tal efter att
 * användaren rensat den.
 */
it('nollställer siffran när klockan öppnas', function () {
    withoutVite();

    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now());
    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now());

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 2)
    );

    actingAs($anvandare)->post('/notifications/read')->assertRedirect();

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 0)
    );
});

/*
 * Listan är en optional prop: en vanlig sidladdning rör den aldrig.
 */
it('hämtar inte listan på en vanlig sidladdning', function () {
    withoutVite();

    $anvandare = klockaAnvandare();
    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now());

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->missing('notificationList')
            ->where('unreadNotificationCount', 1)
    );
});

/*
 * Klockan är ingen kanal: att öppna den skapar ingen leveransrad, och den rör
 * inga preferenser. Raden nedan kommer ur den RIKTIGA vägen in
 * (App\Actions\Notification\CreateNotification), som skapar leveransrader —
 * en fabriksrad hade inte haft någon att låta bli att lägga till.
 */
it('skapar ingen leveransrad när klockan öppnas', function () {
    withoutVite();

    $anvandare = klockaAnvandare();
    $konto = $anvandare->accounts()->first();

    app(CreateNotification::class)->handle(
        type: Notification::TYPE_QUOTA_WARNING,
        account: $konto,
        user: $anvandare,
        subject: $konto,
        payload: ['percent' => 80, 'used_bytes' => 100, 'limit_bytes' => 200],
    );

    $före = NotificationDelivery::query()->count();

    expect($före)->toBeGreaterThan(0);

    actingAs($anvandare)->post('/notifications/read')->assertRedirect();
    klockaListan($anvandare);

    expect(NotificationDelivery::query()->count())->toBe($före);
});

/*
 * Siffrorna kostar ett FAST antal frågor per sidladdning, oavsett hur många
 * rader användaren än har — indexet `(user_id, created_at)` finns för dem
 * ([[Notiser]] § notification). Sedan M60 · issue 383 är de två: klockans
 * gamla `unreadNotificationCount` och notislistans `notifications.unread`.
 */
it('kostar ett fast antal frågor per sidladdning, oavsett antalet rader', function () {
    // Frys tiden: UpdateLastActiveAt skriver `user.last_active_at`, och en
    // sekundgräns mellan mätningarna hade blivit en UPDATE extra (issue 477).
    Carbon::setTestNow(now());

    $anvandare = klockaAnvandare();

    expect(klockaNotisfragor($anvandare))->toBe(2);

    foreach (range(1, 5) as $i) {
        klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinutes($i));
    }

    expect(klockaNotisfragor($anvandare))->toBe(2);

    Carbon::setTestNow();
});

/*
 * Migreringen är ADDITIV (AGENTS.md § Databaskonventioner): en nullbar
 * tidsstämpel på en befintlig tabell, utan att en enda rad skrivs om. Att den
 * är nullbar är hela dess betydelse — NULL är "klockan har aldrig öppnats" och
 * räknar alla rader, så en användare som fanns före kolumnen får sin historik
 * oläst i stället för läst. Att den går på MariaDB prövas av CI-jobbet
 * `Migreringar`, som kör `up()` mot mariadb:10.6.
 */
it('har kolumnen notifications_read_at på user, nullbar', function () {
    expect(Schema::hasColumn('user', 'notifications_read_at'))->toBeTrue();

    $kolumn = collect(Schema::getColumns('user'))->firstWhere('name', 'notifications_read_at');

    expect($kolumn['nullable'])->toBeTrue();
});

/*
 * Länkarna: en uppgift och ett lån till sitt item, ett ägarbyte till
 * `/transfers` — och ingenting för de två typer som gäller kontot, för
 * kontosidan finns inte i M19 (issue 127 § Beslut).
 */
it('länkar en uppgift och ett lån till sitt item, och ett ägarbyte till /transfers', function () {
    [$konto, $anvandare, $container, $item] = klockaKontext();

    $schema = Schedule::factory()->for($item, 'item')->create(['title' => 'Byt impeller']);
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['due_at' => '2027-05-05']);
    $lån = Loan::factory()->for($item, 'item')->create(['borrower_name' => 'Erik']);

    $itemUrl = "/containers/{$container->ulid}/items/{$item->ulid}";

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinutes(3), [
        'title' => 'Byt impeller', 'item' => 'Motorn', 'container' => $container->name, 'date' => '2027-05-05',
    ])->forceFill([
        'account_id' => $konto->id,
        'container_id' => $container->id,
        'subject_type' => $förekomst->getMorphClass(),
        'subject_id' => $förekomst->id,
    ])->save();

    klockaRad($anvandare, Notification::TYPE_LOAN_DUE, now()->subMinutes(2), [
        'item' => 'Motorn', 'container' => $container->name, 'borrower' => 'Erik', 'date' => '2027-05-05',
    ])->forceFill([
        'account_id' => $konto->id,
        'container_id' => $container->id,
        'subject_type' => $lån->getMorphClass(),
        'subject_id' => $lån->id,
    ])->save();

    klockaRad($anvandare, Notification::TYPE_TRANSFER_REQUESTED, now()->subMinute(), [
        'container' => $container->name,
    ]);

    $rader = klockaListan($anvandare)['notificationList'];

    expect($rader[0]['type'])->toBe(Notification::TYPE_TRANSFER_REQUESTED);
    expect($rader[0]['url'])->toBe('/transfers');

    expect($rader[1]['type'])->toBe(Notification::TYPE_LOAN_DUE);
    expect($rader[1]['url'])->toBe($itemUrl);

    expect($rader[2]['type'])->toBe(Notification::TYPE_TASK_DUE);
    expect($rader[2]['url'])->toBe($itemUrl);
});

/*
 * Uppföljningen av issue 409: åtkomsten är kvar och gäller igen när ägaren
 * uppgraderar, så raden för `agent.access_lost` leder till sidan där kontot
 * ser sina anslutna agenter ([[ADR-0087 MCP-server för agenter]] Riktning 11
 * och 12).
 */
it('länkar en förlorad agentåtkomst till /settings/agents', function () {
    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_AGENT_ACCESS_LOST, now(), [
        'owner' => 'Anna', 'agents' => 1,
    ]);

    $rader = klockaListan($anvandare)['notificationList'];

    expect($rader[0]['type'])->toBe(Notification::TYPE_AGENT_ACCESS_LOST)
        ->and($rader[0]['url'])->toBe('/settings/agents');
});

it('länkar inte en kvotvarning eller en inaktivitetsvarning', function () {
    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_QUOTA_WARNING, now()->subMinute(), [
        'percent' => 80, 'used_bytes' => 100, 'limit_bytes' => 200,
    ]);

    klockaRad($anvandare, Notification::TYPE_ACCOUNT_INACTIVE, now(), [
        'months' => 12, 'close_at' => '2027-01-01',
    ]);

    foreach (klockaListan($anvandare)['notificationList'] as $rad) {
        expect($rad['url'])->toBeNull();
    }
});

/*
 * Ett mjukraderat item faller bort genom SoftDeletes' globala scope, och
 * raden blir en text i stället för en länk till en 404 — samma svar som
 * händelseloggen ger en gallrad rad.
 */
it('gör ingen länk av en rad vars mål inte längre finns', function () {
    [$konto, $anvandare, $container, $item] = klockaKontext();

    $schema = Schedule::factory()->for($item, 'item')->create();
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now())->forceFill([
        'account_id' => $konto->id,
        'container_id' => $container->id,
        'subject_type' => $förekomst->getMorphClass(),
        'subject_id' => $förekomst->id,
    ])->save();

    $item->delete();

    expect(klockaListan($anvandare)['notificationList'][0]['url'])->toBeNull();
});

/*
 * Mellansteget kan falla bort medan målet lever kvar: `Schedule` har
 * SoftDeletes och förekomsterna stängs inte när schemat raderas — de följer
 * med genom relationen. `$occurrence->schedule` blir då `null` (globala
 * scopen) medan både förekomsten, itemet och containern finns kvar. Raden ska
 * bli en text, inte en krasch.
 */
it('gör ingen länk när schemat är mjukraderat men förekomsten lever kvar', function () {
    [$konto, $anvandare, $container, $item] = klockaKontext();

    $schema = Schedule::factory()->for($item, 'item')->create();
    $förekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now())->forceFill([
        'account_id' => $konto->id,
        'container_id' => $container->id,
        'subject_type' => $förekomst->getMorphClass(),
        'subject_id' => $förekomst->id,
    ])->save();

    $schema->delete();

    expect($förekomst->fresh()->schedule)->toBeNull()
        ->and(klockaListan($anvandare)['notificationList'][0]['url'])->toBeNull();
});

/*
 * Issue 647 · Notisklockan utan rubrik och med Clear, fynd från testarna
 * 2026-10-03 (docs/Design/findings_20261003_a/notis.png). Panelen tömmer sin
 * lista med en diskret Clear-knapp, och rensningen är en TIDSSTÄMPEL och
 * ingen radering: `notification` är outboxen ([[ADR-0010 Notisarkitektur]]
 * § Beslut) och raderna står kvar.
 *
 * Proven nedan prövar kolumnen, skrivningen och de två följderna — listan
 * blir tom, siffran noll — och att inbjudningarna, som inte är notiser, står
 * kvar. Formen på skrivningen är `store()`s: användaren ur sessionen, ingen
 * kropp, svaret `back()`.
 */

it('har kolumnen notifications_cleared_at på user, nullbar', function () {
    expect(Schema::hasColumn('user', 'notifications_cleared_at'))->toBeTrue();

    $kolumn = collect(Schema::getColumns('user'))->firstWhere('name', 'notifications_cleared_at');

    expect($kolumn['nullable'])->toBeTrue();
});

it('tömmer panelens lista vid Clear utan att radera någon notisrad', function () {
    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinute());
    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now());

    expect(klockaListan($anvandare)['notificationList'])->toHaveCount(2);

    $fore = Notification::query()->count();

    actingAs($anvandare)->post('/notifications/clear')->assertRedirect();

    expect(klockaListan($anvandare)['notificationList'])->toBe([])
        ->and(Notification::query()->count())->toBe($fore);
});

it('nollställer siffran vid Clear', function () {
    withoutVite();

    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinute());
    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now());

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 2)
    );

    actingAs($anvandare)->post('/notifications/clear')->assertRedirect();

    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 0)
    );
});

/*
 * Gränsen är strikt och tiden är fryst: en notis skapad EFTER rensningen ska
 * synas, och en skapad i samma sekund som rensningen hör till det rensade —
 * samma regel som siffran (HandleInertiaRequests::unreadNotificationCount()).
 */
it('visar en notis som skapats efter rensningen', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00'));

    $anvandare = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinute());

    actingAs($anvandare)->post('/notifications/clear')->assertRedirect();

    expect(klockaListan($anvandare)['notificationList'])->toBe([]);

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->addMinute());

    $rader = klockaListan($anvandare)['notificationList'];

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['created_at'])->toBe(now()->addMinute()->toIso8601String());

    Carbon::setTestNow();
});

it('låter inbjudningarna stå kvar efter rensningen', function () {
    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'klocka@exempel.se']);
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'klocka@exempel.se',
        'invited_by_user_id' => $inbjudare->id,
    ]);

    klockaRad($mottagare, Notification::TYPE_TASK_DUE, now()->subMinute());

    $fore = klockaPanelen($mottagare);

    expect($fore['notificationList'])->toHaveCount(1)
        ->and($fore['pendingInvitations'])->toHaveCount(1);

    actingAs($mottagare)->post('/notifications/clear')->assertRedirect();

    $efter = klockaPanelen($mottagare);

    expect($efter['notificationList'])->toBe([])
        ->and($efter['pendingInvitations'])->toBe($fore['pendingInvitations']);
});

it('rensar bara den egna listan', function () {
    $anvandare = klockaAnvandare();
    $annan = klockaAnvandare();

    klockaRad($anvandare, Notification::TYPE_TASK_DUE, now()->subMinute());
    klockaRad($annan, Notification::TYPE_TASK_DUE, now()->subMinute());

    actingAs($anvandare)->post('/notifications/clear')->assertRedirect();

    expect(klockaListan($anvandare)['notificationList'])->toBe([])
        ->and(klockaListan($annan)['notificationList'])->toHaveCount(1);
});

it('skickar en utloggad besökare från /notifications/clear till inloggningen', function () {
    post('/notifications/clear')->assertRedirect('/login');
});

/*
 * Källkodsprovet: den gamla panelen är borta ur klockan sedan M60 · issue
 * 385, som ersätter den med notislistan i appen
 * (resources/js/components/NotificationBell.vue och
 * tests/Feature/Frontend/NotislistavyTest.php). Kvar sedan issue 647 är
 * serverns beteende — kolumnen, skrivningen och de två följderna — som
 * prövas ovanför; rutten `/notifications/clear` och strängen `inbox.clear`
 * står kvar tills App\Http\Controllers\NotificationInboxController tas bort.
 */
it('ritar inte den gamla panelen i klockan', function () {
    $kod = File::get(resource_path('js/components/NotificationBell.vue'));

    expect($kod)->not->toContain('/notifications/clear')
        ->and($kod)->not->toContain("t('inbox.clear')")
        ->and($kod)->not->toContain('<h2');
});
