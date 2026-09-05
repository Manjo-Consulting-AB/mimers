<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Notification\UrlSafetyValidator;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 37a · Webhooks, registret — plangrinden, behörigheten och
 * SSRF-valideringen vid registrering. Se
 * App\Http\Controllers\Api\WebhookEndpointController och
 * App\Support\Notification\UrlSafetyValidator.
 *
 * Själva leveransen — HMAC-signaturen, omförsöken och SSRF-kontrollen vid
 * varje leverans — är issue 37b och testas inte här.
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php — Pests globala namnrymd gör
 * den åtkomlig rakt av här, samma mönster som KalenderfeedTest. Ett konto
 * med rollen 'member' som enda medlem är orealistiskt, men precis vad
 * behörighetstesterna behöver: grinden läser bara medlemskapet och rollen.
 *
 * SSRF-testerna slår aldrig upp riktiga värdnamn (issue 37a § Att se upp
 * med): alla utom ett använder IP-litteraler som inte kräver DNS, och det
 * enda värdnamnstestet injicerar en resolver i stället för att fråga nätet.
 *
 * "Klart när" (WebhookRegistreringTest):
 * - en ägare i ett prokonto registrerar en endpoint
 * - hemligheten visas bara vid skapandet
 * - hemligheten är krypterad i kolumnen
 * - ett gratiskonto nekas
 * - ett gratiskonto får ändå lista och ta bort sina endpoints
 * - en member nekas
 * - en member i ett gratiskonto får auth_forbidden, inte plankoden
 * - en utomstående nekas
 * - http avvisas
 * - localhost avvisas
 * - en privat ip avvisas
 * - ipv6-loopback avvisas
 * - molnets metadataadress avvisas
 * - ett värdnamn som pekar på en privat ip avvisas
 * - en avvikande port avvisas
 * - en tom event_types avvisas
 * - en okänd notistyp i event_types avvisas
 * - en endpoint i ett annat konto går inte att ändra
 * - en återaktivering nollställer consecutive_failures
 * - ett raderat konto tar med sig sina endpoints
 */

/**
 * Ett prokonto med en ägare, plus ett Sanctum-headerpar för ägaren — det
 * vanliga utgångsläget för registreringstesterna. Free-planen kräver ingen
 * fixture (planraderna kommer ur migrationen, issue 25 § Beslut 2).
 *
 * @return array{0: Account, 1: User, 2: array<string, string>} [$account, $user, $headers]
 */
function webhookProKonto(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account)->for($pro)->create();

    return [$account, $user, $headers];
}

/**
 * Skapar en webhook_endpoint-rad direkt, förbi API:et — för de tester som
 * behöver ett utgångsläge rutten aldrig producerar (en inaktiv endpoint, en
 * rad i fel konto). `secret` krypteras av modellens cast vid sparning.
 */
function webhookRad(Account $account, array $attribut = []): WebhookEndpoint
{
    return WebhookEndpoint::factory()->for($account, 'account')->create($attribut);
}

it('en ägare i ett prokonto registrerar en endpoint', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE, Notification::TYPE_QUOTA_WARNING],
    ], $headers);

    $response->assertCreated();

    // Beslut 2: hemligheten visas i klartext, en gång, i POST-svaret.
    $secret = $response->json('secret');
    expect($secret)->toBeString();
    expect(strlen($secret))->toBe(64);

    expect($response->json('data.url'))->toBe('https://example.com/notiser');
    expect($response->json('data.event_types'))->toBe([Notification::TYPE_TASK_DUE, Notification::TYPE_QUOTA_WARNING]);
    expect($response->json('data.is_active'))->toBeTrue();
    expect($response->json('data.ulid'))->toBeString();
    // Löpnumret exponeras aldrig, se issue 8 § Beslut 7.
    expect($response->json('data.id'))->toBeNull();

    $rad = DB::table('webhook_endpoint')->where('account_id', $account->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->account_id)->toBe($account->id);
    expect($rad->is_active)->toBe(1);
});

it('hemligheten visas bara vid skapandet', function () {
    [$account, , $headers] = webhookProKonto();

    $första = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/forsta',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);
    $första->assertCreated();
    $secret = $första->json('secret');

    postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/andra',
        'event_types' => [Notification::TYPE_TASK_OVERDUE],
    ], $headers)->assertCreated();

    $lista = getJson("/api/accounts/{$account->ulid}/webhooks", $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(2);

    // Varken klartexten (64 tecken ur Str::random()s alfabet) eller något
    // `secret`-fält får finnas i GET-svaret — samma helkroppskontroll som
    // KalenderfeedTest gör för token.
    expect($lista->getContent())->not->toContain($secret);
    foreach ($lista->json('data') as $endpoint) {
        expect(array_keys($endpoint))->not->toContain('secret');
    }

    $patch = patchJson("/api/accounts/{$account->ulid}/webhooks/{$första->json('data.ulid')}", [
        'is_active' => true,
    ], $headers);
    $patch->assertOk();
    expect($patch->json('data.secret'))->toBeNull();
    expect($patch->json('secret'))->toBeNull();
    expect($patch->getContent())->not->toContain($secret);
});

it('hemligheten är krypterad i kolumnen', function () {
    [$account] = webhookProKonto();
    $klartext = 'hemlig-'.str_repeat('x', 56); // 64 tecken

    $endpoint = webhookRad($account, ['secret' => $klartext]);

    // Läsning genom modellen ger tillbaka samma klartext som skrevs —
    // 'encrypted'-casten rullar av kuvertet (Beslut 1).
    expect($endpoint->secret)->toBe($klartext);

    // Råvärdet i databasen är inte klartexten.
    $råVärde = DB::table('webhook_endpoint')->where('id', $endpoint->id)->value('secret');
    expect($råVärde)->not->toBe($klartext);
    expect($råVärde)->not->toBeNull();
});

it('ett gratiskonto nekas', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('plan.feature_unavailable');
    expect($response->json('error.data'))->toBe(['feature' => 'webhooks']);
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
});

it('ett gratiskonto får ändå lista och ta bort sina endpoints', function () {
    [$account, , $headers] = kontoMedMedlem();
    webhookRad($account);
    webhookRad($account);

    $lista = getJson("/api/accounts/{$account->ulid}/webhooks", $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(2);

    $ulid = $lista->json('data.0.ulid');
    $svar = deleteJson("/api/accounts/{$account->ulid}/webhooks/{$ulid}", [], $headers);
    $svar->assertNoContent();

    expect(DB::table('webhook_endpoint')->where('account_id', $account->id)->count())->toBe(1);
});

it('en member nekas', function () {
    [$account, , $headers] = kontoMedMedlem('member');
    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account)->for($pro)->create();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
});

it('en member i ett gratiskonto får auth_forbidden, inte plankoden', function () {
    // Ordningen mellan grindarna (Beslut 5): behörighet först, plan sedan.
    // Körde plangrinden före Gate::authorize() skulle en member i ett
    // gratiskonto få plan.feature_unavailable och därmed avslöja kontots plan.
    [$account, , $headers] = kontoMedMedlem('member');

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en utomstående nekas', function () {
    [$ägarkonto] = kontoMedMedlem();
    [, , $inkräktareHeaders] = kontoMedMedlem();

    $response = postJson("/api/accounts/{$ägarkonto->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('http avvisas', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'http://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('invalid_scheme');
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
});

it('localhost avvisas', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://localhost/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_hostname');
});

it('en privat ip avvisas', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://10.0.0.1/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_ip');
});

it('ipv6-loopback avvisas', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://[::1]/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_ip');
});

it('molnets metadataadress avvisas', function () {
    // 169.254.169.254 är den enda adressen i listan där ett fel har en direkt
    // konsekvens — instansens autentiseringsuppgifter (issue 37a § Beslut 6).
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://169.254.169.254/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_ip');
});

it('en nat64-adress som bäddar in en privat ip avvisas', function () {
    // 64:ff9b::/96 bäddar in IPv4 i de sista 32 bitarna — den hexadecimala
    // formen 64:ff9b::a9fe:a9fe ÄR 169.254.169.254 (molnets metadatatjänst)
    // men passerar FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE, så den avkodas och
    // avvisas uttryckligen (granskningen av PR #206).
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://[64:ff9b::a9fe:a9fe]/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_ip');
});

it('en 6to4-adress som bäddar in en privat ip avvisas', function () {
    // 2002::/16 bär IPv4 i bitarna 16–48 — 2002:a9fe:a9fe:: ÄR
    // 169.254.169.254, av samma skäl som NAT64-testet ovan.
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://[2002:a9fe:a9fe::]/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_ip');
});

it('ett värdnamn som pekar på en privat ip avvisas', function () {
    // DNS-uppslag slås aldrig upp mot nätet i testsviten — resolvern
    // injiceras (issue 37a § Att se upp med).
    [$account, , $headers] = webhookProKonto();

    app()->instance(UrlSafetyValidator::class, new UrlSafetyValidator(
        fn (string $host): array => $host === 'privat.exempel.se' ? ['10.0.0.1'] : [],
    ));

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://privat.exempel.se/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('reserved_ip');
});

it('en avvikande port avvisas', function () {
    // Porten prövas före DNS-uppslaget, så testet rör aldrig nätet.
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com:8080/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('webhook.unsafe_url');
    expect($response->json('error.data.reason'))->toBe('invalid_port');
});

it('en tom event_types avvisas', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => [],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    // En tom array är "empty" för required-regeln, så den slår före min:1.
    expect($response->json('error.data.fields.event_types.0.code'))->toBe('validation.required');
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
});

it('en okänd notistyp i event_types avvisas', function () {
    [$account, , $headers] = webhookProKonto();

    $response = postJson("/api/accounts/{$account->ulid}/webhooks", [
        'url' => 'https://example.com/notiser',
        'event_types' => ['task.finns-inte'],
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    // Fältnyckeln är "event_types.0" — en bokstavlig punkt i nyckeln, så den
    // nås inte med data_get-punktnotation (se App\Support\Api\ValidationErrorMapper).
    $fields = $response->json('error.data.fields');
    expect($fields)->toHaveKey('event_types.0');
    expect($fields['event_types.0'][0]['code'])->toBe('validation.in');
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
});

it('en endpoint i ett annat konto går inte att ändra', function () {
    // scopeBindings() löser {webhook} inom {account} — en endpoint-ULID från
    // konto B via konto A:s rutt ger 404, inte en ändring (Beslut 3).
    [$kontoA, , $headers] = kontoMedMedlem();
    $kontoB = Account::factory()->create();
    $endpointB = webhookRad($kontoB);

    $response = patchJson("/api/accounts/{$kontoA->ulid}/webhooks/{$endpointB->ulid}", [
        'is_active' => false,
    ], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');

    $endpointB->refresh();
    expect($endpointB->is_active)->toBeTrue();
});

it('en återaktivering nollställer consecutive_failures', function () {
    [$account, , $headers] = webhookProKonto();
    $endpoint = webhookRad($account, ['is_active' => false, 'consecutive_failures' => 6]);

    $response = patchJson("/api/accounts/{$account->ulid}/webhooks/{$endpoint->ulid}", [
        'is_active' => true,
    ], $headers);

    $response->assertOk();

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeTrue();
    expect($endpoint->consecutive_failures)->toBe(0);
});

it('ett raderat konto tar med sig sina endpoints', function () {
    [$account] = kontoMedMedlem();
    webhookRad($account);

    (new DeleteAccount(new PurgeContainer(new PurgeContent(new PurgeAttachment))))->handle($account);

    expect(DB::table('account')->where('id', $account->id)->exists())->toBeFalse();
    expect(DB::table('webhook_endpoint')->count())->toBe(0);
});
