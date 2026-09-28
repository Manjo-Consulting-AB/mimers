<?php

use App\Actions\Item\RecordRecentVisit;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\RecentVisit;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 160 · Nyligen besökta — skrivningen. Se [[ADR-0049 Nyligen besökta]],
 * App\Actions\Item\RecordRecentVisit och
 * App\Http\Controllers\ItemController::show().
 *
 * Varje "Klart när"-punkt om SKRIVNINGEN motsvaras av ett namngivet test här:
 * att öppna ett item skriver en rad, att öppna det igen uppdaterar `visited_at`
 * utan en ny rad, att en 21:a rad raderar den äldsta, och att en nekad visning
 * inte skriver någonting. Listan — filtreringen, ordningen och taket på tio —
 * prövas i tests/Feature/Frontend/NyligenBesoktaTest.php.
 *
 * **Grinden ligger före skrivningen, och det är provet "skriver ingenting när
 * visningen nekas" som håller den där.** Flyttas anropet ovanför
 * `Gate::authorize()` faller det, och då hade ett item utanför omfånget
 * skrivit en rad om en person som aldrig fick se det.
 *
 * Proven kör WEBBENS rutt (routes/web.php), för det är sidan som skriver:
 * `/api` får ingen motsvarighet. Fixturen är FavoritTest:s
 * (tests/Feature/Item/FavoritTest.php) — ett ägarkonto med en container, och en
 * mottagare utanför kontot med en grant på ett enskilt item.
 *
 * Hjälparna har prefixet `nyligen` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ägarkontot, dess medlem, containern och två items i den, i ordningen
 * [$konto, $ägare, $container, $motorn, $masten].
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: Item}
 */
function nyligenKontext(): array
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
 * Ett item i containern, för takprovet som behöver fler än tjugo.
 */
function nyligenItem(Container $container, User $ägare, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot med `read` på ett enda item — samma fixture
 * som FavoritTest:s itemgrant, och det minsta omfång som ändå räcker för att
 * få öppna det itemet.
 */
function nyligenMottagare(Container $container, Item $item): User
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
 * Itemets detaljvy — sidan som skriver besöket.
 */
function nyligenUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

it('skriver en rad när itemet öppnas och flyttar visited_at när det öppnas igen', function () {
    withoutVite();

    [, $ägare, $container, $motorn] = nyligenKontext();

    Carbon::setTestNow('2026-09-28 10:00:00');

    actingAs($ägare)->get(nyligenUrl($container, $motorn))->assertOk();

    $visit = RecentVisit::query()->where('user_id', $ägare->id)->sole();

    expect($visit->item_id)->toBe($motorn->id)
        ->and($visit->visited_at->toDateTimeString())->toBe('2026-09-28 10:00:00');

    Carbon::setTestNow('2026-09-28 11:00:00');

    // Samma sida en gång till. Det unika paret `(user_id, item_id)` är
    // garanten: raden finns redan, så upserten uppdaterar den i stället för
    // att skriva en andra.
    actingAs($ägare)->get(nyligenUrl($container, $motorn))->assertOk();

    expect(RecentVisit::query()->where('user_id', $ägare->id)->count())->toBe(1)
        ->and(RecentVisit::query()->where('user_id', $ägare->id)->sole()->visited_at->toDateTimeString())
        ->toBe('2026-09-28 11:00:00');

    Carbon::setTestNow();
});

it('en tjugoförsta rad raderar den äldsta', function () {
    withoutVite();

    [, $ägare, $container] = nyligenKontext();

    // Tjugoen items, besökta i ordning — den första är den äldsta och ska
    // inte finnas kvar när den tjugoförsta skrivits.
    $items = collect(range(1, RecordRecentVisit::LIMIT + 1))
        ->map(fn (int $nummer): Item => nyligenItem($container, $ägare, "Item {$nummer}"));

    foreach ($items as $index => $item) {
        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00')->addMinutes($index));

        actingAs($ägare)->get(nyligenUrl($container, $item))->assertOk();
    }

    Carbon::setTestNow();

    $kvar = RecentVisit::query()
        ->where('user_id', $ägare->id)
        ->orderBy('visited_at')
        ->pluck('item_id');

    expect($kvar)->toHaveCount(RecordRecentVisit::LIMIT)
        ->and($kvar)->not->toContain($items->first()->id)
        ->and($kvar->last())->toBe($items->last()->id);
});

it('skriver ingenting när visningen nekas', function () {
    withoutVite();

    [, , $container, $motorn, $masten] = nyligenKontext();

    $mottagare = nyligenMottagare($container, $motorn);

    // Masten ligger utanför mottagarens omfång: grinden kastar, och anropet
    // till RecordRecentVisit ligger efter den.
    actingAs($mottagare)->get(nyligenUrl($container, $masten))->assertForbidden();

    expect(RecentVisit::query()->where('user_id', $mottagare->id)->count())->toBe(0);

    // Och samma mottagare SKRIVER på det item hon når, så provet inte kan
    // passera på en kontroller som slutat skriva alls.
    actingAs($mottagare)->get(nyligenUrl($container, $motorn))->assertOk();

    expect(RecentVisit::query()->where('user_id', $mottagare->id)->count())->toBe(1);
});

it('håller taket per person och inte över alla', function () {
    withoutVite();

    [, $ägare, $container] = nyligenKontext();

    $items = collect(range(1, RecordRecentVisit::LIMIT + 1))
        ->map(fn (int $nummer): Item => nyligenItem($container, $ägare, "Item {$nummer}"));

    // Ägaren öppnar alla tjugoen.
    foreach ($items as $item) {
        actingAs($ägare)->get(nyligenUrl($container, $item))->assertOk();
    }

    // Sedan öppnar en annan person ett av dem. Hennes rad är hennes: den
    // räknas inte in i ägarens tak, och ägarens städning rör den inte.
    $mottagare = nyligenMottagare($container, $items->first());

    actingAs($mottagare)->get(nyligenUrl($container, $items->first()))->assertOk();

    expect(RecentVisit::query()->where('user_id', $ägare->id)->count())->toBe(RecordRecentVisit::LIMIT)
        ->and(RecentVisit::query()->where('user_id', $mottagare->id)->count())->toBe(1);
});
