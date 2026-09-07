<?php

use App\Console\GeneratesTaskNotifications;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Issue 34b · Uppgiftsnotiserna — task.due och task.overdue ur samma fråga
 * som todo-listan. Se App\Console\GeneratesTaskNotifications, [[Notiser]] §
 * Kön och App\Models\ScheduleOccurrence::scopeTodoFor().
 *
 * kontoMedMedlem(), beviljaAccess(), oppnaForekomst() och skapaBeroende()
 * är globala testhjälpare i tests/Support/Testhjalpare.php.
 *
 * Klockan fryses för varje test: visible_from- och due_at-villkoren jämför
 * DATUM med dagens datum, så utan en fryst tid beror utfallet på klockslaget
 * när sviten körs (issue 24 § Att se upp med). Varje "Klart när"-punkt i
 * issuen (1–7) motsvarar ett namngivet test här.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En container på ett konto med en ägare.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function uppgiftsnotisBas(string $containerNamn = 'Vindil'): array
{
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => $containerNamn]);

    return [$account, $user, $container];
}

/**
 * Öppnar en förekomst på en container med kända namn. due_at = $due,
 * visible_from = $due minus $leadDagar.
 */
function uppgiftsnotisSkapa(
    Container $container,
    User $user,
    Account $account,
    string $titel,
    string $due,
    int $leadDagar = 0,
    string $itemNamn = 'Motor',
): ScheduleOccurrence {
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $itemNamn,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    [, $occurrence] = oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => $due,
        'lead_days' => $leadDagar,
    ]);

    return $occurrence;
}

it('en synlig öppen förekomst ger en task.due till ägarkontots medlem', function () {
    [$account, $user, $container] = uppgiftsnotisBas();
    $occurrence = uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(1);
    $notis = Notification::query()->firstOrFail();
    expect($notis->type)->toBe(Notification::TYPE_TASK_DUE);
    expect($notis->user_id)->toBe($user->id);
    expect($notis->account_id)->toBe($account->id);
    expect($notis->container_id)->toBe($container->id);
    expect($notis->subject_id)->toBe($occurrence->id);
    expect($notis->dedupe_key)->toBe('task.due:'.$occurrence->ulid.':'.$user->ulid);
});

it('alla roller på ägarkontot får påminnelsen', function () {
    [$account, $ägare, $container] = uppgiftsnotisBas();
    $medlem = User::factory()->create();
    $account->users()->attach($medlem, ['role' => 'member']);
    uppgiftsnotisSkapa($container, $ägare, $account, 'Byt impeller', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(2);
    expect(Notification::query()->pluck('user_id'))->toContain($ägare->id)->toContain($medlem->id);
});

it('en förfallen förekomst ger en task.overdue', function () {
    [$account, $user, $container] = uppgiftsnotisBas();
    $occurrence = uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2026-09-01');

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(1);
    $notis = Notification::query()->firstOrFail();
    expect($notis->type)->toBe(Notification::TYPE_TASK_OVERDUE);
    expect($notis->dedupe_key)->toBe('task.overdue:'.$occurrence->ulid.':'.$user->ulid);
});

it('jobbet kört fyra gånger ger fortfarande en notis per förekomst', function () {
    [$account, $user, $container] = uppgiftsnotisBas();
    uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2026-09-02');

    foreach (range(1, 4) as $ignored) {
        app(GeneratesTaskNotifications::class)->handle();
    }

    expect(Notification::query()->count())->toBe(1);
});

it('en gäst med delegerad åtkomst får ingen uppgiftsnotis', function () {
    [$ägarKonto, $ägare, $container] = uppgiftsnotisBas();
    [$gästKonto, $gäst] = kontoMedMedlem();
    uppgiftsnotisSkapa($container, $ägare, $ägarKonto, 'Byt impeller', '2026-09-02');

    // Gästen ser uppgiften i todo-listan via den delegerade åtkomsten, men
    // påminnelser går bara till ägarkontots medlemmar (Beslut 4).
    beviljaAccess($container, $gäst, 'read', 'guest');

    expect($gäst->accounts->pluck('id'))->toContain($gästKonto->id);

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->where('user_id', $gäst->id)->count())->toBe(0);
    expect(Notification::query()->where('user_id', $ägare->id)->count())->toBe(1);
});

it('en förekomst som blockeras av ett beroende ger ingen notis', function () {
    [$account, $user, $container] = uppgiftsnotisBas();
    $blocker = uppgiftsnotisSkapa($container, $user, $account, 'Serva motorn', '2026-09-02');
    $blockerad = uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2026-09-02');
    skapaBeroende($blockerad, $blocker);

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(1);
    expect(Notification::query()->where('subject_id', $blockerad->id)->exists())->toBeFalse();
    expect(Notification::query()->where('subject_id', $blocker->id)->exists())->toBeTrue();
});

it('en förekomst som ännu inte är synlig ger ingen notis', function () {
    [$account, $user, $container] = uppgiftsnotisBas();

    // due 2027-05-05, lead 14 → visible_from 2027-04-21: fortfarande i
    // framtiden. Inte ens en påminnelse om att den snart syns.
    uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2027-05-05', 14);

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(0);
});

it('payloaden bär titel, item, container och datum — inga färdiga meningar', function () {
    [$account, $user, $container] = uppgiftsnotisBas('Vindil');
    uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2026-09-02', 0, 'Drev');

    app(GeneratesTaskNotifications::class)->handle();

    $notis = Notification::query()->firstOrFail();
    expect($notis->payload)->toBe([
        'title' => 'Byt impeller',
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-02',
    ]);
});

it('samma förekomst får först en task.due och sedan en task.overdue', function () {
    [$account, $user, $container] = uppgiftsnotisBas();
    uppgiftsnotisSkapa($container, $user, $account, 'Byt impeller', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();
    expect(Notification::query()->count())->toBe(1);
    expect(Notification::query()->where('type', Notification::TYPE_TASK_DUE)->count())->toBe(1);

    // Dagen efter har datumet passerats: samma förekomst får en overdue med
    // en egen dedupe-nyckel — två notiser över tid, aldrig två samtidigt.
    Carbon::setTestNow('2026-09-03 10:00:00');
    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(2);
    expect(Notification::query()->where('type', Notification::TYPE_TASK_DUE)->count())->toBe(1);
    expect(Notification::query()->where('type', Notification::TYPE_TASK_OVERDUE)->count())->toBe(1);
});
