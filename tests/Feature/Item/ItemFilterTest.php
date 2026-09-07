<?php

use App\Actions\Category\ResolveCategoryDescendants;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 15a · Filtrering på tagg och kategori. Se
 * App\Http\Controllers\Api\ItemController::index(),
 * App\Http\Requests\Item\IndexItemRequest,
 * App\Actions\Category\ResolveCategoryDescendants och App\Models\Item
 * (scopes WithAllTags och InCategoryTree).
 *
 * kontoMedMedlem() och beviljaAccess() är globala testhjälpare i
 * tests/Support/Testhjalpare.php.
 *
 * Varje "Klart när"-punkt i issuen är ett namngivet test här; att
 * ItemCrudTest fortsätter gå igenom oförändrad är 13a-beviset och testas
 * inte om i den här filen.
 */

it('en listning utan parametrar är oförändrad', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();

    Item::factory()->for($container, 'container')->create([
        'name' => 'Zebra',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    Item::factory()->for($container, 'container')->create([
        'name' => 'Alpha',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    Item::factory()->for($annanContainer, 'container')->create([
        'name' => 'Beta',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = getJson("/api/containers/{$container->ulid}/items", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.name'))->toBe('Alpha');
    expect($response->json('data.1.name'))->toBe('Zebra');
});

it('filtrerar på en tagg', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $medTagg = Item::factory()->for($container, 'container')->create(['name' => 'MPPT-regulator']);
    $medTagg->tags()->attach([$tagg->id]);
    Item::factory()->for($container, 'container')->create(['name' => 'Garderob']);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('MPPT-regulator');
});

it('flera taggar kombineras med OCH', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Tag::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Tag::factory()->for($container, 'container')->create(['name' => 'B']);

    $båda = Item::factory()->for($container, 'container')->create(['name' => 'Båda']);
    $båda->tags()->attach([$a->id, $b->id]);

    $baraA = Item::factory()->for($container, 'container')->create(['name' => 'Bara A']);
    $baraA->tags()->attach([$a->id]);

    $baraB = Item::factory()->for($container, 'container')->create(['name' => 'Bara B']);
    $baraB->tags()->attach([$b->id]);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$a->ulid}&tags[]={$b->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Båda');
});

it('ett item med båda taggarna returneras en gång', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $a = Tag::factory()->for($container, 'container')->create(['name' => 'A']);
    $b = Tag::factory()->for($container, 'container')->create(['name' => 'B']);

    $item = Item::factory()->for($container, 'container')->create(['name' => 'MPPT-regulator']);
    $item->tags()->attach([$a->id, $b->id]);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$a->ulid}&tags[]={$b->ulid}", $headers);

    $response->assertOk();
    // Joinen mot `item_tag` får inte ge samma item två gånger — GROUP BY
    // löser det (issue 15a § Att se upp med).
    expect($response->json('data'))->toHaveCount(1);
    expect(collect($response->json('data'))->pluck('ulid')->all())->toBe([$item->ulid]);
});

it('filtrerar på kategori', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    Item::factory()->for($container, 'container')->create(['name' => 'MPPT-regulator', 'category_id' => $kategori->id]);
    Item::factory()->for($container, 'container')->create(['name' => 'Garderob']);

    $response = getJson("/api/containers/{$container->ulid}/items?category={$kategori->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('MPPT-regulator');
});

it('kategorifiltret inkluderar underkategorier', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $motor = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $el = Category::factory()->for($container, 'container')->create(['name' => 'El', 'parent_id' => $motor->id]);
    $laddning = Category::factory()->for($container, 'container')->create(['name' => 'Laddning', 'parent_id' => $el->id]);

    Item::factory()->for($container, 'container')->create(['name' => 'I motor', 'category_id' => $motor->id]);
    Item::factory()->for($container, 'container')->create(['name' => 'I el', 'category_id' => $el->id]);
    Item::factory()->for($container, 'container')->create(['name' => 'I laddning', 'category_id' => $laddning->id]);
    Item::factory()->for($container, 'container')->create(['name' => 'Okategoriserad']);

    $response = getJson("/api/containers/{$container->ulid}/items?category={$motor->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect(collect($response->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['I el', 'I laddning', 'I motor']);
});

it('kategorifiltret inkluderar kategorin själv', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $motor = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Barn', 'parent_id' => $motor->id]);

    Item::factory()->for($container, 'container')->create(['name' => 'Direkt i motor', 'category_id' => $motor->id]);
    Item::factory()->for($container, 'container')->create(['name' => 'I barn', 'category_id' => $barn->id]);

    $response = getJson("/api/containers/{$container->ulid}/items?category={$motor->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('name'))->toContain('Direkt i motor');
});

it('tagg och kategori kombineras med OCH', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $båda = Item::factory()->for($container, 'container')->create(['name' => 'Båda', 'category_id' => $kategori->id]);
    $båda->tags()->attach([$tagg->id]);

    $baraTagg = Item::factory()->for($container, 'container')->create(['name' => 'Bara tagg']);
    $baraTagg->tags()->attach([$tagg->id]);

    Item::factory()->for($container, 'container')->create(['name' => 'Bara kategori', 'category_id' => $kategori->id]);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}&category={$kategori->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Båda');
});

it('en tagg från en annan container avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $frammandeTagg = Tag::factory()->for($annanContainer, 'container')->create(['name' => 'Hemlig']);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$frammandeTagg->ulid}", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    // Fältkoden ligger under `tags.0` — klienten kan peka ut vilken tagg som var fel.
    expect($response->json('error.data.fields'))->toHaveKey('tags.0');
});

it('en kategori från en annan container avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();
    $frammandeKategori = Category::factory()->for($annanContainer, 'container')->create(['name' => 'Hemlig']);

    $response = getJson("/api/containers/{$container->ulid}/items?category={$frammandeKategori->ulid}", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('category');
});

it('en mjukraderad tagg avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $tagg->delete();

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields'))->toHaveKey('tags.0');
});

it('ett filter utan träffar ger 200 och en tom lista', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    Item::factory()->for($container, 'container')->create(['name' => 'Garderob']);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);

    // 200, aldrig 404: listan finns, den är tom (issue 15a § Beslut 7).
    $response->assertOk();
    $response->assertJsonPath('data', []);
});

it('mjukraderade items kommer aldrig med', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $levande = Item::factory()->for($container, 'container')->create(['name' => 'Levande', 'category_id' => $kategori->id]);
    $levande->tags()->attach([$tagg->id]);

    $raderat = Item::factory()->for($container, 'container')->create(['name' => 'Raderat', 'category_id' => $kategori->id]);
    $raderat->tags()->attach([$tagg->id]);
    $raderat->delete();

    $påTagg = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);
    $påTagg->assertOk();
    expect($påTagg->json('data'))->toHaveCount(1);
    expect($påTagg->json('data.0.name'))->toBe('Levande');

    $påKategori = getJson("/api/containers/{$container->ulid}/items?category={$kategori->ulid}", $headers);
    $påKategori->assertOk();
    expect($påKategori->json('data'))->toHaveCount(1);
    expect($påKategori->json('data.0.name'))->toBe('Levande');
});

it('filtret returnerar aldrig items ur en annan container', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annanContainer = Container::factory()->for($account, 'account')->create();

    // Samma NAMN i båda containrarna — det är ULID:en som avgör, inte namnet.
    $taggHär = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $taggDär = Tag::factory()->for($annanContainer, 'container')->create(['name' => 'Motor']);

    $itemHär = Item::factory()->for($container, 'container')->create(['name' => 'I containern']);
    $itemHär->tags()->attach([$taggHär->id]);

    $itemDär = Item::factory()->for($annanContainer, 'container')->create(['name' => 'Från andra containern']);
    $itemDär->tags()->attach([$taggDär->id]);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$taggHär->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('I containern');
});

it('sorteringen är oförändrad', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    // Skapas i omvänd ordning — sorteringen i svaret får inte vara INSERT-ordningen.
    foreach (['Zebra', 'Alfa', 'Mike'] as $namn) {
        $item = Item::factory()->for($container, 'container')->create(['name' => $namn]);
        $item->tags()->attach([$tagg->id]);
    }

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Alfa', 'Mike', 'Zebra']);
});

it('filtreringen gör ett konstant antal frågor', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    // Scenario 1: en tagg, ett grund träd (1 nivå), 3 items.
    $taggA = Tag::factory()->for($container, 'container')->create(['name' => 'A']);
    $kategori1 = Category::factory()->for($container, 'container')->create(['name' => 'Kategori 1']);
    foreach (['Alfa', 'Beta', 'Gamma'] as $namn) {
        $item = Item::factory()->for($container, 'container')->create(['name' => $namn, 'category_id' => $kategori1->id]);
        $item->tags()->attach([$taggA->id]);
    }

    // Scenario 2: två taggar, ett djupare träd (3 nivåer, egen rot), fler items.
    $taggB = Tag::factory()->for($container, 'container')->create(['name' => 'B']);
    $rot2 = Category::factory()->for($container, 'container')->create(['name' => 'Rot 2']);
    $barn2 = Category::factory()->for($container, 'container')->create(['name' => 'Barn 2', 'parent_id' => $rot2->id]);
    $barnbarn2 = Category::factory()->for($container, 'container')->create(['name' => 'Barnbarn 2', 'parent_id' => $barn2->id]);
    foreach (['Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta'] as $namn) {
        $item = Item::factory()->for($container, 'container')->create(['name' => $namn, 'category_id' => $barnbarn2->id]);
        $item->tags()->attach([$taggA->id, $taggB->id]);
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden med ett omätt anrop, samma mönster som
    // ContainerCrudTest::it('listningen laddar ägarkontot i förväg').
    getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $medEnTagg = getJson("/api/containers/{$container->ulid}/items?tags[]={$taggA->ulid}&category={$kategori1->ulid}", $headers);
    $medEnTagg->assertOk();
    expect($medEnTagg->json('data'))->toHaveCount(3);
    $frågorMedEnTagg = $frågor;

    $frågor = 0;
    $medTvåTaggar = getJson("/api/containers/{$container->ulid}/items?tags[]={$taggA->ulid}&tags[]={$taggB->ulid}&category={$barnbarn2->ulid}", $headers);
    $medTvåTaggar->assertOk();
    expect($medTvåTaggar->json('data'))->toHaveCount(5);
    $frågorMedTvåTaggarOchDjuptTräd = $frågor;

    // Antalet växer varken med antalet filtervärden, antalet items eller
    // trädets djup (issue 15a § Beslut 9).
    expect($frågorMedTvåTaggarOchDjuptTräd)->toBe($frågorMedEnTagg);

    Carbon::setTestNow();
});

it('ResolveCategoryDescendants gör en fråga oavsett djup', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $rot = Category::factory()->for($container, 'container')->create(['name' => 'Rot']);
    $barn = Category::factory()->for($container, 'container')->create(['name' => 'Barn', 'parent_id' => $rot->id]);
    $barnbarn = Category::factory()->for($container, 'container')->create(['name' => 'Barnbarn', 'parent_id' => $barn->id]);
    $barnbarnbarn = Category::factory()->for($container, 'container')->create(['name' => 'Barnbarnbarn', 'parent_id' => $barnbarn->id]);

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $ids = resolve(ResolveCategoryDescendants::class)->handle($rot);

    expect($frågor)->toBe(1);
    expect($ids)->toHaveCount(4);
    expect($ids)->toContain($rot->id, $barn->id, $barnbarn->id, $barnbarnbarn->id);
});

it('en read-deltagare får filtrera', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);
    $item = Item::factory()->for($container, 'container')->create(['name' => 'MPPT-regulator']);
    $item->tags()->attach([$tagg->id]);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('MPPT-regulator');
});

it('en användare utan åtkomst nekas', function () {
    [, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor']);

    $response = getJson("/api/containers/{$container->ulid}/items?tags[]={$tagg->ulid}", $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});
