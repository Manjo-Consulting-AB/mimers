<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 40 · Revisionsloggen. Se App\Actions\Audit\RecordAuditEvent,
 * App\Models\AuditLog, App\Http\Controllers\Api\AuditLogController och de två
 * anropsställena (39b:s accept och 9b:s återkallning).
 *
 * kontoMedMedlem(), beviljaAccess() och skapaÄgarbyteRad() är globala
 * testhjälpare i tests/Support/Testhjalpare.php.
 *
 * "Klart när" (RevisionsloggTest):
 * - en accepterad överlåtelse lämnar exakt en rad med action
 *   container.transferred, ägarbytets ULID som subject_id och båda kontonas
 *   ULID:er i meta
 * - rullas accepttransaktionen tillbaka finns ingen audit_log-rad
 * - en återkallad åtkomst lämnar exakt en rad med action access.revoked och
 *   åtkomstens ULID som subject_id
 * - meta innehåller ingen e-postadress i någon av de två händelserna
 * - en tom meta serialiseras som {} i svaret, aldrig []
 * - en medlem i ägarkontot kan läsa loggen; en användare med giltig
 *   write-åtkomst ser sina EGNA rader och inga andras, med 200 (issue 108 —
 *   före den fick hon auth.forbidden (403), se
 *   tests/Feature/Revision/LasregelTest.php för läsregeln i sin helhet)
 * - ett read_only ägarkonto kan läsa sin logg
 * - listningen svarar med nyaste raden först, och två rader i samma
 *   transaktion kommer i stabil ordning
 * - svaret bär ulid men aldrig id, varken för loggraden, användaren eller
 *   subjektet
 * - det finns ingen rutt som ändrar eller raderar en loggrad
 */

/**
 * En säljare med en container — samma utgångsläge som 39b:s acceptSäljare,
 * fast utan räknarrad: den spelar ingen roll för de här testerna.
 *
 * @return array{0: Account, 1: User, 2: Container} [$konto, $användare, $container]
 */
function revisionsSäljare(): array
{
    [$konto, $användare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $användare, $container];
}

/**
 * Säljare + container + mottagarkonto med medlem, klart att acceptera.
 *
 * @return array{0: Container, 1: Account, 2: Account, 3: User, 4: array<string, string>, 5: OwnershipTransfer}
 */
function revisionsUtgångsläge(array $överföringsAttribut = []): array
{
    [$säljarkonto, , $container] = revisionsSäljare();
    [$köparkonto, $köparAnvändare, $köparHeaders] = kontoMedMedlem();

    $överföring = skapaÄgarbyteRad($container, array_merge([
        'to_account_id' => $köparkonto->id,
        'to_email' => null,
    ], $överföringsAttribut));

    return [$container, $säljarkonto, $köparkonto, $köparAnvändare, $köparHeaders, $överföring];
}

/**
 * En loggrad direkt i containern, för de tester som prövar läsytan och inte
 * händelserna. $användare är den handlande användaren; utelämnas den är
 * raden en systemhändelse (user_id null).
 */
function loggRad(Container $container, Account $account, ?User $användare = null, array $attribut = []): AuditLog
{
    return AuditLog::factory()->create(array_merge([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $användare?->id,
    ], $attribut));
}

it('en accepterad överlåtelse lämnar exakt en audit_log-rad med container.transferred', function () {
    [$container, $säljarkonto, $köparkonto, $köparAnvändare, $köparHeaders, $överföring] = revisionsUtgångsläge();

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertOk();

    $rader = DB::table('audit_log')->where('container_id', $container->id)->get();
    expect($rader)->toHaveCount(1);

    $rad = $rader->first();
    expect($rad->action)->toBe('container.transferred');
    expect($rad->account_id)->toBe($säljarkonto->id);
    expect($rad->user_id)->toBe($köparAnvändare->id);
    expect($rad->container_id)->toBe($container->id);
    expect($rad->subject_type)->toBe('ownership_transfer');
    expect($rad->subject_id)->toBe($överföring->ulid);

    $meta = json_decode($rad->meta, true);
    expect($meta)->toHaveKeys(['from_account', 'to_account', 'excluded_item_count', 'retain_access_level']);
    expect($meta['from_account'])->toBe($säljarkonto->ulid);
    expect($meta['to_account'])->toBe($köparkonto->ulid);
    expect($meta['excluded_item_count'])->toBe(0);
    expect($meta['retain_access_level'])->toBeNull();

    expect($rad->meta)->not->toContain('@');
});

it('rullas accepttransaktionen tillbaka finns ingen audit_log-rad', function () {
    [$container, $säljarkonto, , , $köparHeaders, $överföring] = revisionsUtgångsläge();

    try {
        DB::transaction(function () use ($överföring, $köparHeaders): void {
            postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertOk();

            throw new RuntimeException('rulla tillbaka hela accepten');
        });
    } catch (RuntimeException) {
        // förväntad — transaktionen rullades tillbaka
    }

    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(0);
    expect($container->fresh()->account_id)->toBe($säljarkonto->id);
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('en återkallad åtkomst lämnar exakt en rad med access.revoked', function () {
    [$account, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $mottagare = User::factory()->create();
    $access = beviljaAccess($container, $mottagare, 'write', 'member');

    $response = deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers);

    $response->assertNoContent();

    $rader = DB::table('audit_log')->where('container_id', $container->id)->get();
    expect($rader)->toHaveCount(1);

    $rad = $rader->first();
    expect($rad->action)->toBe('access.revoked');
    expect($rad->account_id)->toBe($account->id);
    expect($rad->user_id)->toBe($ägare->id);
    expect($rad->container_id)->toBe($container->id);
    expect($rad->subject_type)->toBe('container_access');
    expect($rad->subject_id)->toBe($access->ulid);

    $meta = json_decode($rad->meta, true);
    expect($meta)->toHaveKeys(['grantee_type', 'grantee', 'level', 'kind']);
    expect($meta['grantee_type'])->toBe('user');
    expect($meta['grantee'])->toBe($mottagare->ulid);
    expect($meta['level'])->toBe('write');
    expect($meta['kind'])->toBe('member');

    expect($rad->meta)->not->toContain('@');

    // En andra återkallning av samma rad skriver ingen ny loggrad — den
    // ursprungliga tidsstämpeln är historiken.
    deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers)->assertNoContent();
    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(1);
});

it('en tom meta serialiseras som {} i svaret, aldrig som []', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    loggRad($container, $account, null, ['meta' => []]);

    $response = getJson("/api/containers/{$container->ulid}/audit-log", $headers);

    $response->assertOk();
    expect($response->getContent())->toContain('"meta":{}');
    expect($response->getContent())->not->toContain('"meta":[]');
});

it('en medlem i ägarkontot kan läsa loggen', function () {
    [$account, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    loggRad($container, $account, $ägare, ['action' => AuditLog::ACTION_CONTAINER_TRANSFERRED]);

    $response = getJson("/api/containers/{$container->ulid}/audit-log", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);

    $rad = $response->json('data.0');
    expect($rad)->toHaveKey('ulid');
    expect($rad)->not->toHaveKey('id');
    expect($rad['action'])->toBe('container.transferred');

    expect($rad['user']['ulid'])->toBe($ägare->ulid);
    expect($rad['user']['name'])->toBe($ägare->name);
    expect($rad['user'])->not->toHaveKey('id');
});

/*
 * Ersätter provet "en användare med giltig write-åtkomst får auth.forbidden på
 * loggen" (issue 40 § Beslut 7). Sedan issue 108 är läsregeln ett radfilter i
 * App\Actions\Audit\ListAuditEvents: en delegerad `write`-innehavare passerar
 * grinden och ser sina EGNA rader — men inte ägarens, som ligger i samma
 * container. 403:an var hela loggen; nu är det bara raderna som skiljer.
 */
it('en användare med giltig write-åtkomst ser sina egna rader och inga andras', function () {
    [$account, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $writeAnvändare = User::factory()->create();
    beviljaAccess($container, $writeAnvändare, 'write', 'member');

    loggRad($container, $account, $ägare);
    $egen = loggRad($container, $account, $writeAnvändare);

    $token = $writeAnvändare->createToken('api');
    $writeHeaders = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $response = getJson("/api/containers/{$container->ulid}/audit-log", $writeHeaders);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.ulid'))->toBe($egen->ulid);
});

it('ett read_only ägarkonto kan läsa sin logg', function () {
    [$account, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    loggRad($container, $account, $ägare);
    $account->update(['status' => 'read_only']);

    $response = getJson("/api/containers/{$container->ulid}/audit-log", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('listningen svarar med nyaste raden först och håller stabil ordning för rader i samma transaktion', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // Frys tiden så de två raderna får identisk created_at — villkoret som
    // gör id-sorteringen till den avgörande. Samma teknik som AcceptTest
    // (Carbon direkt, ingen travelTo).
    Carbon::setTestNow(now());

    DB::transaction(function () use ($container, $account): void {
        loggRad($container, $account, null, ['meta' => ['marker' => 'först']]);
        loggRad($container, $account, null, ['meta' => ['marker' => 'senare']]);
    });

    $response = getJson("/api/containers/{$container->ulid}/audit-log", $headers);

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0]['meta']['marker'])->toBe('senare');
    expect($data[1]['meta']['marker'])->toBe('först');

    Carbon::setTestNow();
});

it('svaret bär aldrig id för subjektet — subject_id är en ULID, inte ett löpnummer', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $access = beviljaAccess($container, User::factory()->create(), 'read', 'guest');
    loggRad($container, $account, null, [
        'action' => AuditLog::ACTION_ACCESS_REVOKED,
        'subject_type' => 'container_access',
        'subject_id' => $access->ulid,
    ]);

    $response = getJson("/api/containers/{$container->ulid}/audit-log", $headers);

    $response->assertOk();
    $rad = $response->json('data.0');
    expect($rad['subject_id'])->toBe($access->ulid);
    expect($rad['subject_id'])->toBeString();
    expect(strlen($rad['subject_id']))->toBe(26);
    expect($rad)->not->toHaveKey('id');
});

it('det finns ingen rutt som ändrar eller raderar en loggrad', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    loggRad($container, $account);

    $response = deleteJson("/api/containers/{$container->ulid}/audit-log", [], $headers);

    $response->assertStatus(405);
    expect($response->json('error.code'))->toBe('resource.method_not_allowed');
});
