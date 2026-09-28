<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Invitation\RevokeInvitation;
use App\Actions\OwnershipTransfer\RevokeOwnershipTransfer;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Actions\User\DeleteUser;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Export;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\Notification as NotificationModel;
use App\Models\NotificationDelivery;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\SecurityLog;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\User\DeletionBlocker;
use App\Support\User\UserDeletionBlocked;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Issue 144 · En person kan raderas. Se App\Actions\User\DeleteUser,
 * App\Support\User\DeletionBlocker, [[ADR-0045 Radering av konto och
 * person]] § Beslut 3, [[ADR-0043 Tre loggar]] § Säkerhetsloggen och
 * [[Konton och åtkomst]] § user.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Ytan kommer i issue 145 — det här testet anropar actionen direkt, precis
 * som KontoraderingTest anropar DeletesDormantAccounts direkt. Hjälparna
 * heter personradering* för att inte krocka med de globala i
 * tests/Support/Testhjalpare.php eller med kontoradering* i
 * KontoraderingTest — Pests funktioner är globala och hela sviten körs i en
 * process.
 *
 * Actionen hämtas ur behållaren (`app(DeleteUser::class)`) och byggs inte för
 * hand: beroendekedjan är fyra led djup (DeleteAccount → PurgeContainer →
 * PurgeContent → PurgeAttachment) och att skriva av den i varje test vore
 * brus. Det enda test som behöver en trasig DeleteAccount bygger sin egen.
 */

/**
 * Ett konto med personen som enda medlem — kontot som raderas.
 */
function personraderingEgetKonto(User $person, string $roll = 'owner'): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => $roll]);

    return $konto;
}

/**
 * Ett konto med personen och en annan medlem — kontot som lämnas.
 * Den andra medlemmens roll styr om personen är ensam ägare.
 *
 * @return array{0: Account, 1: User} [$konto, $annanMedlem]
 */
function personraderingDelatKonto(User $person, string $personsRoll = 'member', string $annansRoll = 'owner'): array
{
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => $personsRoll]);

    $annan = User::factory()->create();
    $konto->users()->attach($annan, ['role' => $annansRoll]);

    return [$konto, $annan];
}

function personraderingContainer(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * En rad i var och en av de tabeller som bara är personens, så att ett test
 * kan påstå att alla är borta. Returnerar antalet rader som lades in, så att
 * påståendet inte kan passera på en tom tabell.
 */
function personraderingPersonensRader(User $person, Container $container): int
{
    $item = Item::factory()->for($container, 'container')->create();

    DB::table('favorite')->insert([
        'user_id' => $person->id,
        'item_id' => $item->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Besöksraden (issue 160 · [[ADR-0049 Nyligen besökta]] § Beslut): raden
    // är personens och bara hennes, och den bär samma RESTRICT-nyckel mot
    // `item` som favoriten gör mot samma rad.
    DB::table('recent_visit')->insert([
        'user_id' => $person->id,
        'item_id' => $item->id,
        'visited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('dismissed_tip')->insert([
        'user_id' => $person->id,
        'tip_key' => 'favorites',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('calendar_feed')->insert([
        'ulid' => (string) Str::ulid(),
        'container_id' => $container->id,
        'user_id' => $person->id,
        'token_hash' => hash('sha256', 'kalenderlank'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notification_preference')->insert([
        'user_id' => $person->id,
        'type' => NotificationModel::TYPE_TASK_DUE,
        'channel' => 'email',
        'enabled' => true,
        'digest' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('totp_recovery_code')->insert([
        'user_id' => $person->id,
        'code_hash' => Hash::make('ABCDE-12345'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('email_change')->insert([
        'user_id' => $person->id,
        'new_email' => 'ny-adress@exempel.se',
        'token_hash' => hash('sha256', 'adressbyte'),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('password_change')->insert([
        'user_id' => $person->id,
        'password_hash' => Hash::make('nytt-losenord'),
        'token_hash' => hash('sha256', 'losenordsbyte'),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('magic_link_token')->insert([
        'email' => $person->email,
        'token_hash' => hash('sha256', 'inloggningslank'),
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $person->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Test',
        'payload' => base64_encode('{}'),
        'last_activity' => now()->getTimestamp(),
    ]);

    $person->createToken('api');

    // En notis med BÅDE en webhook- och en notisleverans: båda pekar på
    // `notification` med RESTRICT, och en person kan ha en notis som en
    // webhook har levererats för. Utan leveransraderna först faller
    // raderingen på en främmandenyckel.
    $notis = NotificationModel::factory()->create([
        'account_id' => $container->account_id,
        'user_id' => $person->id,
    ]);

    $endpoint = WebhookEndpoint::factory()->create(['account_id' => $container->account_id]);

    NotificationDelivery::factory()->create(['notification_id' => $notis->id]);
    WebhookDelivery::factory()->create([
        'notification_id' => $notis->id,
        'webhook_endpoint_id' => $endpoint->id,
    ]);

    // Tretton räknade rader: de tolv ovan plus besöksraden (issue 160).
    return 13;
}

/**
 * Antalet rader personen har kvar i de tabeller som bara är hennes.
 */
function personraderingKvarvarandeRader(User $person): int
{
    return (int) DB::table('favorite')->where('user_id', $person->id)->count()
        + (int) DB::table('recent_visit')->where('user_id', $person->id)->count()
        + (int) DB::table('dismissed_tip')->where('user_id', $person->id)->count()
        + (int) DB::table('calendar_feed')->where('user_id', $person->id)->count()
        + (int) DB::table('notification_preference')->where('user_id', $person->id)->count()
        + (int) DB::table('totp_recovery_code')->where('user_id', $person->id)->count()
        + (int) DB::table('email_change')->where('user_id', $person->id)->count()
        + (int) DB::table('password_change')->where('user_id', $person->id)->count()
        + (int) DB::table('magic_link_token')->where('email', $person->email)->count()
        + (int) DB::table('sessions')->where('user_id', $person->id)->count()
        + (int) DB::table('personal_access_tokens')->where('tokenable_id', $person->id)->count()
        + (int) DB::table('notification')->where('user_id', $person->id)->count()
        + (int) DB::table('account_user')->where('user_id', $person->id)->count();
}

/*
 * Klart när: en person som är enda medlem i sitt konto raderas, och kontot,
 * dess containers och personens rader är borta.
 */
it('en person som är enda medlem i sitt konto raderas med kontot, containern och itemet', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse()
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse()
        ->and(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
});

it('en person som är enda medlem raderas även när kontot startat ett ägarbyte', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);

    // Ett ägarbyte spärrar inte (spärrarna räknar aktiva medlemmar, inte
    // pågående överlåtelser) — men raden bär en RESTRICT-nyckel mot både
    // containern och kontot, och utan städningen hade hela raderingen fallit.
    $ägarbyte = skapaÄgarbyteRad($container, [
        'from_account_id' => $konto->id,
        'initiated_by_user_id' => $person->id,
        'status' => 'pending',
    ]);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse()
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse()
        ->and(DB::table('ownership_transfer')->where('id', $ägarbyte->id)->exists())->toBeFalse();
});

it('personens egna rader raderas, och ingen främmandenyckel lämnas kvar', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);

    $antal = personraderingPersonensRader($person, $container);

    expect(personraderingKvarvarandeRader($person))->toBe($antal);

    // En åtkomst som MOTTAGARE i någon annans container: raden är personens,
    // och den ger rätt till någon annans innehåll.
    $agare = Account::factory()->create();
    $frammande = personraderingContainer($agare);
    beviljaAccess($frammande, $person, 'read', 'guest');

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(personraderingKvarvarandeRader($person))->toBe(0)
        ->and(DB::table('container_access')->where('grantee_type', 'user')->where('grantee_id', $person->id)->exists())->toBeFalse()
        // Containern personen hade åtkomst till är orörd — det var bara
        // åtkomsten som var hennes.
        ->and(Container::query()->whereKey($frammande->id)->exists())->toBeTrue();
});

/*
 * Klart när: personraderingen tar bort besöksraderna — även de som pekar på
 * någon ANNANS item.
 *
 * Raden i personens egen container försvinner redan med kontot:
 * DeleteAccount gallrar items genom PurgeContent::item(), och den metoden tar
 * besöksraderna (issue 160). Det är därför den FRÄMMANDE raden är provet —
 * den bär personens `user_id` och en RESTRICT-nyckel mot ett item som står
 * kvar, och bara DeleteUser:s eget steg kan ta den.
 */
it('personens besöksrader raderas även på någon annans item', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    personraderingContainer($konto);

    $agare = Account::factory()->create();
    $frammande = personraderingContainer($agare);
    $item = Item::factory()->for($frammande, 'container')->create([
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $agare->id,
    ]);

    beviljaAccess($frammande, $person, 'read', 'guest');

    DB::table('recent_visit')->insert([
        'user_id' => $person->id,
        'item_id' => $item->id,
        'visited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(DB::table('recent_visit')->where('item_id', $item->id)->exists())->toBeFalse()
        // Itemet är någon annans och står kvar — det var bara besöksraden som
        // var personens.
        ->and(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

/*
 * Klart när: en person i ett konto med andra medlemmar lämnar kontot, och
 * kontot står kvar orört.
 */
it('en person i ett konto med andra medlemmar lämnar kontot, och kontot står kvar orört', function () {
    $person = User::factory()->create();
    [$kvarvarande, $annan] = personraderingDelatKonto($person, 'member', 'owner');
    $container = personraderingContainer($kvarvarande);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $annan->id,
        'created_by_account_id' => $kvarvarande->id,
    ]);

    // Personen är dessutom ensam i ett eget konto, som ska bort.
    $eget = personraderingEgetKonto($person);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($eget->id)->exists())->toBeFalse()
        // Kontot med de andra medlemmarna står kvar, med sitt innehåll och
        // sin kvarvarande medlem.
        ->and(Account::query()->whereKey($kvarvarande->id)->exists())->toBeTrue()
        ->and(Container::query()->whereKey($container->id)->exists())->toBeTrue()
        ->and(Item::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and(DB::table('account_user')->where('account_id', $kvarvarande->id)->where('user_id', $person->id)->exists())->toBeFalse()
        ->and(DB::table('account_user')->where('account_id', $kvarvarande->id)->where('user_id', $annan->id)->exists())->toBeTrue();
});

/*
 * Klart när: var och en av de tre spärrarna gör att ingenting raderas och ger
 * sin kod.
 */

it('en ensam ägare i ett konto med andra medlemmar spärrar raderingen', function () {
    $person = User::factory()->create();
    [$konto] = personraderingDelatKonto($person, 'owner', 'member');

    $blockers = app(DeleteUser::class)->blockers($person);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->code)->toBe(DeletionBlocker::CODE_SOLE_OWNER)
        ->and($blockers[0]->data)->toBe(['account' => $konto->ulid]);

    expect(fn () => app(DeleteUser::class)->handle($person))
        ->toThrow(UserDeletionBlocked::class);

    // Ingenting raderat: personen finns, medlemskapet finns, kontot finns.
    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeTrue()
        ->and(DB::table('account_user')->where('account_id', $konto->id)->count())->toBe(2);
});

it('en annan ägare i kontot tar bort spärren', function () {
    $person = User::factory()->create();
    personraderingDelatKonto($person, 'owner', 'owner');

    expect(app(DeleteUser::class)->blockers($person))->toBe([]);
});

it('en delad container i ett konto som skulle raderas spärrar raderingen', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);
    $gäst = Account::factory()->create();

    beviljaAccess($container, $gäst, 'read', 'managed');

    $blockers = app(DeleteUser::class)->blockers($person);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->code)->toBe(DeletionBlocker::CODE_SHARED_CONTAINER)
        ->and($blockers[0]->data)->toBe([
            'account' => $konto->ulid,
            'containers' => [$container->ulid],
        ]);

    expect(fn () => app(DeleteUser::class)->handle($person))
        ->toThrow(UserDeletionBlocked::class);

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeTrue()
        ->and(Container::query()->whereKey($container->id)->exists())->toBeTrue();
});

it('en obesvarad inbjudan räknas som en aktiv medlem och spärrar', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);

    bjudInRad($container, 'blivande@exempel.se', 'pending', now()->addDays(7));

    $blockers = app(DeleteUser::class)->blockers($person);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->code)->toBe(DeletionBlocker::CODE_SHARED_CONTAINER);

    expect(fn () => app(DeleteUser::class)->handle($person))
        ->toThrow(UserDeletionBlocked::class);
});

it('en återkallad åtkomst spärrar inte', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);
    $gäst = Account::factory()->create();

    beviljaAccess($container, $gäst, 'read', 'managed', revokedAt: Carbon::now()->subDay());

    expect(app(DeleteUser::class)->blockers($person))->toBe([]);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse();
});

it('en rättslig spärr på ett av personens konton spärrar raderingen', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);

    LegalHold::factory()->create(['account_id' => $konto->id]);

    $blockers = app(DeleteUser::class)->blockers($person);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->code)->toBe(DeletionBlocker::CODE_LEGAL_HOLD)
        // Ingen `$data`: spärren får inte peka ut kontot den gäller
        // ([[ADR-0043 Tre loggar]] § Den rättsliga spärren, issue 145).
        ->and($blockers[0]->data)->toBe([]);

    expect(fn () => app(DeleteUser::class)->handle($person))
        ->toThrow(UserDeletionBlocked::class);

    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeTrue();
});

it('en hävd rättslig spärr spärrar inte', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);

    LegalHold::factory()->create([
        'account_id' => $konto->id,
        'lifted_at' => now()->subDay(),
    ]);

    expect(app(DeleteUser::class)->blockers($person))->toBe([]);
});

it('spärrarna bärs med i undantaget så att ytan kan visa dem', function () {
    $person = User::factory()->create();
    personraderingDelatKonto($person, 'owner', 'member');

    $undantag = null;

    try {
        app(DeleteUser::class)->handle($person);
    } catch (UserDeletionBlocked $fångat) {
        $undantag = $fångat;
    }

    if (! $undantag instanceof UserDeletionBlocked) {
        throw new RuntimeException('Undantaget uteblev.');
    }

    expect($undantag->blockers)->toHaveCount(1)
        ->and($undantag->blockers[0]->code)->toBe(DeletionBlocker::CODE_SOLE_OWNER);
});

/*
 * Klart när: ett konto med en prenumeration som är `active` raderas ändå, och
 * prenumerationen är borta.
 */
it('ett konto med en aktiv prenumeration raderas ändå, och prenumerationen är borta', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);

    Subscription::factory()->create(['account_id' => $konto->id, 'status' => 'active']);

    // Rätten att bli glömd går före livscykelns undantag: prenumerationen
    // spärrar inte, och den avslutas utan återbetalning.
    expect(app(DeleteUser::class)->blockers($person))->toBe([]);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse()
        ->and(DB::table('subscription')->where('account_id', $konto->id)->exists())->toBeFalse();
});

/*
 * Klart när: innehåll som personen skapat i en annans container står kvar med
 * författaren nollställd.
 */
it('innehåll personen skapat i en annans container står kvar med författaren nollställd', function () {
    $person = User::factory()->create();
    $personensKonto = personraderingEgetKonto($person);

    $agare = Account::factory()->create();
    $agarmedlem = User::factory()->create();
    $agare->users()->attach($agarmedlem, ['role' => 'owner']);

    $container = personraderingContainer($agare);

    $storedFile = StoredFile::factory()->create(['byte_size' => 1024, 'reference_count' => 1]);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $personensKonto->id,
    ]);

    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $person->id,
        'billed_account_id' => $personensKonto->id,
    ]);

    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'container_id' => $container->id,
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $personensKonto->id,
    ]);

    $schema = Schedule::factory()->for($item, 'item')->create();
    $forekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'status' => 'completed',
        'completed_at' => now(),
        'completed_by_user_id' => $person->id,
        'completed_by_account_id' => $personensKonto->id,
    ]);

    $atkomst = beviljaAccess($container, $agare, 'read', 'guest');

    // Beviljad av personen: författarkolumnen mot `user` nollställs, raden
    // står kvar. Kolumnen ligger utanför `#[Fillable]` (som
    // `granted_by_user_id` gör), så den skrivs förbi modellen.
    DB::table('container_access')->where('id', $atkomst->id)->update(['granted_by_user_id' => $person->id]);

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $person->id,
    ]);

    app(DeleteUser::class)->handle($person);

    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and($item->refresh()->created_by_user_id)->toBeNull()
        // Även kolumnen mot kontot: kontot raderades av samma körning.
        ->and($item->created_by_account_id)->toBeNull()
        ->and($bilaga->refresh()->uploaded_by_user_id)->toBeNull()
        // Bytena flyttades till containerns ägarkonto av DeleteAccount
        // (ADR-0045 § Beslut 1) — bilagan är kvar, betalaren är en annan.
        ->and($bilaga->billed_account_id)->toBe($agare->id)
        ->and($kostnad->refresh()->created_by_user_id)->toBeNull()
        ->and($forekomst->refresh()->completed_by_user_id)->toBeNull()
        ->and($atkomst->refresh()->granted_by_user_id)->toBeNull()
        ->and($export->refresh()->requested_by_user_id)->toBeNull();
});

/*
 * Klart när: väntande inbjudningar och ägarbyten som personen startat är
 * tillbakadragna.
 */
it('väntande inbjudningar och ägarbyten personen startat dras tillbaka', function () {
    $person = User::factory()->create();
    [$konto] = personraderingDelatKonto($person, 'member', 'owner');
    $container = personraderingContainer($konto);

    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'mottagare@exempel.se',
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => $person->id,
    ]);

    $mottagare = Account::factory()->create();
    $ägarbyte = skapaÄgarbyteRad($container, [
        'from_account_id' => $konto->id,
        'to_account_id' => $mottagare->id,
        'initiated_by_user_id' => $person->id,
        'status' => 'pending',
    ]);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        // Raden raderas aldrig — den dras tillbaka, och avsändaren nollställs.
        ->and($inbjudan->refresh()->status)->toBe('revoked')
        ->and($inbjudan->invited_by_user_id)->toBeNull()
        ->and($ägarbyte->refresh()->status)->toBe('revoked')
        ->and($ägarbyte->initiated_by_user_id)->toBeNull();
});

it('besvarade inbjudningar och ägarbyten behåller sin rad med författaren nollställd', function () {
    $person = User::factory()->create();
    [$konto] = personraderingDelatKonto($person, 'member', 'owner');
    $container = personraderingContainer($konto);

    $accepterad = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'accepterad@exempel.se',
        'status' => 'accepted',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => $person->id,
    ]);

    $avslagen = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'avslagen@exempel.se',
        'status' => 'rejected',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => $person->id,
    ]);

    $mottagare = Account::factory()->create();
    $accepteratÄgarbyte = skapaÄgarbyteRad($container, [
        'from_account_id' => $konto->id,
        'to_account_id' => $mottagare->id,
        'initiated_by_user_id' => $person->id,
        'status' => 'accepted',
    ]);

    app(DeleteUser::class)->handle($person);

    // Status står kvar — bara avsändaren försvinner (ADR-0045 § Beslut 2).
    expect($accepterad->refresh()->status)->toBe('accepted')
        ->and($accepterad->invited_by_user_id)->toBeNull()
        ->and($avslagen->refresh()->status)->toBe('rejected')
        ->and($avslagen->invited_by_user_id)->toBeNull()
        ->and($accepteratÄgarbyte->refresh()->status)->toBe('accepted')
        ->and($accepteratÄgarbyte->initiated_by_user_id)->toBeNull();
});

it('en inbjudan på en container i papperskorgen dras också tillbaka', function () {
    $person = User::factory()->create();
    [$konto] = personraderingDelatKonto($person, 'member', 'owner');
    $container = personraderingContainer($konto);

    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'kvarglomd@exempel.se',
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => $person->id,
    ]);

    // En mjukradering återkallar inte inbjudan — raden ligger kvar tills
    // gallringen tar containern. Utan withTrashed() på containern svarar
    // relationen null och hela raderingen faller.
    $container->delete();

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and($inbjudan->refresh()->status)->toBe('revoked')
        ->and($inbjudan->invited_by_user_id)->toBeNull();
});

/*
 * Klart när: loggraderna med personens `user_id` står kvar.
 */
it('loggraderna med personens user_id står kvar', function () {
    $person = User::factory()->create();

    $handelselogg = AuditLog::factory()->create([
        'user_id' => $person->id,
        'action' => AuditLog::ACTION_ITEM_CREATED,
    ]);

    $sakerhetsrad = DB::table('security_log')->insertGetId([
        'action' => SecurityLog::ACTION_LOGIN,
        'user_id' => $person->id,
        'ip_group' => null,
        'device_name' => null,
        'meta' => json_encode([]),
        'created_at' => now(),
    ]);

    app(DeleteUser::class)->handle($person);

    // Nycklarna är identifierare utan främmande nyckel (issue 107) och
    // gallras av logggallringen, inte av personraderingen.
    expect(AuditLog::query()->whereKey($handelselogg->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->whereKey($handelselogg->id)->sole()->user_id)->toBe($person->id)
        ->and(DB::table('security_log')->where('id', $sakerhetsrad)->exists())->toBeTrue()
        ->and(DB::table('security_log')->where('id', $sakerhetsrad)->value('user_id'))->toBe($person->id);
});

/*
 * Klart när: e-postadressen kan registreras igen.
 */
it('e-postadressen kan registreras igen efter raderingen', function () {
    $person = User::factory()->create(['email' => 'aterbrukad@exempel.se']);
    personraderingEgetKonto($person);

    // En outnyttjad inloggningslänk för adressen: utan städningen skulle den
    // logga in den som registrerar adressen härnäst
    // (App\Support\Auth\MagicLinkBroker slår upp användaren på `email`).
    DB::table('magic_link_token')->insert([
        'email' => $person->email,
        'token_hash' => hash('sha256', 'kvarglomd-lank'),
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(DeleteUser::class)->handle($person);

    expect(DB::table('magic_link_token')->where('email', $person->email)->exists())->toBeFalse();

    // Ingen mjukradering: raden är borta, och adressen är ledig.
    $ny = User::factory()->create(['email' => 'aterbrukad@exempel.se']);

    expect($ny->id)->not->toBe($person->id)
        ->and(User::query()->where('email', 'aterbrukad@exempel.se')->count())->toBe(1);
});

/*
 * Klart när: allt sker i en transaktion, och ett fel mitt i lämnar ingenting
 * halvt.
 */
it('ett fel mitt i raderingen lämnar ingenting halvt', function () {
    $person = User::factory()->create();
    $konto = personraderingEgetKonto($person);
    $container = personraderingContainer($konto);
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    personraderingPersonensRader($person, $container);

    $trasig = new class(new PurgeContainer(new PurgeContent(new PurgeAttachment))) extends DeleteAccount
    {
        public function handle(Account $account): void
        {
            throw new RuntimeException('trasig kontoradering');
        }
    };

    $action = new DeleteUser(
        $trasig,
        app(RevokeInvitation::class),
        app(RevokeOwnershipTransfer::class),
    );

    expect(fn () => $action->handle($person))
        ->toThrow(RuntimeException::class, 'trasig kontoradering');

    // Allt står kvar: personen, kontot, containern, itemet, medlemskapet och
    // varje rad som bara var hennes. Ingenting halvt.
    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeTrue()
        ->and(Container::query()->whereKey($container->id)->exists())->toBeTrue()
        ->and(Item::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and(DB::table('account_user')->where('account_id', $konto->id)->where('user_id', $person->id)->exists())->toBeTrue()
        ->and(personraderingKvarvarandeRader($person))->toBeGreaterThan(0);
});

/*
 * Kantfall som inte har en egen rad i "Klart när" men som raderingen måste
 * klara: en person utan konto, och en som redan är borta.
 */
it('en person utan konton raderas', function () {
    $person = User::factory()->create();

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse();
});

it('en person som redan är raderad rörs inte en gång till', function () {
    $person = User::factory()->create();
    personraderingEgetKonto($person);

    app(DeleteUser::class)->handle($person);

    // Den andra körningen hittar ingen rad och gör ingenting — den kastar
    // inte och skriver ingen andra loggrad.
    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(DB::table('security_log')->where('action', SecurityLog::ACTION_USER_DELETED)->count())->toBe(1);
});

/*
 * Klart när: [[Konton och åtkomst]] och [[Registerförteckning]] beskriver
 * raderingen.
 *
 * Dokumentproven är textprov med flit — avsnittet är kontraktet mot den som
 * bygger ytan i issue 145. Avsnittet fångas först och innehållet letas inuti
 * det: ett `toContain` mot hela filen hade passerat på en rad någon annan
 * stans.
 */
function personraderingAvsnitt(string $fil, string $rubrik): string
{
    $rader = file(base_path($fil)) ?: [];
    $start = null;
    $text = [];

    foreach ($rader as $rad) {
        if (str_starts_with($rad, $rubrik)) {
            $start = strlen($rubrik);

            continue;
        }

        if ($start === null) {
            continue;
        }

        if (str_starts_with($rad, '#') && strlen($rad) - strlen(ltrim($rad, '#')) <= $start) {
            break;
        }

        $text[] = $rad;
    }

    if ($text === []) {
        throw new RuntimeException("Hittade inget avsnitt {$rubrik} i {$fil}.");
    }

    return implode('', $text);
}

it('Konton och åtkomst beskriver personraderingen', function () {
    $avsnitt = personraderingAvsnitt('docs/Datamodell/Konton och åtkomst.md', '### Personradering');

    expect($avsnitt)->toContain('App\Actions\User\DeleteUser')
        ->toContain('App\Support\User\DeletionBlocker')
        ->toContain(DeletionBlocker::CODE_SOLE_OWNER)
        ->toContain(DeletionBlocker::CODE_SHARED_CONTAINER)
        ->toContain(DeletionBlocker::CODE_LEGAL_HOLD)
        ->toContain('DeleteAccount')
        ->toContain('user.deleted')
        ->toContain('account_user')
        ->toContain('personal_access_tokens')
        // Prenumerationen spärrar inte, och det är en del av kontraktet.
        ->toContain('prenumeration');
});

it('Registerförteckningen beskriver raderingen', function () {
    $rader = array_values(array_filter(
        file(base_path('docs/Registerförteckning.md')) ?: [],
        fn (string $rad) => str_starts_with($rad, '|') && str_contains($rad, '`user`'),
    ));

    expect($rader)->toHaveCount(1);

    $rad = trim($rader[0]);

    expect($rad)->toContain('DeleteUser')
        ->toContain('ADR-0045')
        ->toContain('omedelbart')
        // Adressen blir ledig — det är hela poängen med en riktig radering.
        ->toContain('registrera igen');

    // Personraderingen skriver en egen rad i säkerhetsloggen, och
    // registerförteckningen säger vilken.
    $sakerhetsrad = array_values(array_filter(
        file(base_path('docs/Registerförteckning.md')) ?: [],
        fn (string $rad) => str_starts_with($rad, '|') && str_contains($rad, '`security_log`'),
    ));

    expect($sakerhetsrad)->toHaveCount(1)
        ->and(trim($sakerhetsrad[0]))->toContain('user.deleted');
});
