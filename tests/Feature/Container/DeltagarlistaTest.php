<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 9c · Deltagarlista: vilka som har åtkomst till containern just nu,
 * läsbar för VARJE deltagare — inte bara för ägarkontot. Se
 * App\Http\Controllers\Api\ContainerParticipantController,
 * App\Http\Resources\ParticipantResource och [[Konton och åtkomst]] §
 * Behörighetsregler, stycket "Att hantera åtkomster och att se dem är två
 * olika saker".
 *
 * Förvaltningsvyn (nivåer, utgångsdatum, vem som beviljade, återkallad
 * historik, obesvarade inbjudningar) är 9b:s och 10a:s och testas i
 * ContainerAtkomstApiTest.php respektive InbjudanTest.php — inget av det
 * prövas om här. Det som prövas här är motsatsen: att inget av det läcker
 * ut i den här listan.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * "Klart när" (DeltagarlistaTest):
 * - ägarkontot står alltid först i listan
 * - en inbjuden deltagare ser de andra deltagarna
 * - en användare utan åtkomst nekas
 * - oautentiserad begäran ger 401
 * - en managed-organisation räknas som en deltagare
 * - både användarposter och kontoposter bär namnet under samma nyckel
 * - svaret innehåller aldrig en e-postadress
 * - återkallade deltagare syns inte
 * - utgångna deltagare syns inte
 * - en deltagare utan expires_at syns
 * - svaret röjer aldrig level, expires_at, granted_by eller revoked_at
 * - svaret röjer aldrig obesvarade inbjudningar
 * - samma person via egen rad och via ett managed-konto ger två poster
 * - deltagarlistan gör inte en fråga per deltagare
 *
 * Punkten "scopeValidFor beter sig oförändrat efter utbrytningen"
 * (§ Beslut 5) har ingen egen testfil med flit: den bevisas av att
 * ContainerAtkomstTest.php och ContainerAtkomstApiTest.php går igenom
 * ORÖRDA — en ny formulering av samma villkor här hade bevisat noll.
 */

/**
 * En container som ägs av ett konto $user INTE är medlem i, plus en giltig
 * åtkomst för $user. Returnerar containern och ägarkontot.
 *
 * @return array{0: Container, 1: Account}
 */
function containerDelatMed(User $user, string $level = 'read', string $kind = 'guest'): array
{
    $ägarkonto = Account::factory()->create();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    beviljaAccess($container, $user, $level, $kind);

    return [$container, $ägarkonto];
}

it('ägarkontot står alltid först i listan', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0'))->toBe([
        'type' => 'account',
        'ulid' => $account->ulid,
        'name' => $account->name,
        'role' => 'owner',
    ]);
});

/*
 * Grinden är `view` och inte `viewAccesses` (§ Beslut 2): en read-guest
 * som får LÄSA containern ska också få veta vem mer som kan. Hade
 * controllern tagit `viewAccesses` hade exakt de deltagare issuen finns för
 * låsts ute — därför är det här testet det viktigaste i filen.
 */
it('en inbjuden deltagare ser de andra deltagarna', function () {
    [, $gäst, $headers] = kontoMedMedlem();
    [$container, $ägarkonto] = containerDelatMed($gäst);

    $sambo = User::factory()->create();
    beviljaAccess($container, $sambo, 'write', 'member');

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data.*.ulid'))
        ->toContain($ägarkonto->ulid)
        ->toContain($gäst->ulid)
        ->toContain($sambo->ulid);
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/participants");

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

/*
 * § Beslut 3: en organisation är EN deltagare, aldrig sina anställda. Att
 * expandera raden vore att lämna ut ett varvs personalregister till en
 * charterkund.
 */
it('en managed-organisation räknas som en deltagare', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $varv = Account::factory()->create();
    $anställda = User::factory()->count(3)->create();
    foreach ($anställda as $anställd) {
        $varv->users()->attach($anställd, ['role' => 'member']);
    }
    beviljaAccess($container, $varv, 'write', 'managed');

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.1'))->toBe([
        'type' => 'account',
        'ulid' => $varv->ulid,
        'name' => $varv->name,
        'role' => 'managed',
    ]);
    foreach ($anställda as $anställd) {
        expect($response->json('data.*.ulid'))->not->toContain($anställd->ulid);
    }
});

/*
 * § Beslut 6: `user.name` och `account.name` ligger under SAMMA nyckel
 * `name`, så klienten inte behöver två avpackningsvägar.
 */
it('både användarposter och kontoposter bär namnet under samma nyckel', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $sambo = User::factory()->create(['name' => 'Alice Bergström']);
    $varv = Account::factory()->create(['name' => 'Sjöbergs varv AB']);
    beviljaAccess($container, $sambo, 'write', 'member');
    beviljaAccess($container, $varv, 'write', 'managed');

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    $poster = collect($response->json('data'))->keyBy('ulid');

    expect($poster[$sambo->ulid])->toMatchArray(['type' => 'user', 'name' => 'Alice Bergström']);
    expect($poster[$varv->ulid])->toMatchArray(['type' => 'account', 'name' => 'Sjöbergs varv AB']);
    expect($poster[$ägarkonto->ulid])->toMatchArray(['type' => 'account', 'name' => $ägarkonto->name]);
});

/*
 * § Beslut 6, den viktigaste raden i hela issuen: INGEN e-postadress lämnar
 * rutten. Söker igenom hela svarskroppen som text i stället för att titta
 * på enskilda nycklar — en adress som smyger in under ett annat fältnamn
 * ska fångas lika säkert.
 */
it('svaret innehåller aldrig en e-postadress', function () {
    [$ägarkonto, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $sambo = User::factory()->create();
    $varv = Account::factory()->create();
    $anställd = User::factory()->create();
    $varv->users()->attach($anställd, ['role' => 'member']);

    beviljaAccess($container, $sambo, 'write', 'member');
    beviljaAccess($container, $varv, 'write', 'managed');

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    $kropp = $response->getContent();

    foreach ([$ägare, $sambo, $anställd] as $person) {
        expect($kropp)->not->toContain($person->email);
    }

    expect($kropp)->not->toMatch('/[\w.+-]+@[\w-]+\.[\w.]+/');
});

it('återkallade deltagare syns inte', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $återkallad = User::factory()->create();
    beviljaAccess($container, $återkallad, 'write', 'member', revokedAt: now()->subDay());

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.*.ulid'))->not->toContain($återkallad->ulid);
});

it('utgångna deltagare syns inte', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $utgången = User::factory()->create();
    beviljaAccess($container, $utgången, 'read', 'guest', expiresAt: now()->subHour());

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.*.ulid'))->not->toContain($utgången->ulid);
});

/*
 * `expires_at` NULL betyder "går aldrig ut", inte "gick ut för länge sedan"
 * — 9a § Att se upp med. Testet finns för att en omskriven jämförelse i
 * controllern skulle ha missat just det fallet; scopeValid() gör det inte.
 */
it('en deltagare utan expires_at syns', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $sambo = User::factory()->create();
    beviljaAccess($container, $sambo, 'write', 'member', expiresAt: null);

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data.*.ulid'))->toContain($sambo->ulid);
});

/*
 * § Beslut 6: förvaltningsdata får inte smyga in. `level` är den vanligaste
 * kopieringsmissen från 9b:s ContainerAccessResource, och åtkomstradens
 * egen ULID identifierar en förvaltningsresurs bara ägaren får röra.
 */
it('svaret röjer aldrig level, expires_at, granted_by eller revoked_at', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $gäst = User::factory()->create();
    $access = beviljaAccess($container, $gäst, 'write', 'guest', expiresAt: now()->addWeek());

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    $kropp = $response->getContent();

    foreach (['level', 'expires_at', 'granted_by', 'revoked_at', 'grantee', 'grantee_type', 'email'] as $förbjudenNyckel) {
        expect($kropp)->not->toContain("\"{$förbjudenNyckel}\"");
    }

    // Varken åtkomstradens ULID eller den beviljande användarens.
    expect($kropp)->not->toContain($access->ulid);
    expect($kropp)->not->toContain(User::query()->whereKey($access->granted_by_user_id)->value('ulid'));

    // Exakt fyra nycklar per post, inga fler.
    foreach ($response->json('data') as $post) {
        expect(array_keys($post))->toBe(['type', 'ulid', 'name', 'role']);
    }
});

/*
 * § Beslut 4: en obesvarad inbjudan är INTE åtkomst. Att visa den för alla
 * deltagare skulle röja en e-postadress för någon som ännu inte tackat ja.
 * `invitation`-tabellen finns sedan issue 10a (#48), så punkten är testad
 * på riktigt och inte överhoppad.
 */
it('svaret röjer aldrig obesvarade inbjudningar', function () {
    [$ägarkonto, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'inte-svarat@exempel.se',
        'status' => 'pending',
        'invited_by_user_id' => $ägare->id,
    ]);

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.role'))->toBe('owner');

    $kropp = $response->getContent();
    expect($kropp)->not->toContain('inte-svarat@exempel.se');
    expect($kropp)->not->toContain($inbjudan->ulid);
});

/*
 * § Att se upp med: samma person kan ha en egen `member`-rad OCH nås via
 * ett `managed`-konto hon jobbar på. Det är två olika deltagare i modellens
 * mening — en person och en organisation — och ska visas som två poster.
 * Slå inte ihop dem.
 */
it('samma person via egen rad och via ett managed-konto ger två poster', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    $person = User::factory()->create();
    $varv = Account::factory()->create();
    $varv->users()->attach($person, ['role' => 'member']);

    beviljaAccess($container, $person, 'write', 'member');
    beviljaAccess($container, $varv, 'write', 'managed');

    $response = getJson("/api/containers/{$container->ulid}/participants", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('data.*.ulid'))
        ->toContain($person->ulid)
        ->toContain($varv->ulid);
});

/*
 * § Beslut 8: konstant antal frågor oavsett antal deltagare. Låser inte ett
 * fast tal (skört mot ovidkommande ändringar) utan att antalet är DETSAMMA
 * med två deltagare och med tio — samma teknik som
 * ContainerCrudTest::"listningen laddar ägarkontot i förväg", inklusive
 * värmningen av Sanctum-guarden, vars tokenuppslag annars bara belastar
 * det första mätta anropet.
 */
it('deltagarlistan gör inte en fråga per deltagare', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();

    beviljaAccess($container, User::factory()->create(), 'write', 'member');
    beviljaAccess($container, Account::factory()->create(), 'write', 'managed');

    $url = "/api/containers/{$container->ulid}/participants";

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    getJson($url, $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson($url, $headers);
    $frågorMedTreDeltagare = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(3);

    foreach (User::factory()->count(4)->create() as $person) {
        beviljaAccess($container, $person, 'read', 'guest');
    }
    foreach (Account::factory()->count(3)->create() as $organisation) {
        beviljaAccess($container, $organisation, 'read', 'managed');
    }
    DB::flushQueryLog(); // factoryns egna INSERT-frågor ska inte mätas

    $andraSvaret = getJson($url, $headers);
    $frågorMedTioDeltagare = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(10);

    expect($frågorMedTioDeltagare)->toBe($frågorMedTreDeltagare);

    Carbon::setTestNow();
});
