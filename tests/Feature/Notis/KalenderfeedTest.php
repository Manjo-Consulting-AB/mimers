<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 36a · ICS-kalenderfeed, första halvan (nyckeln och ytan). Se
 * App\Http\Controllers\Api\CalendarFeedController,
 * App\Http\Resources\CalendarFeedResource och App\Models\CalendarFeed.
 *
 * Själva feeden — rutten som svarar med text/calendar och skriver
 * last_fetched_at — är issue 36b och testas inte här.
 *
 * kontoMedMedlem() är deklarerad i
 * tests/Feature/Container/ContainerCrudTest.php och beviljaAccess() i
 * tests/Feature/Container/ContainerAtkomstTest.php — Pests globala
 * namnrymd gör dem åtkomliga rakt av här, samma mönster som InbjudanTest.
 *
 * Behörigheten är 8:s view()-policy oförändrad
 * (App\Policies\ContainerPolicy::view()), så behörighetstesterna nedan
 * bevisar bara att KALENDERFEEDRUTTERNA hänger på rätt grind, inte
 * policyn i sig.
 *
 * "Klart när" (KalenderfeedTest):
 * - en deltagare skapar en feed och får en url med token
 * - klartexten sparas aldrig — kolumnen bär en sha256-hash, inte token
 * - listan visar aldrig token eller hash
 * - en gäst med läsrätt får skapa en feed
 * - en användare utan åtkomst nekas
 * - listan visar bara den egna användarens feeder
 * - samma användare får ha två feeder till samma container
 * - en feed går att återkalla
 * - ett återkallande är idempotent
 * - en feed i en annan container går inte att återkalla
 * - en oinloggad begäran avvisas
 * - en purgad container tar med sig sina feeder
 * - ett raderat konto går att radera med feeder i containern
 */

/**
 * Skapar en calendar_feed-rad direkt, förbi API:et — för de tester som
 * behöver ett utgångsläge (en annan deltagares feed, en återkallad rad)
 * rutten aldrig producerar i sig. `token_hash` genereras av fabriken som
 * en sha256-hex av en kastad slump, precis som produktionsvägen.
 */
function kalenderfeedRad(Container $container, User $user, array $attribut = []): CalendarFeed
{
    return CalendarFeed::factory()->create(array_merge([
        'container_id' => $container->id,
        'user_id' => $user->id,
    ], $attribut));
}

/**
 * Plockar ut klartexttokenet ur en feed-URL av Beslut 6:s form
 * `{app.url}/kalender/{token}.ics`.
 */
function kalenderfeedUrlToken(string $url): string
{
    if (! preg_match('#/kalender/([A-Za-z0-9]{64})\.ics$#', $url, $träffar)) {
        throw new RuntimeException("Kunde inte tolka feed-URL:en: {$url}");
    }

    return $träffar[1];
}

/**
 * Kör PurgeContainer precis som papperskorgens gallring gör (20c).
 */
function kalenderfeedPurge(Container $container): void
{
    (new PurgeContainer(new PurgeContent(new PurgeAttachment)))->handle($container);
}

it('en deltagare skapar en feed och får en url med token', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers);

    $response->assertCreated();

    // URL:en är Beslut 6:s kontrakt: {app.url}/kalender/{token}.ics — svenska
    // i sökvägen och .ics på slutet, se kontrollerns docblock.
    $url = $response->json('url');
    $prefix = rtrim((string) config('app.url'), '/').'/kalender/';
    expect($url)->toBeString();
    expect($url)->toStartWith($prefix);
    expect($url)->toEndWith('.ics');

    $token = kalenderfeedUrlToken($url);
    expect(strlen($token))->toBe(64);

    $rad = DB::table('calendar_feed')->where('container_id', $container->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->user_id)->toBe($user->id);
    expect($rad->revoked_at)->toBeNull();

    // Löpnumret exponeras aldrig, se issue 8 § Beslut 7.
    expect($response->json('data.id'))->toBeNull();
    expect($response->json('data.ulid'))->toBeString();
});

it('klartexten sparas aldrig', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers);

    $response->assertCreated();
    $token = kalenderfeedUrlToken($response->json('url'));

    $rad = DB::table('calendar_feed')->where('container_id', $container->id)->first();
    expect($rad->token_hash)->toMatch('/^[0-9a-f]{64}$/');
    expect($rad->token_hash)->toBe(hash('sha256', $token));
    expect($rad->token_hash)->not->toBe($token);

    // Klartexten finns ingenstans i databasen — inte ens som ett delvärde i
    // en annan kolumn (de enda kolumnerna är id, ulid, container_id,
    // user_id, token_hash, revoked_at, last_fetched_at och tidsstämplarna).
    expect(DB::table('calendar_feed')->where('token_hash', $token)->exists())->toBeFalse();
});

it('listan visar aldrig token eller hash', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    kalenderfeedRad($container, $user);
    kalenderfeedRad($container, $user, ['revoked_at' => now()]);

    $response = getJson("/api/containers/{$container->ulid}/calendar-feeds", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    // Varken token, token_hash eller url får vara ett fält — och inget
    // värde i kroppen får vara så långt som en klartext (64 tecken ur
    // Str::random()s alfabet) eller en sha256-hex (issue 36a § Att se upp
    // med, samma kontroll som InbjudanTest gör).
    $kropp = $response->getContent();
    expect($kropp)->not->toMatch('/[A-Za-z0-9]{64}/');

    foreach ($response->json('data') as $feed) {
        expect(array_keys($feed))->not->toContain('token');
        expect(array_keys($feed))->not->toContain('token_hash');
        expect(array_keys($feed))->not->toContain('url');
    }

    // En återkallad feed listas som en vanlig rad med sitt revoked_at ifyllt
    // (Beslut 3) — den raderas aldrig och göms inte.
    $återkallade = collect($response->json('data'))->filter(fn (array $feed) => $feed['revoked_at'] !== null);
    expect($återkallade)->toHaveCount(1);
});

it('en gäst med läsrätt får skapa en feed', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $response = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers);

    $response->assertCreated();
    expect(DB::table('calendar_feed')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect(DB::table('calendar_feed')->count())->toBe(0);
});

it('listan visar bara den egna användarens feeder', function () {
    [$account, $användareA, $headersA] = kontoMedMedlem();
    $användareB = User::factory()->create();
    $account->users()->attach($användareB, ['role' => 'owner']);
    $container = Container::factory()->for($account, 'account')->create();
    $feedA1 = kalenderfeedRad($container, $användareA);
    $feedA2 = kalenderfeedRad($container, $användareA);
    $feedB = kalenderfeedRad($container, $användareB);

    $response = getJson("/api/containers/{$container->ulid}/calendar-feeds", $headersA);

    $response->assertOk();
    $ulidLista = collect($response->json('data'))->pluck('ulid');
    expect($ulidLista)->toHaveCount(2);
    expect($ulidLista)->toContain($feedA1->ulid);
    expect($ulidLista)->toContain($feedA2->ulid);
    expect($ulidLista)->not->toContain($feedB->ulid);
});

it('samma användare får ha två feeder till samma container', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $första = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers);
    $andra = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers);

    $första->assertCreated();
    $andra->assertCreated();
    expect($andra->json('data.ulid'))->not->toBe($första->json('data.ulid'));
    expect(DB::table('calendar_feed')->where('container_id', $container->id)->where('user_id', $user->id)->count())
        ->toBe(2);
});

it('en feed går att återkalla', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $ulid = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers)->json('data.ulid');

    $response = deleteJson("/api/containers/{$container->ulid}/calendar-feeds/{$ulid}", [], $headers);

    $response->assertNoContent();
    $rad = DB::table('calendar_feed')->where('ulid', $ulid)->first();
    expect($rad)->not->toBeNull();
    expect($rad->revoked_at)->not->toBeNull();
});

it('ett återkallande är idempotent', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $ulid = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers)->json('data.ulid');

    deleteJson("/api/containers/{$container->ulid}/calendar-feeds/{$ulid}", [], $headers)->assertNoContent();
    $efterFörsta = DB::table('calendar_feed')->where('ulid', $ulid)->value('revoked_at');

    deleteJson("/api/containers/{$container->ulid}/calendar-feeds/{$ulid}", [], $headers)->assertNoContent();

    // Andra DELETE:en skriver inte om den ursprungliga tidsstämpeln — den är
    // historien (Beslut 3, samma som ContainerAccessController::destroy).
    $efterAndra = DB::table('calendar_feed')->where('ulid', $ulid)->value('revoked_at');
    expect($efterAndra)->toBe($efterFörsta);
});

it('en feed i en annan container går inte att återkalla', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $feedB = kalenderfeedRad($containerB, $user);

    $response = deleteJson("/api/containers/{$containerA->ulid}/calendar-feeds/{$feedB->ulid}", [], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
    expect(DB::table('calendar_feed')->where('ulid', $feedB->ulid)->value('revoked_at'))->toBeNull();
});

it('en oinloggad begäran avvisas', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/calendar-feeds");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('en purgad container tar med sig sina feeder', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    kalenderfeedRad($container, $user);
    $container->delete();

    kalenderfeedPurge($container);

    expect(DB::table('calendar_feed')->count())->toBe(0);
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
});

it('ett raderat konto går att radera med feeder i containern', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    kalenderfeedRad($container, $user);

    (new DeleteAccount(new PurgeContainer(new PurgeContent(new PurgeAttachment))))->handle($account);

    expect(DB::table('account')->where('id', $account->id)->exists())->toBeFalse();
    expect(DB::table('container')->where('id', $container->id)->exists())->toBeFalse();
    expect(DB::table('calendar_feed')->count())->toBe(0);
});
