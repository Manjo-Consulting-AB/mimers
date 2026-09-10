<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Plan;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

/*
 * Issue 48 · Tak för utestående inbjudningar, se
 * App\Support\Plan\Entitlements::assertPendingInvitationsWithinLimit() och
 * [[ADR-0017 Missbruksvektorer]] § 5.
 *
 * Det som skyddas är leveransryktet hos e-postleverantören: en inbjudan går
 * till en overifierad adress, och magic länkar är inloggningskritiska. Taket
 * ligger i `plan.limits`, aldrig som konstant — därför skruvas det med
 * sättPlangräns() i testerna och aldrig genom en kodändring.
 *
 * Free-planens `containers: 1` och `shared_users_per_container: 1` står i
 * vägen för att nå det nya taket via API:et, så containrar byggs med
 * Container::factory()->for($account, 'account') och `pending`-rader med
 * bjudInRad(), medan delningstaket skruvas upp med sättPlangräns() så att det
 * nya taket kan prövas för sig (issue 48 § Att se upp med).
 *
 * kontoMedMedlem(), bjudInRad() och sättPlangräns() är globala testhjälpare i
 * tests/Support/Testhjalpare.php och används oförändrade.
 */

/**
 * Sätter upp ett gratiskonto vars containrar får bjuda in fritt (delningstaket
 * ur vägen) men där inbjudningstaket är $tak. Returnerar kontot, headern och
 * en container att bjuda in i.
 *
 * @return array{0: Account, 1: array<string, string>, 2: Container}
 */
function inbjudningsTakKontext(int $tak): array
{
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    sättPlangräns('free', 'shared_users_per_container', 50);
    sättPlangräns('free', 'pending_invitations', $tak);

    return [$account, $headers, $container];
}

it('ett konto vars tak är fullt nekas nästa inbjudan', function () {
    [, $headers, $container] = inbjudningsTakKontext(2);

    bjudInRad($container, 'a@exempel.se');
    bjudInRad($container, 'b@exempel.se');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'c@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.pending_invitations_exceeded');
});

it('felkoden bär exakt två heltal i data, utan message', function () {
    [, $headers, $container] = inbjudningsTakKontext(2);

    bjudInRad($container, 'a@exempel.se');
    bjudInRad($container, 'b@exempel.se');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'c@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);

    $data = $response->json('error.data');
    expect($data)->toBe(['limit' => 2, 'used' => 2]);
    expect($data['limit'])->toBeInt();
    expect($data['used'])->toBeInt();

    // Ingen message-nyckel, vare sig på toppnivå eller i höljet (AGENTS.md
    // § Felformat i API:et), och data är ett JSON-objekt, aldrig en array.
    expect($response->json('message'))->toBeNull();
    expect($response->json('error.message'))->toBeNull();
    expect(json_decode($response->getContent())->error->data)->toBeInstanceOf(stdClass::class);
});

it('ett nekat anrop skapar ingen rad och skickar inget mejl', function () {
    Notification::fake();

    [, $headers, $container] = inbjudningsTakKontext(1);

    bjudInRad($container, 'a@exempel.se');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);

    // Undantaget kastas före Str::random(), save() och Notification::route()
    // (issue 48 § Beslut 9): ett nekande lämnar inga spår.
    expect(Invitation::where('email', 'b@exempel.se')->exists())->toBeFalse();
    expect(Invitation::count())->toBe(1);
    Notification::assertNothingSent();
});

it('taket räknas över kontots alla containrar', function () {
    [$account, $headers, $tredje] = inbjudningsTakKontext(2);

    $första = Container::factory()->for($account, 'account')->create();
    $andra = Container::factory()->for($account, 'account')->create();

    bjudInRad($första, 'a@exempel.se');
    bjudInRad($andra, 'b@exempel.se');

    // Två obesvarade fördelade på två containrar — den tredje containern är
    // tom, men kontots tak är fullt.
    $response = postJson("/api/containers/{$tredje->ulid}/invitations", [
        'email' => 'c@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.pending_invitations_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 2, 'used' => 2]);
});

it('ett annat kontos inbjudningar påverkar inte taket', function () {
    [, $headers, $container] = inbjudningsTakKontext(1);

    [$annatKonto] = kontoMedMedlem();
    $annans = Container::factory()->for($annatKonto, 'account')->create();
    bjudInRad($annans, 'a@exempel.se');

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('en accepterad inbjudan frigör en plats', function () {
    [, $headers, $container] = inbjudningsTakKontext(1);

    bjudInRad($container, 'a@exempel.se', status: 'accepted');

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('en avvisad inbjudan frigör en plats', function () {
    [, $headers, $container] = inbjudningsTakKontext(1);

    bjudInRad($container, 'a@exempel.se', status: 'rejected');

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('en tillbakadragen inbjudan frigör en plats', function () {
    [, $headers, $container] = inbjudningsTakKontext(1);

    bjudInRad($container, 'a@exempel.se', status: 'revoked');

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('en utgången inbjudan frigör en plats trots att status är pending', function () {
    [, $headers, $container] = inbjudningsTakKontext(1);

    // Utgång härleds ur expires_at, aldrig ur status — kolumnen står kvar på
    // `pending` (issue 10a § Beslut 7).
    $utgången = bjudInRad($container, 'a@exempel.se', expiresAt: now()->subDay());
    expect($utgången->status)->toBe('pending');

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('gränsen kommer ur planraden, inte ur koden', function () {
    [, $headers, $container] = inbjudningsTakKontext(2);

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'a@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    // Den tredje nekas — taket är 2 för att planraden säger det, utan en
    // kodändring.
    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'c@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.pending_invitations_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 2, 'used' => 2]);
});

it('null i en plan nekar aldrig, oavsett antal obesvarade rader', function () {
    [, $headers, $container] = inbjudningsTakKontext(1);

    // sättPlangräns() tar int|bool; `null` (obegränsat) sätts direkt på
    // planraden, precis som en framtida plan skulle se ut.
    $plan = Plan::where('code', 'free')->firstOrFail();
    $limits = $plan->limits;
    $limits['pending_invitations'] = null;
    $plan->update(['limits' => $limits]);

    foreach (range(1, 15) as $i) {
        bjudInRad($container, "obesvarad{$i}@exempel.se");
    }

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});

it('migrationen ger free och pro nyckeln med 10 respektive 100', function () {
    expect(Plan::where('code', 'free')->firstOrFail()->planLimit('pending_invitations'))->toBe(10);
    expect(Plan::where('code', 'pro')->firstOrFail()->planLimit('pending_invitations'))->toBe(100);
});

it('en plan byggd med fabriken bär nyckeln', function () {
    expect(Plan::factory()->create()->planLimit('pending_invitations'))->toBe(10);
});

it('delningstaket svarar fortfarande med sin egen kod', function () {
    // Standardgränserna för free: en delad användare. Kontot slår i
    // delningstaket långt före inbjudningstaket (10), och ska då få
    // quota.shared_users_exceeded — inte den nya koden.
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    bjudInRad($container, 'a@exempel.se');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'b@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.shared_users_exceeded');
    expect($response->json('error.data'))->toBe(['limit' => 1, 'used' => 1]);
});

it('duplikatspärren ger invitation.already_pending efter omskrivningen', function () {
    [, $headers, $container] = inbjudningsTakKontext(5);

    $första = bjudInRad($container, 'ny@exempel.se');

    $response = postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('invitation.already_pending');
    expect($response->json('error.data.invitation'))->toBe($första->ulid);
});

it('en avvisad eller utgången rad blockerar inte en ny inbjudan', function () {
    [, $headers, $container] = inbjudningsTakKontext(5);

    bjudInRad($container, 'ny@exempel.se', status: 'rejected');
    bjudInRad($container, 'gammal@exempel.se', expiresAt: now()->subDay());

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'gammal@exempel.se',
        'level' => 'read',
    ], $headers)->assertCreated();
});
