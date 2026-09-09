<?php

use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Subscription;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 46 · Plangrinden på kostnadsrapporten, se
 * App\Http\Controllers\Api\CostReportController och [[Planer och kvoter]] §
 * Kontrollpunkter. Registrering är fri, summering kräver Pro — och
 * kontrollen sitter i API:et (ADR-0016 § Konsekvenser): en egen frontend
 * ska inte kunna summera raderna genom att hämta dem och räkna själv.
 *
 * De två grindarna går i ordningen behörighet → plan (Beslut 2): en
 * användare utan åtkomst får auth.forbidden, aldrig en plan-kod som
 * avslöjar att containern finns. Planen som räknas är ÄGARKONTOTS,
 * `$container->account`, inte den anropande användarens — konsekvensen
 * testas i de två sista testerna.
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 */

it('ett gratiskonto nekas rapporten med plan.feature_unavailable', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('plan.feature_unavailable');
    expect($response->json('error.data'))->toBe(['feature' => 'cost_reports']);
});

it('ett gratiskonto kan fortfarande skapa och läsa sina egna kostnader', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Impeller',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $skapat = postJson($url, [
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impellerbyte',
    ], $headers);
    $skapat->assertCreated();

    $lista = getJson($url, $headers);
    $lista->assertOk();
    expect($lista->json('data'))->toHaveCount(1);
    expect($lista->json('data.0.amount'))->toBe(120050);

    // Registreringen är fri — men summeringen är det inte (samma konto).
    $nekat = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);
    $nekat->assertStatus(403);
    expect($nekat->json('error.code'))->toBe('plan.feature_unavailable');
});

it('ett prokonto får 200', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account, , $headers] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();
    $container = Container::factory()->for($account, 'account')->create();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    expect($response->json('data.group_by'))->toBe('item');
    expect($response->json('data.groups'))->toBe([]);
    expect($response->json('data.totals'))->toBe([]);
});

it('en användare utan åtkomst får auth.forbidden — även när både ägarkontot och användarens konto är pro', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$ägarKonto] = kontoMedMedlem();
    Subscription::factory()->for($ägarKonto)->for($pro)->create();
    $container = Container::factory()->for($ägarKonto, 'account')->create();

    [$användarensKonto, , $headers] = kontoMedMedlem();
    Subscription::factory()->for($användarensKonto)->for($pro)->create();

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->json('error.data'))->toBe([]);
});

it('en gratis gäst hos en pro-ägare får rapporten — planen som räknas är ägarkontots', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$ägarKonto] = kontoMedMedlem();
    Subscription::factory()->for($ägarKonto)->for($pro)->create();
    $container = Container::factory()->for($ägarKonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    CostEntry::factory()->for($item, 'item')->create(['amount' => 1000]);

    [, $gäst, $headers] = kontoMedMedlem(); // gästens EGNA konto är gratis
    beviljaAccess($container, $gäst, 'read', 'member');

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertOk();
    expect($response->json('data.totals.0.amount'))->toBe(1000);
});

it('en pro-gäst hos en gratis-ägare nekas plan.feature_unavailable, inte auth.forbidden', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$ägarKonto] = kontoMedMedlem(); // gratis
    $container = Container::factory()->for($ägarKonto, 'account')->create();

    [, $gäst, $headers] = kontoMedMedlem();
    Subscription::factory()->for($gäst->accounts()->first())->for($pro)->create();
    beviljaAccess($container, $gäst, 'read', 'member');

    $response = getJson("/api/containers/{$container->ulid}/costs/report?group_by=item", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('plan.feature_unavailable');
    expect($response->json('error.data.feature'))->toBe('cost_reports');
});
