<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 72 · Beviljande, ändring och återkallande av itemåtkomst. Se
 * App\Http\Controllers\Api\ContainerAccessController,
 * App\Http\Requests\ContainerAccess\StoreContainerAccessRequest,
 * App\Http\Requests\ContainerAccess\UpdateContainerAccessRequest,
 * App\Http\Resources\ContainerAccessResource,
 * App\Actions\Access\ResolveItemScope::reach() och
 * App\Support\Plan\Entitlements::assertCanShareContainer().
 *
 * Fixturen är ADR-0028:s: en motor med tre ättlingar (impeller, impellerns
 * eget barn lager, och packning) plus ett relaterat par (motorn och drevet).
 *
 *   motor ── impeller ── lager
 *     └──── packning
 *   motor  related  drev
 *
 * kontoMedMedlem(), beviljaAccess() och sättPlangräns() är globala
 * testhjälpare i tests/Support/Testhjalpare.php. Hjälparna nedan är
 * fil-lokala med flit — samma skäl som Testhjalpare.php:s docblock
 * beskriver: en hjälpare som bara den här filen använder hör hemma här.
 */

/**
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function atkomstKonto(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $headers, $container];
}

function atkomstItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

function atkomstKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function atkomstGrant(
    Container $container,
    User $grantee,
    string $level,
    ?Item $item = null,
    ?Carbon $expiresAt = null,
    ?Carbon $revokedAt = null,
): ContainerAccess {
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $grantee->id,
        'level' => $level,
        'kind' => 'member',
        'expires_at' => $expiresAt,
        'revoked_at' => $revokedAt,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * @return array<string, mixed>
 */
function atkomstKropp(User $mottagare, string $level = 'read', ?Item $item = null): array
{
    $kropp = [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => $level,
        'kind' => 'member',
    ];

    if ($item !== null) {
        $kropp['item'] = $item->ulid;
    }

    return $kropp;
}

it('skapar en itemrad när item är satt och svarar med itemets ULID', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $mottagare = User::factory()->create();

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($mottagare, 'write', $motor),
        $headers,
    );

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'item' => $motor->ulid,
            'reach' => 1,
            'level' => 'write',
            'grantee' => $mottagare->ulid,
        ],
    ]);

    $rad = DB::table('container_access')->where('container_id', $container->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->item_id)->toBe($motor->id);
});

it('skapar en container-bred rad när item utelämnas, precis som före issuen', function () {
    [, , $headers, $container] = atkomstKonto();
    $mottagare = User::factory()->create();

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($mottagare),
        $headers,
    );

    $response->assertCreated();
    $response->assertJson(['data' => ['item' => null, 'reach' => null]]);

    $rad = DB::table('container_access')->where('container_id', $container->id)->first();
    expect($rad->item_id)->toBeNull();
});

it('avvisar en item-ULID ur en annan container som ett valideringsfel', function () {
    [, , $headers, $container] = atkomstKonto();
    $främmande = Item::factory()->for(Container::factory(), 'container')->create();
    $mottagare = User::factory()->create();

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($mottagare, 'read', $främmande),
        $headers,
    );

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.item.0.code'))->toBe('validation.exists');

    // Aldrig 404, och aldrig en tyst container-bred grant.
    expect(DB::table('container_access')->where('container_id', $container->id)->count())->toBe(0);
});

it('avvisar ett mjukraderat item som ett valideringsfel', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $motor->delete();
    $mottagare = User::factory()->create();

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($mottagare, 'read', $motor),
        $headers,
    );

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.item.0.code'))->toBe('validation.exists');

    expect(DB::table('container_access')->where('container_id', $container->id)->count())->toBe(0);
});

it('accepterar create och delete som nivå på åtkomstytan', function (string $nivå) {
    [, , $headers, $container] = atkomstKonto();

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp(User::factory()->create(), $nivå),
        $headers,
    );

    $response->assertCreated();
    expect($response->json('data.level'))->toBe($nivå);
})->with(['create', 'delete']);

it('avvisar två giltiga rader för samma container, item och mottagare', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $mottagare = User::factory()->create();
    $befintlig = atkomstGrant($container, $mottagare, 'read', $motor);

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($mottagare, 'write', $motor),
        $headers,
    );

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('container_access.already_granted');
    expect($response->json('error.data.access'))->toBe($befintlig->ulid);
    expect(DB::table('container_access')->where('container_id', $container->id)->count())->toBe(1);
});

it('tillåter en container-bred rad och en itemrad för samma mottagare', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $mottagare = User::factory()->create();
    atkomstGrant($container, $mottagare, 'read');

    // Två rader för SAMMA mottagare är en mottagare i taket — men
    // free-planens tak är 1, så platsen måste rymma den här granten.
    sättPlangräns('free', 'shared_users_per_container', 2);

    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($mottagare, 'write', $motor),
        $headers,
    );

    $response->assertCreated();
    expect(DB::table('container_access')->where('container_id', $container->id)->count())->toBe(2);
});

it('låter PATCH ändra level och expires_at', function () {
    [, , $headers, $container] = atkomstKonto();
    $access = atkomstGrant($container, User::factory()->create(), 'read');

    $utgång = now()->addMonth()->startOfSecond();

    $response = patchJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [
        'level' => 'write',
        'expires_at' => $utgång->toIso8601String(),
    ], $headers);

    $response->assertOk();
    $response->assertJson(['data' => ['ulid' => $access->ulid, 'level' => 'write']]);

    $access->refresh();
    expect($access->level)->toBe('write');
    expect($access->expires_at->equalTo($utgång))->toBeTrue();
});

it('avvisar PATCH med item, grantee, grantee_type eller kind', function (string $fält, mixed $värde) {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $mottagare = User::factory()->create();
    $access = atkomstGrant($container, $mottagare, 'read');

    $response = patchJson(
        "/api/containers/{$container->ulid}/accesses/{$access->ulid}",
        ['level' => 'write', $fält => $värde],
        $headers,
    );

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json("error.data.fields.{$fält}"))->not->toBeNull();

    // Värdet ändras inte och ignoreras inte tyst.
    $access->refresh();
    expect($access->level)->toBe('read');
    expect($access->item_id)->toBeNull();
})->with([
    'item' => ['item', 'Motor'],
    'grantee' => ['grantee', 'ULID'],
    'grantee_type' => ['grantee_type', 'account'],
    'kind' => ['kind', 'guest'],
]);

it('avvisar PATCH mot en återkallad rad', function () {
    [, , $headers, $container] = atkomstKonto();
    $access = atkomstGrant($container, User::factory()->create(), 'read', revokedAt: now());

    $response = patchJson(
        "/api/containers/{$container->ulid}/accesses/{$access->ulid}",
        ['level' => 'write'],
        $headers,
    );

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('container_access.revoked');
    expect($access->fresh()->level)->toBe('read');
});

it('avvisar PATCH mot en utgången rad', function () {
    [, , $headers, $container] = atkomstKonto();
    $access = atkomstGrant($container, User::factory()->create(), 'read', expiresAt: now()->subDay());

    $response = patchJson(
        "/api/containers/{$container->ulid}/accesses/{$access->ulid}",
        ['level' => 'write'],
        $headers,
    );

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('container_access.revoked');
    expect($access->fresh()->level)->toBe('read');
});

it('nekar en write-mottagare att bevilja, ändra och återkalla åtkomster', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');
    $annan = beviljaAccess($container, User::factory()->create(), 'read', 'guest');

    $bevilja = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp(User::factory()->create()),
        $headers,
    );
    $bevilja->assertStatus(403);
    expect($bevilja->json('error.code'))->toBe('auth.forbidden');

    $ändra = patchJson(
        "/api/containers/{$container->ulid}/accesses/{$annan->ulid}",
        ['level' => 'write'],
        $headers,
    );
    $ändra->assertStatus(403);
    expect($ändra->json('error.code'))->toBe('auth.forbidden');

    $återkalla = deleteJson(
        "/api/containers/{$container->ulid}/accesses/{$annan->ulid}",
        [],
        $headers,
    );
    $återkalla->assertStatus(403);
    expect($återkalla->json('error.code'))->toBe('auth.forbidden');

    expect($annan->fresh()->revoked_at)->toBeNull();
});

it('nekar en omfångsbegränsad mottagare att se förvaltningsvyn', function () {
    [, , , $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');

    [, $mottagare, $mottagarHeaders] = kontoMedMedlem();
    atkomstGrant($container, $mottagare, 'read', $motor);

    $response = getJson("/api/containers/{$container->ulid}/accesses", $mottagarHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('räknar reach för en grant på motorn och dess ättlingar', function () {
    [, , $headers, $container] = atkomstKonto();
    [$motor, $impeller, $lager, $packning] = [
        atkomstItem($container, 'Motor'),
        atkomstItem($container, 'Impeller'),
        atkomstItem($container, 'Lager'),
        atkomstItem($container, 'Packning'),
    ];

    atkomstKant($motor, $impeller);
    atkomstKant($impeller, $lager);
    atkomstKant($motor, $packning);

    $mottagare = User::factory()->create();
    atkomstGrant($container, $mottagare, 'read', $motor);
    atkomstGrant($container, User::factory()->create(), 'read', $lager);
    atkomstGrant($container, User::factory()->create(), 'read');

    $response = getJson("/api/containers/{$container->ulid}/accesses", $headers);

    $response->assertOk();

    $rader = collect($response->json('data'));

    // motorn når sig själv + impellern + lagret + packningen.
    expect($rader->firstWhere('item', $motor->ulid)['reach'])->toBe(4);
    // ett item utan barn når bara sig självt.
    expect($rader->firstWhere('item', $lager->ulid)['reach'])->toBe(1);
    // en container-bred rad har inget reach alls.
    expect($rader->firstWhere('item', null)['reach'])->toBeNull();
});

it('räknar inte ett relaterat item i reach', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $drev = atkomstItem($container, 'Drev');

    ItemLink::query()->insert([
        'from_item_id' => min($motor->id, $drev->id),
        'to_item_id' => max($motor->id, $drev->id),
        'relation' => 'related',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    atkomstGrant($container, User::factory()->create(), 'read', $motor);

    $response = getJson("/api/containers/{$container->ulid}/accesses", $headers);

    $response->assertOk();
    expect($response->json('data.0.reach'))->toBe(1);
});

it('kostar samma antal frågor med tjugo åtkomstrader som med en', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    atkomstGrant($container, User::factory()->create(), 'read', $motor);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver
    // deterministiskt (issue 80), samma grepp som ContainerAtkomstApiTest.
    Carbon::setTestNow(now());

    // "Värm" Sanctum-guarden med ett omätt anrop innan mätningen börjar.
    getJson("/api/containers/{$container->ulid}/accesses", $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson("/api/containers/{$container->ulid}/accesses", $headers);
    $frågorMedEnRad = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(1);

    for ($i = 0; $i < 19; $i++) {
        atkomstGrant($container, User::factory()->create(), 'read', $motor);
    }

    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson("/api/containers/{$container->ulid}/accesses", $headers);
    $frågorMedTjugoRader = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(20);
    expect($frågorMedTjugoRader)->toBe($frågorMedEnRad);

    Carbon::setTestNow();
});

it('räknar en mottagare med fyra itemåtkomster som en i delningstaket', function () {
    [, , $headers, $container] = atkomstKonto();
    sättPlangräns('free', 'shared_users_per_container', 2);

    $mottagare = User::factory()->create();

    foreach (['Motor', 'Impeller', 'Lager', 'Packning'] as $namn) {
        atkomstGrant($container, $mottagare, 'read', atkomstItem($container, $namn));
    }

    // Fyra rader, men EN distinkt mottagare — platsen är alltså fri.
    $ny = User::factory()->create();
    postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp($ny),
        $headers,
    )->assertCreated();

    // Först nu, vid TVÅ distinkta mottagare, fälls taket.
    $response = postJson(
        "/api/containers/{$container->ulid}/accesses",
        atkomstKropp(User::factory()->create()),
        $headers,
    );

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.shared_users_exceeded');
    // `used` är det distinkta talet som faktiskt jämförs — inte antalet rader.
    expect($response->json('error.data'))->toBe(['limit' => 2, 'used' => 2]);
});

it('visar en mottagare med fyra itemåtkomster en gång i deltagarlistan', function () {
    [, , $headers, $container] = atkomstKonto();
    $mottagare = User::factory()->create();

    foreach (['Motor', 'Impeller', 'Lager', 'Packning'] as $namn) {
        atkomstGrant($container, $mottagare, 'read', atkomstItem($container, $namn));
    }

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();

    // Ägarkontot som en post, mottagaren som en — inte fyra.
    expect($response->json('data'))->toHaveCount(2);

    $post = collect($response->json('data'))->firstWhere('ulid', $mottagare->ulid);
    expect($post)->not->toBeNull();
    expect($post)->not->toHaveKeys(['item', 'reach', 'level']);
});

it('loggar itemets ULID i meta när en itemåtkomst återkallas', function () {
    [, , $headers, $container] = atkomstKonto();
    $motor = atkomstItem($container, 'Motor');
    $access = atkomstGrant($container, User::factory()->create(), 'read', $motor);

    deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers)->assertNoContent();

    $meta = AuditLog::where('action', AuditLog::ACTION_ACCESS_REVOKED)->firstOrFail()->meta;
    expect($meta['item'])->toBe($motor->ulid);
});

it('loggar item: null när en container-bred åtkomst återkallas', function () {
    [, , $headers, $container] = atkomstKonto();
    $access = atkomstGrant($container, User::factory()->create(), 'read');

    deleteJson("/api/containers/{$container->ulid}/accesses/{$access->ulid}", [], $headers)->assertNoContent();

    $meta = AuditLog::where('action', AuditLog::ACTION_ACCESS_REVOKED)->firstOrFail()->meta;
    expect($meta)->toHaveKey('item');
    expect($meta['item'])->toBeNull();
});
