<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Notification\CreateNotification;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
 * Issue 30 · Notisens utboksrad: två tabeller, två modeller och den enda
 * vägen in — App\Actions\Notification\CreateNotification. Se
 * App\Models\Notification, App\Models\NotificationDelivery,
 * [[Notiser]] § notification och § notification_delivery samt
 * [[ADR-0010 Notisarkitektur]] § Beslut.
 *
 * Ingenting skickas och ingen kanal finns i den här issuen: testerna går
 * direkt mot modeller och action, ingen HTTP, precis som ForekomstTest.
 * kontoMedMedlem() (tests/Feature/Container/ContainerCrudTest.php) är redan
 * deklarerad och återanvänds rakt av genom Pests globala namnrymd.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function notisKontext(): array
{
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $container];
}

it('en notis skapas med ulid, konto och payload', function () {
    [$account, $user] = notisKontext();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        payload: ['due_at' => '2027-05-05'],
        dedupeKey: 'skapa',
    );

    // Läses om från databasen: payload-castet ska göra JSON-kolumnen till en
    // array även när sqlite lagrat den som TEXT (issue 30 § Att se upp med).
    $lagrad = Notification::query()->findOrFail($notis->id);

    expect($lagrad->ulid)->toBeString();
    expect(strlen($lagrad->ulid))->toBe(26);
    expect($lagrad->account_id)->toBe($account->id);
    expect($lagrad->payload)->toBeArray();
    expect($lagrad->type)->toBe(Notification::TYPE_TASK_DUE);
});

it('samma logiska händelse skapad sextio gånger ger en enda leverans', function () {
    [$account, $user] = notisKontext();

    $första = null;

    for ($i = 0; $i < 60; $i++) {
        $notis = app(CreateNotification::class)->handle(
            type: Notification::TYPE_TASK_DUE,
            account: $account,
            user: $user,
            payload: ['occurrence_id' => 42, 'due_at' => '2027-05-05'],
            dedupeKey: 'occurrence:42:2027-05-05',
        );
        $första ??= $notis;
    }

    expect(Notification::query()->count())->toBe(1);
    expect(NotificationDelivery::query()->where('notification_id', $första->id)->count())->toBe(1);
});

it('en andra notis med samma dedupe_key returnerar den befintliga raden', function () {
    [$account, $user] = notisKontext();

    $första = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        payload: [],
        dedupeKey: 'samma-händelse',
    );
    $andra = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        payload: [],
        dedupeKey: 'samma-händelse',
    );

    expect($andra->id)->toBe($första->id);
    expect(Notification::query()->count())->toBe(1);
    expect(NotificationDelivery::query()->count())->toBe(1);
});

it('notiser utan dedupe_key dedupliceras inte', function () {
    [$account, $user] = notisKontext();

    $första = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );
    $andra = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    expect($andra->id)->not->toBe($första->id);
    expect(Notification::query()->count())->toBe(2);
});

it('samma notis kan aldrig få två leveransrader på samma kanal', function () {
    [$account, $user] = notisKontext();
    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    // CreateNotification har redan skapat en e-postleverans; en andra rad på
    // samma kanal ska stängas av unik (notification_id, channel) — den andra
    // spärren som gäller även när dedupe_key är null (Beslut 7).
    $duplikat = new NotificationDelivery;
    $duplikat->notification_id = $notis->id;
    $duplikat->channel = NotificationDelivery::CHANNEL_EMAIL;
    $duplikat->status = NotificationDelivery::STATUS_PENDING;
    $duplikat->attempts = 0;

    expect(fn () => $duplikat->save())->toThrow(UniqueConstraintViolationException::class);
});

it('en ny notis får en väntande e-postleverans', function () {
    [$account, $user] = notisKontext();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    $leverans = NotificationDelivery::query()->where('notification_id', $notis->id)->first();

    expect($leverans)->not->toBeNull();
    expect($leverans->channel)->toBe(NotificationDelivery::CHANNEL_EMAIL);
    expect($leverans->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($leverans->attempts)->toBe(0);
    expect($leverans->sent_at)->toBeNull();
});

it('available_at sätts vid skapandet', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    [$account, $user] = notisKontext();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
    );

    // Tysta timmar räknas inte här — available_at sätts till now() och
    // tidszonsräkningen är 31a (Beslut 6).
    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->available_at)->not->toBeNull();
    expect($lagrad->available_at->toDateTimeString())->toBe('2026-09-04 12:00:00');
});

it('notisen bär ingen text i payload', function () {
    [$account, $user] = notisKontext();
    $payload = ['due_at' => '2027-05-05', 'occurrence_id' => 42];

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        payload: $payload,
    );

    // Actionen lagrar arrayen den fick, oförändrad — rendering till text sker
    // per kanal och språk vid leverans (Beslut 5), aldrig här.
    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->payload)->toBe($payload);
});

it('en notis kan sakna mottagande användare', function () {
    [$account] = notisKontext();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_ACCOUNT_INACTIVE,
        account: $account,
        payload: ['months' => 18],
        dedupeKey: 'konto-inaktivt',
    );

    expect($notis->user_id)->toBeNull();

    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->user_id)->toBeNull();
});

it('en notis kan peka ut ett subject', function () {
    [$account, $user, $container] = notisKontext();
    $subject = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        container: $container,
        subject: $subject,
        payload: ['due_at' => '2027-05-05'],
    );

    $lagrad = Notification::query()->findOrFail($notis->id);
    expect($lagrad->subject_type)->toBe($subject->getMorphClass());
    expect($lagrad->subject_id)->toBe($subject->id);
    expect($lagrad->subject)->toBeInstanceOf(Item::class);
    expect($lagrad->subject->is($subject))->toBeTrue();
});

it('en raderad container tar med sig sina notiser', function () {
    [$account, $user, $container] = notisKontext();

    $containerNotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        container: $container,
        payload: ['due_at' => '2027-05-05'],
        dedupeKey: 'container-notis',
    );

    // Kontonivå-notisen utan container är en kontroll: PurgeContainer rör
    // bara rader som pekar på containern (Beslut 8).
    $kontoNotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_ACCOUNT_INACTIVE,
        account: $account,
        payload: ['months' => 18],
        dedupeKey: 'konto-notis',
    );

    // Papperskorgen: gallringen tar mjukraderade containers.
    $container->delete();

    $purgeContent = new PurgeContent(new PurgeAttachment);
    $purge = new PurgeContainer($purgeContent);

    // Utan städningen skulle forceDelete kasta ett integritetsfel på
    // notification.container_id (ON DELETE RESTRICT).
    expect(fn () => $purge->handle($container))->not->toThrow(Throwable::class);

    expect(DB::table('notification')->where('id', $containerNotis->id)->exists())->toBeFalse();
    expect(DB::table('notification_delivery')->where('notification_id', $containerNotis->id)->exists())->toBeFalse();
    expect(DB::table('notification')->where('id', $kontoNotis->id)->exists())->toBeTrue();
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
});

it('ett raderat konto tar med sig sina notiser', function () {
    [$account, $user, $container] = notisKontext();

    $containerNotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $account,
        user: $user,
        container: $container,
        payload: ['due_at' => '2027-05-05'],
        dedupeKey: 'container-notis',
    );
    $kontoNotis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_ACCOUNT_INACTIVE,
        account: $account,
        payload: ['months' => 18],
        dedupeKey: 'konto-notis',
    );

    $purgeContent = new PurgeContent(new PurgeAttachment);
    $deleteAccount = new DeleteAccount(new PurgeContainer($purgeContent));

    // Container-notiserna tas av PurgeContainer inuti DeleteAccount;
    // konto-notisen av raderingen efter containrarna (Beslut 8).
    expect(fn () => $deleteAccount->handle($account))->not->toThrow(Throwable::class);

    expect(DB::table('notification')->where('account_id', $account->id)->exists())->toBeFalse();
    expect(DB::table('notification_delivery')->where('notification_id', $containerNotis->id)->exists())->toBeFalse();
    expect(DB::table('notification_delivery')->where('notification_id', $kontoNotis->id)->exists())->toBeFalse();
    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse();
});
