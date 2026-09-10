<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 73 · Fritextsöket över flera containers — den svåra biten av
 * läckageytan. Se [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser,
 * [[ADR-0012 Sök]] § Konsekvenser och
 * App\Http\Controllers\Api\ItemSearchController::index().
 *
 * Den globala rutten `GET /api/items?q=` går över ALLA containers
 * användaren når, och omfånget är olika i varje. Villkoret blir en OR över
 * containers (issue 73 § Beslut 4), byggt av ETT anrop till
 * ResolveItemScope::forContainers(). Containerns egen sökväg
 * (`?q=` på itemlistningen) prövas i ListningsfilterTest.php.
 *
 * Hjälparna är namnrymda (`sok*`) — Pest delar global namnrymd mellan
 * testfilerna.
 */

/**
 * Ett ägarkonto med en medlem, plus ett Sanctum-headerpar för medlemmen.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function sokÄgare(): array
{
    return kontoMedMedlem();
}

/**
 * En container under $ägarkonto — containern är alltså inte mottagarens.
 */
function sokContainer(Account $ägarkonto): Container
{
    return Container::factory()->for($ägarkonto, 'account')->create();
}

/**
 * Ett item i $container med ett namn som bär söktermen.
 */
function sokItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En container_access-rad, item-bred när $item ges och container-bred annars.
 */
function sokGrant(Container $container, User $user, ?Item $item = null, string $nivå = 'read'): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med ett Sanctum-headerpar.
 *
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function sokMottagare(): array
{
    $user = User::factory()->create();
    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop.
 *
 * `app()->forgetScopedInstances()` först: ResolveItemScope är `scoped` och
 * memoiserar per request i drift, men i testsviten överlever memon mellan
 * HTTP-anropen (Container::forgetScopedInstances() körs bara i kö-arbetare).
 * Utan det mäter man förra anropets omfång.
 *
 * En egen funktion och inte en stängning i testet: DB::listen staplar
 * lyssnare, och en återanvänd stängning med `use (&$frågor)` låter den
 * förra lyssnaren räkna samma variabel en gång till.
 */
function sokFrågor(Closure $värm, Closure $anrop): int
{
    app()->forgetScopedInstances();
    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

it('GET /items?q= över tre containers med olika omfång ger exakt det mottagaren når i var och en', function () {
    [$ägarkonto] = sokÄgare();

    $obegränsad = sokContainer($ägarkonto);
    $begränsadEtt = sokContainer($ägarkonto);
    $begränsadTvå = sokContainer($ägarkonto);

    // Den obegränsade: allt syns.
    $a1 = sokItem($obegränsad, 'Vindil ett');
    $a2 = sokItem($obegränsad, 'Vindil två');
    $a3 = sokItem($obegränsad, 'Vindil tre');

    // De begränsade: ett item var nås, resten ligger utanför.
    $b1 = sokItem($begränsadEtt, 'Vindil fyra');
    sokItem($begränsadEtt, 'Vindil fem');

    $c1 = sokItem($begränsadTvå, 'Vindil sex');
    sokItem($begränsadTvå, 'Vindil sju');

    [$mottagare, $headers] = sokMottagare();
    sokGrant($obegränsad, $mottagare, null, 'read');
    sokGrant($begränsadEtt, $mottagare, $b1);
    sokGrant($begränsadTvå, $mottagare, $c1);

    $svar = getJson('/api/items?q=Vindil', $headers);

    $svar->assertOk();
    expect(collect($svar->json('data'))->pluck('ulid')->sort()->values()->all())
        ->toBe(collect([$a1->ulid, $a2->ulid, $a3->ulid, $b1->ulid, $c1->ulid])->sort()->values()->all());
});

it('en begränsad grant i EN container öppnar inte samma container förbi items utanför omfånget', function () {
    [$ägarkonto] = sokÄgare();
    $container = sokContainer($ägarkonto);

    $mitt = sokItem($container, 'Vindil mitt');
    $dolt = sokItem($container, 'Vindil dolt');

    [$mottagare, $headers] = sokMottagare();
    sokGrant($container, $mottagare, $mitt);

    $svar = getJson('/api/items?q=Vindil', $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1);
    expect($svar->json('data.0.ulid'))->toBe($mitt->ulid);
    expect($svar->getContent())->not->toContain($dolt->ulid);
});

it('GET /items?q= för en användare utan åtkomst till någon container ger tomt, inte allt', function () {
    [$ägarkonto] = sokÄgare();
    $container = sokContainer($ägarkonto);
    sokItem($container, 'Vindil hemlig');

    // Ingen grant alls — båda listorna i OR-villkoret är tomma, och då får
    // svaret inte falla tillbaka på "hela databasen" (issue 73 § Beslut 4).
    [, $headers] = sokMottagare();

    $svar = getJson('/api/items?q=Vindil', $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(0);
    expect($svar->getContent())->not->toContain('Vindil hemlig');
});

it('en medlem i ägarkontot ser containerns items oförändrat via sökningen', function () {
    [$ägarkonto, , $headers] = sokÄgare();
    $container = sokContainer($ägarkonto);

    foreach (['Vindil ett', 'Vindil två', 'Vindil tre'] as $namn) {
        sokItem($container, $namn);
    }

    $svar = getJson('/api/items?q=Vindil', $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(3);
});

it('sökningen kostar ett konstant antal frågor: samma för en container som för tio', function () {
    [$ägarkonto] = sokÄgare();
    [$mottagare, $headers] = sokMottagare();

    $första = sokContainer($ägarkonto);
    sokItem($första, 'Vindil ett');
    sokGrant($första, $mottagare, null, 'read');

    Carbon::setTestNow(now());

    $värm = fn () => getJson('/api/items?q=Vindil', $headers)->assertOk();

    $frågorMedEnContainer = sokFrågor($värm, function () use ($headers) {
        $svar = getJson('/api/items?q=Vindil', $headers);
        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(1);
    });

    // Nio containers till, alla med container-bred grant: den som når
    // containern når allt i den, och antalet frågor ska inte växa.
    foreach (range(1, 9) as $i) {
        $extra = sokContainer($ägarkonto);
        sokItem($extra, "Vindil $i");
        sokGrant($extra, $mottagare, null, 'read');
    }

    $frågorMedTioContainers = sokFrågor($värm, function () use ($headers) {
        $svar = getJson('/api/items?q=Vindil', $headers);
        $svar->assertOk();
        expect($svar->json('data'))->toHaveCount(10);
    });

    expect($frågorMedTioContainers)->toBe($frågorMedEnContainer);

    Carbon::setTestNow();
});

it('en mjukraderad container ger inga sökträffar ens med en giltig grant', function () {
    [$ägarkonto] = sokÄgare();
    $container = sokContainer($ägarkonto);
    sokItem($container, 'Vindil');

    [$mottagare, $headers] = sokMottagare();
    sokGrant($container, $mottagare, null, 'read');

    $container->delete();

    $svar = getJson('/api/items?q=Vindil', $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(0);
});
