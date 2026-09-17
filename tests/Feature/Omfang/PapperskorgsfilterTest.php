<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Issue 74 · Session 1 — papperskorgen: listningen och återställningsrätten.
 * Se [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser, [[ADR-0008 Soft delete
 * och papperskorg]], App\Http\Controllers\Api\TrashController::index() och
 * ::restore(), App\Actions\Trash\RestoreContent (orörd) och
 * App\Support\Access\ItemScope.
 *
 * Läckageytan, del två: issue 73 stängde de fyra listningarna, den här filen
 * stänger de vyer som SAMMANFATTAR i stället för att lista. Papperskorgen är
 * den första av dem — en containervy som annars är ett fönster in i allt som
 * någon gång raderats i containern.
 *
 * Fixturen är omfångsupplösningens (issue 70):
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *
 * Hjälparna är namnrymda (`papperskorgsfilter*`) för att inte krocka med de
 * andra Omfang-filerna — Pest delar global namnrymd mellan testfilerna.
 * kontoMedMedlem(), beviljaAccess(), papperskorgsItem() och
 * papperskorgsBilaga() är globala i tests/Support/Testhjalpare.php.
 */

/**
 * Båten och dess delar i EN container, i ordningen [$container, $båt, $motor,
 * $mast, $impeller]. Ägarkontot kan skickas in så att en medlem i det kan
 * prövas mot samma fixture.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function papperskorgsfilterBåt(?Account $ägarkonto = null): array
{
    $container = Container::factory()
        ->for($ägarkonto ?? Account::factory()->create(), 'account')
        ->create();

    $a = $container->account;
    $skapare = User::factory()->create();

    $item = fn (string $namn): Item => papperskorgsItem($container, $a, $skapare, ['name' => $namn]);

    $båt = $item('Båten');
    $motor = $item('Motorn');
    $mast = $item('Masten');
    $impeller = $item('Impellern');

    // from = förälder, to = barn — samma kanoniska riktning som LinkItems.
    papperskorgsfilterKant($båt, $motor);
    papperskorgsfilterKant($båt, $mast);
    papperskorgsfilterKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En `parent`-kant skriven DIREKT i tabellen, förbi LinkItems — upplösningen
 * ska prövas mot grafen, inte mot den Action som skapar den.
 */
function papperskorgsfilterKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En container_access-rad, item-bred när $item ges och container-bred annars.
 */
function papperskorgsfilterGrant(Container $container, User $user, ?Item $item = null, string $nivå = 'read'): ContainerAccess
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
function papperskorgsfilterMottagare(): array
{
    $user = User::factory()->create();
    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * Mjukraderar en rad genom att sätta `deleted_at` — samma sluttillstånd som
 * raderingsrutterna (SoftDeletes) men med kontrollerad tidpunkt.
 */
function papperskorgsfilterRadera(Item|Attachment|Category|Tag $modell): void
{
    $modell->deleted_at = now();
    $modell->save();
}

/**
 * ULID:erna i svaret.
 *
 * @return list<string>
 */
function papperskorgsfilterUlider($response): array
{
    return collect($response->json('data'))->pluck('ulid')->all();
}

it('en omfångsbegränsad mottagares papperskorg bär bara hennes items och deras bilagor', function () {
    [$container, $båt, $motor, $mast, $impeller] = papperskorgsfilterBåt();

    $motornsBilaga = papperskorgsBilaga($motor, $container->account, User::factory()->create(), ['filename' => 'motorns.pdf']);
    $mastensBilaga = papperskorgsBilaga($mast, $container->account, User::factory()->create(), ['filename' => 'mastens.pdf']);

    foreach ([$båt, $motor, $mast, $impeller, $motornsBilaga, $mastensBilaga] as $rad) {
        papperskorgsfilterRadera($rad);
    }

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $svar->assertOk();
    // Granten når motorn och impellern — ett löv till. Alla raderna
    // mjukraderades i samma sekund, så ordningen dem emellan är ULID:ns och
    // inte den här filens sak; mängden är det som prövas.
    expect(collect(papperskorgsfilterUlider($svar))->sort()->values()->all())
        ->toBe(collect([$motornsBilaga->ulid, $impeller->ulid, $motor->ulid])->sort()->values()->all());

    $bilagaRad = collect($svar->json('data'))->firstWhere('type', 'attachment');
    expect($bilagaRad['context'])->toBe('Motorn');
    expect($svar->getContent())->not->toContain('Båten');
    expect($svar->getContent())->not->toContain('Masten');
    expect($svar->getContent())->not->toContain('mastens.pdf');
});

it('samma papperskorg bär inga kategorier och inga taggar', function () {
    [$container, , $motor] = papperskorgsfilterBåt();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);

    foreach ([$motor, $kategori, $tagg] as $rad) {
        papperskorgsfilterRadera($rad);
    }

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $svar->assertOk();
    expect(papperskorgsfilterUlider($svar))->toBe([$motor->ulid]);
    expect(collect($svar->json('data'))->pluck('type')->all())->toBe(['item']);

    // Namnen i sig är avslöjandet: en raderad tagg som heter "Skilsmässa" är
    // en upplysning om containern, inte om motorn (issue 74 § Beslut 1).
    expect($svar->getContent())->not->toContain('Skilsmässa');
    expect($svar->getContent())->not->toContain('Rigg');
    expect($svar->getContent())->not->toContain($kategori->ulid);
    expect($svar->getContent())->not->toContain($tagg->ulid);
});

it('ett raderat item hon aldrig kunnat se finns inte i svaret, varken som post eller som antal', function () {
    [$container, , $motor] = papperskorgsfilterBåt();

    foreach (range(1, 12) as $i) {
        papperskorgsfilterRadera(
            papperskorgsItem($container, $container->account, User::factory()->create(), ['name' => "Dold $i"])
        );
    }

    papperskorgsfilterRadera($motor);

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $mottagare, $motor);

    $svar = getJson("/api/containers/{$container->ulid}/trash", $headers);

    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1);
    expect($svar->getContent())->not->toContain('Dold');

    // Ingen totalräknare, ingen räknande header och ingen extra nyckel —
    // mottagaren kan inte sluta sig till hur mycket som filtrerats bort.
    expect(array_keys($svar->json()))->toBe(['data']);
    expect($svar->json())->not->toHaveKey('total');
    expect($svar->json())->not->toHaveKey('meta');
    expect($svar->headers->get('X-Total-Count'))->toBeNull();
    expect($svar->headers->get('Content-Range'))->toBeNull();
});

it('en delete-mottagare kan återställa sitt eget item och en bilaga på det', function () {
    [$container, , $motor] = papperskorgsfilterBåt();

    $bilaga = papperskorgsBilaga($motor, $container->account, User::factory()->create(), ['filename' => 'faktura.pdf']);
    papperskorgsfilterRadera($bilaga);

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $mottagare, $motor, 'delete');

    $påBilagan = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'attachment',
        'ulid' => $bilaga->ulid,
    ], $headers);

    $påBilagan->assertOk();
    expect($påBilagan->json('data.ulid'))->toBe($bilaga->ulid);
    expect($påBilagan->json('data.deleted_at'))->toBeNull();
    expect($bilaga->fresh()->deleted_at)->toBeNull();

    // Sedan itemet självt: `delete` mjukraderar OCH återställer (regel 3).
    papperskorgsfilterRadera($motor);

    $påItemet = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $motor->ulid,
    ], $headers);

    $påItemet->assertOk();
    expect($påItemet->json('data.ulid'))->toBe($motor->ulid);
    expect($motor->fresh()->deleted_at)->toBeNull();
});

it('samma mottagare får 403 auth.forbidden för ett item utanför omfånget', function () {
    [$container, , $motor, $mast] = papperskorgsfilterBåt();

    papperskorgsfilterRadera($mast);

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $mottagare, $motor, 'delete');

    $svar = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $mast->ulid,
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');

    // Nekan lämnar raden i papperskorgen.
    expect($mast->fresh()->deleted_at)->not->toBeNull();
});

it('en write-mottagare får 403 vid återställning av sitt eget item', function () {
    [$container, , $motor] = papperskorgsfilterBåt();

    papperskorgsfilterRadera($motor);

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    // `write` ligger under `delete` i laddern och mjukraderar varken eller
    // återställer — samma nivå som gör att hon inte får radera itemet.
    papperskorgsfilterGrant($container, $mottagare, $motor, 'write');

    $svar = postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $motor->ulid,
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
    expect($motor->fresh()->deleted_at)->not->toBeNull();
});

it('en omfångsbegränsad mottagare får 403 vid återställning av en tagg eller kategori', function () {
    [$container, , $motor] = papperskorgsfilterBåt();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    papperskorgsfilterRadera($kategori);
    papperskorgsfilterRadera($tagg);

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    // Även med `delete` på sitt item: kategorin och taggen är containervid
    // organisation, inte hennes item (issue 74 § Beslut 1 och 2).
    papperskorgsfilterGrant($container, $mottagare, $motor, 'delete');

    foreach ([['category', $kategori], ['tag', $tagg]] as [$typ, $rad]) {
        $svar = postJson("/api/containers/{$container->ulid}/trash/restore", [
            'type' => $typ,
            'ulid' => $rad->ulid,
        ], $headers);

        $svar->assertStatus(403);
        expect($svar->json('error.code'))->toBe('auth.forbidden');
        expect($rad->fresh()->deleted_at)->not->toBeNull();
    }
});

it('ägaren ser och återställer alla fyra typerna, precis som före issuen', function () {
    [$ägarkonto, , $headers] = kontoMedMedlem();
    [$container, , $motor] = papperskorgsfilterBåt($ägarkonto);

    $bilaga = papperskorgsBilaga($motor, $ägarkonto, User::factory()->create(), ['filename' => 'faktura.pdf']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    foreach ([$motor, $bilaga, $kategori, $tagg] as $rad) {
        papperskorgsfilterRadera($rad);
    }

    $lista = getJson("/api/containers/{$container->ulid}/trash", $headers);
    $lista->assertOk();
    expect(collect($lista->json('data'))->pluck('type')->sort()->values()->all())
        ->toBe(['attachment', 'category', 'item', 'tag']);

    foreach ([['item', $motor], ['attachment', $bilaga], ['category', $kategori], ['tag', $tagg]] as [$typ, $rad]) {
        postJson("/api/containers/{$container->ulid}/trash/restore", [
            'type' => $typ,
            'ulid' => $rad->ulid,
        ], $headers)->assertOk();
    }

    expect(getJson("/api/containers/{$container->ulid}/trash", $headers)->json('data'))->toBe([]);
});

it('en container-bred delete-innehavare ser och återställer alla fyra typerna', function () {
    [$container, , $motor] = papperskorgsfilterBåt();

    $bilaga = papperskorgsBilaga($motor, $container->account, User::factory()->create(), ['filename' => 'faktura.pdf']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    [$breda, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $breda, null, 'delete');

    foreach ([$motor, $bilaga, $kategori, $tagg] as $rad) {
        papperskorgsfilterRadera($rad);
    }

    $lista = getJson("/api/containers/{$container->ulid}/trash", $headers);
    $lista->assertOk();
    expect(collect($lista->json('data'))->pluck('type')->sort()->values()->all())
        ->toBe(['attachment', 'category', 'item', 'tag']);

    foreach ([['item', $motor], ['attachment', $bilaga], ['category', $kategori], ['tag', $tagg]] as [$typ, $rad]) {
        postJson("/api/containers/{$container->ulid}/trash/restore", [
            'type' => $typ,
            'ulid' => $rad->ulid,
        ], $headers)->assertOk();
    }
});

it('retentionsgränsen gäller oförändrat — ett utgånget item listas inte för någon', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$container, , $motor] = papperskorgsfilterBåt();

        $retention = (int) config('files.trash_retention_days');

        $utgånget = papperskorgsItem($container, $container->account, User::factory()->create(), ['name' => 'Utgånget']);
        $utgånget->deleted_at = now()->subDays($retention + 1);
        $utgånget->save();

        [$mottagare, $headers] = papperskorgsfilterMottagare();
        papperskorgsfilterGrant($container, $mottagare, $utgånget, 'delete');

        // Utgånget innehåll är osynligt för ALLA, oavsett omfång — filtret
        // lägger sig ovanpå omfångsfiltret (issue 74 § Beslut 4).
        expect(papperskorgsfilterUlider(getJson("/api/containers/{$container->ulid}/trash", $headers)))->toBe([]);

        $svar = postJson("/api/containers/{$container->ulid}/trash/restore", [
            'type' => 'item',
            'ulid' => $utgånget->ulid,
        ], $headers);

        $svar->assertStatus(404);
        expect($svar->json('error.code'))->toBe('resource.not_found');

        // Och motorn hon når men inte har raderat ligger förstås inte där.
        expect($motor->fresh()->deleted_at)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('ett mjukraderat föräldraitem behåller sitt omfång — impellern under en raderad motor', function () {
    [$container, , $motor, , $impeller] = papperskorgsfilterBåt();

    // Motorn ligger i papperskorgen, impellern gör det INTE. Upplösningen
    // läser `item_link` och ser mjukraderade rader, så kanten motor →
    // impeller finns kvar och granten på motorn når fortfarande impellern
    // (issue 74 § Beslut 3). Det är precis det beteende en framtida
    // `whereNull('deleted_at')` i slutningen hade ändrat i god tro.
    papperskorgsfilterRadera($motor);

    [$mottagare, $headers] = papperskorgsfilterMottagare();
    papperskorgsfilterGrant($container, $mottagare, $motor, 'delete');

    expect(papperskorgsfilterUlider(getJson("/api/containers/{$container->ulid}/trash", $headers)))
        ->toBe([$motor->ulid]);

    // Och hon kan fortfarande läsa impellern — den ligger under motorn.
    $live = getJson("/api/containers/{$container->ulid}/items", $headers);
    $live->assertOk();
    expect($live->json('data.0.ulid'))->toBe($impeller->ulid);
});
