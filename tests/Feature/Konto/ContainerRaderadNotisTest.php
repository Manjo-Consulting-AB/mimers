<?php

use App\Actions\Account\DeleteAccount;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Notification as NotificationModel;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Notification\EmailChannel;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/*
 * Issue 1235 · `container.deleted` — varje person som förlorar åtkomst till
 * en container som raderas med sitt konto ska få veta det. Se
 * [[ADR-0045 Radering av konto och person]] § Uppföljning 2026-11-14 och
 * [[Notiser]] § notification.
 *
 * De två raderingsvägarna går båda genom App\Actions\Account\DeleteAccount,
 * som får skälet som argument: `owner_deleted` från App\Actions\User\
 * DeleteUser och `account_inactive` från App\Console\DeletesDormantAccounts.
 * Spärrarna som i dag hindrar livscykeln från att radera ett konto med
 * aktiva medlemmar tas bort i två egna issues; här prövas actionen direkt,
 * precis som KontoraderingTest och PersonraderingTest gör.
 *
 * Hjälparna har prefixet containerRaderad för att inte krocka med de
 * globala hjälparna i tests/Support/Testhjalpare.php eller med
 * kontoradering- och personradering-hjälparna i grannfilerna — Pests
 * funktioner är globala och hela sviten körs i en process.
 */

/**
 * Kontot som raderas, med en medlem som ägare.
 *
 * @return array{0: Account, 1: User}
 */
function containerRaderadKonto(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare];
}

/**
 * En mottagare med ett eget personkonto. Personkontot behövs för att
 * notisraden ska ha ett `account_id` som är mottagarens och inte det
 * raderade kontot — uppslaget är App\Support\Account\ActingAccount:s.
 *
 * @return array{0: User, 1: Account}
 */
function containerRaderadMottagare(): array
{
    $person = User::factory()->create();
    $personkonto = Account::factory()->create(['type' => 'personal']);
    $personkonto->users()->attach($person, ['role' => 'owner']);

    return [$person, $personkonto];
}

/**
 * @return Collection<int, NotificationModel>
 */
function containerRaderadNotiser(): Collection
{
    return NotificationModel::query()
        ->where('type', NotificationModel::TYPE_CONTAINER_DELETED)
        ->orderBy('id')
        ->get();
}

it('en medlem med containeråtkomst och en med itemåtkomst får var sin notis med rätt namn och skäl', function () {
    [$konto, $ägare] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);
    $item = Item::factory()->for($container, 'container')->create();

    // Container-bred åtkomst.
    [$containerMottagare, $containerKonto] = containerRaderadMottagare();
    beviljaAccess($container, $containerMottagare, 'read', 'member');

    // Åtkomst på itemnivå. Kolumnen ligger utanför #[Fillable], så raden
    // skrivs med fabriken — samma form som issue 70–75:s tester.
    [$itemMottagare, $itemKonto] = containerRaderadMottagare();
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $itemMottagare->id,
        'level' => 'read',
        'kind' => 'member',
        'granted_by_user_id' => $ägare->id,
    ]);

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    $notiser = containerRaderadNotiser();

    expect($notiser)->toHaveCount(2);

    $perMottagare = $notiser->keyBy('user_id');

    expect($perMottagare[$containerMottagare->id]->payload)->toBe([
        'container' => 'Vindil',
        'reason' => DeleteAccount::REASON_OWNER_DELETED,
    ]);
    expect($perMottagare[$itemMottagare->id]->payload)->toBe([
        'container' => 'Vindil',
        'reason' => DeleteAccount::REASON_OWNER_DELETED,
    ]);

    // Mottagarens eget personkonto, inte det som raderas.
    expect($perMottagare[$containerMottagare->id]->account_id)->toBe($containerKonto->id);
    expect($perMottagare[$itemMottagare->id]->account_id)->toBe($itemKonto->id);
});

it('en medlem i två containrar får två notiser', function () {
    [$konto] = containerRaderadKonto();
    $första = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);
    $andra = Container::factory()->for($konto, 'account')->create(['name' => 'Motorn']);

    [$mottagare] = containerRaderadMottagare();
    beviljaAccess($första, $mottagare, 'read', 'member');
    beviljaAccess($andra, $mottagare, 'read', 'member');

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    $notiser = containerRaderadNotiser()->where('user_id', $mottagare->id)->values();

    expect($notiser)->toHaveCount(2);
    expect($notiser->pluck('payload')->pluck('container')->sort()->values()->all())
        ->toBe(['Motorn', 'Vindil']);
});

it('en container i papperskorgen räknas också', function () {
    [$konto] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);

    [$mottagare] = containerRaderadMottagare();
    beviljaAccess($container, $mottagare, 'read', 'member');

    // En mjukradering sätter bara `deleted_at` på container-raden och
    // återkallar inte åtkomsten. Containern rensas ändå av DeleteAccount
    // (withTrashed), så mottagaren förlorar åtkomsten och ska få notisen.
    $container->delete();

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    expect(containerRaderadNotiser()->where('user_id', $mottagare->id))->toHaveCount(1);
});

it('en grant till ett konto ger notis till kontots personer', function () {
    [$konto] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);

    [$första, $förstaKonto] = containerRaderadMottagare();
    [$andra, $andraKonto] = containerRaderadMottagare();

    $gästkonto = Account::factory()->organisation()->create();
    $gästkonto->users()->attach($första, ['role' => 'member']);
    $gästkonto->users()->attach($andra, ['role' => 'owner']);

    beviljaAccess($container, $gästkonto, 'read', 'managed');

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    $notiser = containerRaderadNotiser()->keyBy('user_id');

    expect($notiser)->toHaveCount(2)
        // Personkontot, inte gästkontot som bär åtkomsten.
        ->and($notiser[$första->id]->account_id)->toBe($förstaKonto->id)
        ->and($notiser[$andra->id]->account_id)->toBe($andraKonto->id);
});

it('en utgången eller återkallad åtkomst ger ingen notis', function () {
    [$konto] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create();

    [$utgången] = containerRaderadMottagare();
    beviljaAccess($container, $utgången, 'read', 'member', expiresAt: now()->subDay());

    [$återkallad] = containerRaderadMottagare();
    beviljaAccess($container, $återkallad, 'read', 'member', revokedAt: now()->subDay());

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    expect(containerRaderadNotiser())->toHaveCount(0);
});

it('den raderade personen och en obesvarad inbjudan får ingen notis', function () {
    [$konto, $ägare] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create();

    // Ägaren har en egen åtkomst till sin egen container. Raden är giltig,
    // men personen vars konto raderas ska aldrig få en notis om att hon
    // förlorar åtkomsten.
    beviljaAccess($container, $ägare, 'read', 'member');

    // En obesvarad inbjudan dras tillbaka som i dag, utan notis.
    bjudInRad($container, 'blivande@exempel.se');

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    expect(containerRaderadNotiser())->toHaveCount(0);
});

it('notisen pekar inte på det raderade kontot eller containern, och raderingen lyckas', function () {
    [$konto] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);

    [$mottagare, $personkonto] = containerRaderadMottagare();
    beviljaAccess($container, $mottagare, 'read', 'member');

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    $notis = containerRaderadNotiser()->sole();

    expect($notis->container_id)->toBeNull()
        ->and($notis->account_id)->toBe($personkonto->id)
        ->and($notis->account_id)->not->toBe($konto->id)
        ->and($notis->user_id)->toBe($mottagare->id)
        ->and($notis->dedupe_key)
        ->toBe(NotificationModel::TYPE_CONTAINER_DELETED.":{$container->ulid}:{$mottagare->ulid}")
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse()
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
});

it('skälet account_inactive skrivs när livscykeln raderar', function () {
    [$konto] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create();

    [$mottagare] = containerRaderadMottagare();
    beviljaAccess($container, $mottagare, 'read', 'member');

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_ACCOUNT_INACTIVE);

    expect(containerRaderadNotiser()->sole()->payload['reason'])
        ->toBe(DeleteAccount::REASON_ACCOUNT_INACTIVE);
});

it('container.deleted finns med förval: på och inget sammandrag', function () {
    $preferences = app(NotificationPreferences::class);

    expect($preferences->types())->toContain(NotificationModel::TYPE_CONTAINER_DELETED)
        ->and($preferences->isEnabled(null, NotificationModel::TYPE_CONTAINER_DELETED, 'email'))->toBeTrue()
        ->and($preferences->digest(null, NotificationModel::TYPE_CONTAINER_DELETED, 'email'))->toBeFalse();
});

it('webhooks får inte typen', function () {
    expect(WebhookEndpoint::EVENT_TYPES)->not->toContain(NotificationModel::TYPE_CONTAINER_DELETED);
});

it('mejlet renderas för båda skälen och saknar länk till containern', function () {
    Mail::fake();

    [$konto, $mottagare] = kontoMedMedlem();

    $fraser = [
        DeleteAccount::REASON_OWNER_DELETED => 'was deleted by its owner',
        DeleteAccount::REASON_ACCOUNT_INACTIVE => 'inactive for 18 months',
    ];

    foreach ($fraser as $reason => $fras) {
        $delivery = epostLeverans(
            $konto,
            $mottagare,
            NotificationModel::TYPE_CONTAINER_DELETED,
            ['container' => 'Vindil', 'reason' => $reason],
        );

        app(EmailChannel::class)->send($delivery);

        $mail = Mail::sent(NotificationMail::class)->last();
        $html = $mail->render();

        expect($html)->toContain('Vindil')
            ->toContain($fras)
            ->toContain('You no longer have access to it, or to anything you added there.');

        expect($html)->not->toContain('/containers/');
        expect(mejletsÄmne($mail))->toContain('Vindil');
    }
});

it('en körning till ger ingen dubblett', function () {
    [$konto] = containerRaderadKonto();
    $container = Container::factory()->for($konto, 'account')->create();

    [$mottagare] = containerRaderadMottagare();
    beviljaAccess($container, $mottagare, 'read', 'member');

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);
    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    expect(containerRaderadNotiser()->where('user_id', $mottagare->id))->toHaveCount(1);
});
