<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 10a · Inbjudningar, avsändarytan. Se
 * App\Http\Controllers\Api\ContainerInvitationController,
 * App\Http\Requests\Invitation\StoreInvitationRequest,
 * App\Http\Resources\InvitationResource och App\Models\Invitation.
 *
 * Mottagarsidan — mejlet, acceptera och avvisa — är issue 10b och testas
 * inte här. Behörigheten är 9b:s policy oförändrad
 * (App\Policies\ContainerPolicy::viewAccesses()/manageAccess()), så
 * behörighetstesterna nedan bevisar bara att INBJUDNINGSRUTTERNA hänger på
 * rätt grind, inte policyn i sig.
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php och beviljaAccess() i
 * tests/Feature/Container/ContainerAtkomstTest.php — Pests globala
 * namnrymd gör dem åtkomliga rakt av här, samma mönster som
 * ContainerAtkomstApiTest.php redan använder.
 *
 * "Klart när" (InbjudanTest):
 * - ägarkontots medlem kan bjuda in en e-postadress
 * - en ny inbjudan går ut om fjorton dagar
 * - tokenet lagras som hash och läcker aldrig i svaret
 * - e-postadressen normaliseras till gemener
 * - en write-access får aldrig bjuda in
 * - en write-access får aldrig lista inbjudningar
 * - ett read_only ägarkonto nekar inbjudan
 * - ett read_only ägarkonto kan ändå lista inbjudningar
 * - en andra pending inbjudan till samma adress avvisas
 * - en ny inbjudan går igenom efter att den förra dragits tillbaka
 * - en tillbakadragen inbjudan får status revoked och raderas aldrig
 * - en redan tillbakadragen inbjudan kan inte dras tillbaka igen
 * - en inbjudan i en annan container går inte att dra tillbaka
 * - en utgången pending-inbjudan redovisas som expired
 * - listningen visar även tillbakadragna och utgångna inbjudningar
 * - listningen gör inte en fråga per rad
 * - en ogiltig e-postadress avvisas
 * - en ogiltig level avvisas
 */

/**
 * Skapar en invitation-rad direkt, förbi API:et — för de tester som
 * behöver ett utgångsläge rutten själv aldrig producerar (en utgången
 * `pending`-rad, en `accepted`). `invited_by_user_id` är obligatorisk (FK)
 * men vem det är spelar ingen roll här, så en fristående användare skapas
 * åt raden, precis som beviljaAccess() gör i ContainerAtkomstTest.php.
 */
function bjudInRad(
    Container $container,
    string $email,
    string $status = 'pending',
    ?Carbon $expiresAt = null,
): Invitation {
    return Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => $email,
        'status' => $status,
        'expires_at' => $expiresAt ?? now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

it('ägarkontots medlem kan bjuda in en e-postadress', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'write',
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'email' => 'ny@exempel.se',
            'level' => 'write',
            'status' => 'pending',
            'invited_by' => $user->ulid,
        ],
    ]);
    // Löpnumret exponeras aldrig, se issue 8 § Beslut 7.
    expect($response->json('data.id'))->toBeNull();

    $rad = DB::table('invitation')->where('email', 'ny@exempel.se')->first();
    expect($rad)->not->toBeNull();
    expect($rad->container_id)->toBe($container->id);
    expect($rad->status)->toBe('pending');
    expect($rad->level)->toBe('write');
    expect($rad->invited_by_user_id)->toBe($user->id);
});

it('en ny inbjudan går ut om fjorton dagar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    $inbjudan = Invitation::query()->where('email', 'ny@exempel.se')->firstOrFail();

    // TTL:en är Invitation::TTL_DAYS (14), satt med ramverkets now() i UTC
    // — jämförelsen tål den sekund testkörningen råkar ta.
    expect($inbjudan->expires_at->diffInSeconds(now()->addDays(Invitation::TTL_DAYS), absolute: true))
        ->toBeLessThan(60);
    expect(Invitation::TTL_DAYS)->toBe(14);
});

it('tokenet lagras som hash och läcker aldrig i svaret', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertCreated();

    $rad = DB::table('invitation')->where('email', 'ny@exempel.se')->first();
    expect($rad->token_hash)->toMatch('/^[0-9a-f]{64}$/');

    // Klartexten är 64 tecken ur Str::random()s alfabet och kastas i
    // kontrollern — den kan alltså inte finnas i kroppen. Hashen får inte
    // heller läcka: ingen nyckel bär den, och inget värde alls är så långt
    // som ett token eller en hash (issue 10a § Att se upp med).
    $kropp = $response->getContent();
    expect($kropp)->not->toContain($rad->token_hash);
    expect($kropp)->not->toMatch('/[A-Za-z0-9]{64}/');

    $data = $response->json('data');
    expect(array_keys($data))->not->toContain('token');
    expect(array_keys($data))->not->toContain('token_hash');
});

it('e-postadressen normaliseras till gemener', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'Alice@Exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.email'))->toBe('alice@exempel.se');
    expect(DB::table('invitation')->where('email', 'alice@exempel.se')->exists())->toBeTrue();
    expect(DB::table('invitation')->where('email', 'Alice@Exempel.se')->count())->toBe(0);
});

it('en write-access får aldrig bjuda in', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en write-access får aldrig lista inbjudningar', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');

    $response = getJson("/api/containers/{$container->ulid}/invitations", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only ägarkonto nekar inbjudan', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $account->update(['status' => 'read_only']);

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('ett read_only ägarkonto kan ändå lista inbjudningar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    bjudInRad($container, 'ny@exempel.se');
    $account->update(['status' => 'read_only']);

    $response = getJson("/api/containers/{$container->ulid}/invitations", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('en andra pending inbjudan till samma adress avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $första = bjudInRad($container, 'ny@exempel.se');

    // Versalerna bevisar att spärren frågar på den NORMALISERADE adressen,
    // se issue 10a § Att se upp med.
    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'NY@Exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('invitation.already_pending');
    expect($response->json('error.data.invitation'))->toBe($första->ulid);
    expect(DB::table('invitation')->count())->toBe(1);
});

it('en ny inbjudan går igenom efter att den förra dragits tillbaka', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $första = bjudInRad($container, 'ny@exempel.se');

    deleteJson("/api/containers/{$container->ulid}/invitations/{$första->ulid}", [], $headers)
        ->assertNoContent();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    // Spärren ser bara `pending`: den tillbakadragna raden ligger kvar
    // bredvid den nya.
    expect(DB::table('invitation')->where('email', 'ny@exempel.se')->count())->toBe(2);
});

it('en tillbakadragen inbjudan får status revoked och raderas aldrig', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $inbjudan = bjudInRad($container, 'ny@exempel.se');

    $response = deleteJson("/api/containers/{$container->ulid}/invitations/{$inbjudan->ulid}", [], $headers);

    $response->assertNoContent();

    $rad = DB::table('invitation')->where('id', $inbjudan->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->status)->toBe('revoked');
});

it('en redan tillbakadragen inbjudan kan inte dras tillbaka igen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $inbjudan = bjudInRad($container, 'ny@exempel.se', status: 'revoked');

    $response = deleteJson("/api/containers/{$container->ulid}/invitations/{$inbjudan->ulid}", [], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('invitation.not_pending');
    expect(DB::table('invitation')->where('id', $inbjudan->id)->value('status'))->toBe('revoked');
});

it('en inbjudan i en annan container går inte att dra tillbaka', function () {
    [$account, , $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $inbjudan = bjudInRad($containerB, 'ny@exempel.se');

    $response = deleteJson("/api/containers/{$containerA->ulid}/invitations/{$inbjudan->ulid}", [], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
    expect(DB::table('invitation')->where('id', $inbjudan->id)->value('status'))->toBe('pending');
});

it('en utgången pending-inbjudan redovisas som expired', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $inbjudan = bjudInRad($container, 'ny@exempel.se', expiresAt: now()->subDay());

    $response = getJson("/api/containers/{$container->ulid}/invitations", $headers);

    $response->assertOk();
    expect($response->json('data.0.status'))->toBe('expired');

    // Kolumnen ändras INTE av att tiden passerat — ingen bakgrundsprocess
    // flippar den, se issue 10a § Beslut 7. Det här är avsiktligt.
    expect(DB::table('invitation')->where('id', $inbjudan->id)->value('status'))->toBe('pending');
});

it('listningen visar även tillbakadragna och utgångna inbjudningar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    bjudInRad($container, 'oppen@exempel.se');
    bjudInRad($container, 'tillbaka@exempel.se', status: 'revoked');
    bjudInRad($container, 'utgangen@exempel.se', expiresAt: now()->subDay());

    $response = getJson("/api/containers/{$container->ulid}/invitations", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    $statusPerAdress = collect($response->json('data'))->pluck('status', 'email');
    expect($statusPerAdress->get('oppen@exempel.se'))->toBe('pending');
    expect($statusPerAdress->get('tillbaka@exempel.se'))->toBe('revoked');
    expect($statusPerAdress->get('utgangen@exempel.se'))->toBe('expired');
});

/*
 * Mäter att frågeantalet INTE växer med antalet rader (issue 10a § Beslut
 * 14: en User::whereIn(...)->pluck() för hela listan), inte ett fast
 * antal. Varje rad får en egen inbjudare, så en lazy-load per rad skulle
 * synas direkt. Samma upplägg som ContainerAtkomstApiTest.
 */
it('listningen gör inte en fråga per rad', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    bjudInRad($container, 'en@exempel.se');
    bjudInRad($container, 'tva@exempel.se');

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    // "Värm" Sanctum-guarden med ett omätt anrop innan mätningen börjar,
    // se samma resonemang i ContainerCrudTest.
    getJson("/api/containers/{$container->ulid}/invitations", $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson("/api/containers/{$container->ulid}/invitations", $headers);
    $frågorMedTvåRader = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(2);

    bjudInRad($container, 'tre@exempel.se');
    bjudInRad($container, 'fyra@exempel.se');
    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson("/api/containers/{$container->ulid}/invitations", $headers);
    $frågorMedFyraRader = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(4);

    expect($frågorMedFyraRader)->toBe($frågorMedTvåRader);

    Carbon::setTestNow();
});

it('en ogiltig e-postadress avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'inte-en-adress',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.email.0.code'))->toBe('validation.email');
    expect(DB::table('invitation')->count())->toBe(0);
});

it('en ogiltig level avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'admin',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.level.0.code'))->toBe('validation.in');
    expect(DB::table('invitation')->count())->toBe(0);
});
