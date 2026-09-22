<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\AccessLevel;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/*
 * Issue 105 · Favoritmarkeringen — tabellen, växlingen och stjärnan. Se
 * [[ADR-0042 Designsystemet]] § Konsekvenser, [[ADR-0028 Åtkomst på
 * itemnivå]] § Beslut och [[M17 Designsystemet]] § 105.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här.
 *
 * **Markeringen är per användare.** Provet "två användare favoritmarkerar
 * samma item oberoende av varandra" är det som skiljer en pivot från en
 * kolumn på `item`: med en flagga på itemet hade den enas markering varit
 * den andras, och det är fel så snart två personer ser samma item.
 *
 * **Markeringen speglar åtkomsten, den ger den inte.** Grinden är
 * App\Policies\ItemPolicy::view() och ligger före varje skrivning — ett item
 * utanför omfånget ger 403 och ingen rad, och ingen ny åtkomstregel finns i
 * App\Actions\Access\ResolveItemScope för den här issuen.
 *
 * Proven kör WEBBENS rutter (routes/web.php), inte `/api`: stjärnan är en
 * yta i webben och `/api` får ingen ändpunkt för markeringen. Fixturen är
 * ändå ItemgrindTest:s (tests/Feature/Omfang/ItemgrindTest.php): ett
 * ägarkonto med en container, och en mottagare utanför kontot med en grant
 * på ett enskilt item.
 *
 * Hjälparna har prefixet `favorit` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ägarkontot, dess medlem, containern och två items i den, i ordningen
 * [$konto, $ägare, $container, $motorn, $masten].
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: Item}
 */
function favoritKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = fn (string $namn) => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $ägare, $container, $item('Motorn'), $item('Masten')];
}

/**
 * En mottagare UTANFÖR ägarkontot med `read` på ett enda item — samma
 * fixture som ItemgrindTest:s itemgrant, och det minsta omfång som ändå
 * räcker för att få märka det itemet.
 */
function favoritMottagare(Container $container, Item $item): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => AccessLevel::READ,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Itemets detaljvy — sidan stjärnan står på, och den adress `back()` landar
 * på. `from()` sätter `Referer`, vilket är vad `back()` läser.
 */
function favoritUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

// --- pivoten ------------------------------------------------------------

it('pivoten binder användare till item med ett unikt par', function () {
    [, $ägare, $container, $motorn] = favoritKontext();

    $url = favoritUrl($container, $motorn);

    actingAs($ägare)->from($url)->post("{$url}/favorite")
        ->assertRedirect($url)
        ->assertSessionHas('status', 'favorite-added');

    // Dubbletten: samma användare och samma item en gång till. Raden finns
    // redan, så svaret är detsamma och ingen andra rad skrivs — det unika
    // paret `(user_id, item_id)` är garanten, och växlingen är idempotent.
    actingAs($ägare)->from($url)->post("{$url}/favorite")
        ->assertRedirect($url)
        ->assertSessionHas('status', 'favorite-added');

    $rader = Favorite::query()
        ->where('user_id', $ägare->id)
        ->where('item_id', $motorn->id)
        ->get();

    expect($rader)->toHaveCount(1);
    expect(Favorite::query()->count())->toBe(1);

    // Tidsstämpeln finns på raden och sätts av Eloquent — pivoten bär ett
    // "när", inte bara ett par.
    expect($rader->first()->created_at)->not->toBeNull();
    expect($rader->first()->item->is($motorn))->toBeTrue();
    expect($rader->first()->user->is($ägare))->toBeTrue();
});

// --- åtkomsten ----------------------------------------------------------

it('ett item utanför omfånget går inte att favoritmarkera', function () {
    [, , $container, $motorn, $masten] = favoritKontext();

    $mottagare = favoritMottagare($container, $motorn);

    $nåbar = favoritUrl($container, $motorn);
    $onåbar = favoritUrl($container, $masten);

    // Itemet hon NÅR går att märka. Utan den halvan hade 403:an nedan kunnat
    // vara en trasig rutt i stället för en grind.
    actingAs($mottagare)->from($nåbar)->post("{$nåbar}/favorite")->assertRedirect($nåbar);

    // Känt men utanför omfånget: 403 och inte 404, samma svar som detaljvyn
    // ger (issue 73 § Beslut 3). Ingen ny regel prövas — det är
    // ItemPolicy::view() och ingenting annat.
    actingAs($mottagare)->from($onåbar)->post("{$onåbar}/favorite")->assertForbidden();

    expect(Favorite::query()->where('user_id', $mottagare->id)->where('item_id', $masten->id)->exists())->toBeFalse();
    expect(Favorite::query()->count())->toBe(1);
});

it('stjärnan kräver inloggning och en item-ULID ur samma container', function () {
    [, $ägare, $container, $motorn] = favoritKontext();

    $annan = Container::factory()->for($container->account, 'account')->create();

    $främmande = Item::factory()->for($annan, 'container')->create([
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $url = favoritUrl($container, $motorn);

    // Utloggad: `auth`-middlewaren skickar till inloggningen.
    post("{$url}/favorite")->assertRedirect('/login');

    // En item-ULID från en annan container löses inte upp av scopeBindings()
    // (issue 9b § Beslut 1), så den blir 404 — inte märkt i fel container.
    actingAs($ägare)->from($url)
        ->post("/containers/{$container->ulid}/items/{$främmande->ulid}/favorite")
        ->assertNotFound();

    expect(Favorite::query()->count())->toBe(0);
});

// --- två användare, samma item ------------------------------------------

it('två användare favoritmarkerar samma item oberoende av varandra', function () {
    [, $ägare, $container, $motorn] = favoritKontext();

    $mottagare = favoritMottagare($container, $motorn);

    $url = favoritUrl($container, $motorn);

    actingAs($ägare)->from($url)->post("{$url}/favorite")->assertRedirect($url);

    // Den andras markering syns inte hos den första: markeringen är en rad om
    // FÖRHÅLLANDET och inte en flagga på itemet, så frågan "är det här min
    // favorit?" har olika svar för olika personer.
    expect($ägare->favorites()->where('item_id', $motorn->id)->exists())->toBeTrue();
    expect($mottagare->favorites()->where('item_id', $motorn->id)->exists())->toBeFalse();
    expect($motorn->favoritedBy()->count())->toBe(1);

    actingAs($mottagare)->from($url)->post("{$url}/favorite")->assertRedirect($url);

    // Två rader mot samma item — en per person. Det är hela skillnaden mot en
    // kolumn på `item`, som hade kunnat bära ett enda svar.
    expect($motorn->favoritedBy()->count())->toBe(2);
    expect($ägare->favorites()->where('item_id', $motorn->id)->exists())->toBeTrue();
    expect($mottagare->favorites()->where('item_id', $motorn->id)->exists())->toBeTrue();

    // Och den enas borttagning rör inte den andras.
    actingAs($ägare)->from($url)->delete("{$url}/favorite")
        ->assertRedirect($url)
        ->assertSessionHas('status', 'favorite-removed');

    expect($ägare->favorites()->where('item_id', $motorn->id)->exists())->toBeFalse();
    expect($mottagare->favorites()->where('item_id', $motorn->id)->exists())->toBeTrue();
    expect($motorn->favoritedBy()->count())->toBe(1);
});

// --- borttagningen ------------------------------------------------------

it('en borttagen favorit lämnar itemet orört', function () {
    [, $ägare, $container, $motorn] = favoritKontext();

    $url = favoritUrl($container, $motorn);

    $före = $motorn->fresh()->getAttributes();

    actingAs($ägare)->from($url)->post("{$url}/favorite")->assertRedirect($url);
    actingAs($ägare)->from($url)->delete("{$url}/favorite")->assertRedirect($url);

    expect(Favorite::query()->count())->toBe(0);

    // Hela radens tillstånd, inte ett fält i taget: `updated_at`,
    // `deleted_at` och namnet står stilla, så markeringen rörde ingenting på
    // itemet — varken en mjukradering, en tidsstämpel eller ett fält.
    expect($motorn->fresh()->getAttributes())->toBe($före);

    // Markeringen går att sätta igen efteråt: raden raderas hårt och ligger
    // inte kvar i det unika paret (migrationens docblock).
    actingAs($ägare)->from($url)->post("{$url}/favorite")->assertRedirect($url);

    expect($ägare->favorites()->where('item_id', $motorn->id)->exists())->toBeTrue();
});

it('att ta bort en markering som inte finns är inte ett fel', function () {
    [, $ägare, $container, $motorn] = favoritKontext();

    $url = favoritUrl($container, $motorn);

    actingAs($ägare)->from($url)->delete("{$url}/favorite")
        ->assertRedirect($url)
        ->assertSessionHas('status', 'favorite-removed');

    expect(Favorite::query()->count())->toBe(0);
});

// --- resursen -----------------------------------------------------------

it('ItemResource har inget nytt fält', function () {
    [, $ägare, $container, $motorn] = favoritKontext();

    $url = favoritUrl($container, $motorn);

    // Itemet ÄR en favorit när svaret läses. Att fältet ändå inte finns är
    // hela beviset: en favorit är ett faktum om relationen mellan en
    // användare och ett item, inte om itemet, och `/api`:s format har inte
    // bett om det — samma linje som omslagsbilden i issue 93.
    actingAs($ägare)->from($url)->post("{$url}/favorite")->assertRedirect($url);

    $token = $ägare->createToken('api');
    $svar = getJson(
        "/api/containers/{$container->ulid}/items/{$motorn->ulid}",
        ['Authorization' => "Bearer {$token->plainTextToken}"],
    );

    $svar->assertOk();

    expect($svar->getContent())->not->toContain('favorite');
    expect($svar->json('data'))->not->toHaveKey('is_favorite');
});
