<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 71 · Grindarna för items, bilagor och relationer — session 1 av 2.
 * Se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och Konsekvenser,
 * [[Konton och åtkomst]] § Behörighetsregler regel 3 och 4, och
 * App\Policies\ItemPolicy.
 *
 * Fram till den här issuen auktoriserade varje skrivgrind som rör innehåll
 * under ett item mot CONTAINERN (ContainerPolicy::update()). Nu mot itemet,
 * och laddern får verkan: `create` lägger till, `write` ändrar, `delete`
 * mjukraderar. Varje "Klart när"-punkt i issuen motsvarar ett namngivet test
 * här; session 2 har ItemberoendeGrindTest.php.
 *
 * Fixturen är omfångsupplösningens (issue 70):
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *
 * Hjälparna är namnrymda för att inte krocka med OmfangsupplosningTest och
 * ItemPolicyTest, som definierar sina egna på filnivå — Pest delar global
 * namnrymd mellan testfilerna.
 *
 * Storage::fake('files') i beforeEach — nedladdningstestet rör bytes, och
 * inga får hamna i den riktiga storage/files/ när sviten körs.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Båten och dess delar i EN container, i ordningen [$container, $båt, $motor,
 * $mast, $impeller]. Ägarkontot kan skickas in så att ett fryst konto kan
 * prövas mot samma fixture.
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item}
 */
function grindFixture(?Account $ägarkonto = null): array
{
    $container = Container::factory()
        ->for($ägarkonto ?? Account::factory()->create(), 'account')
        ->create();

    $skapare = User::factory()->create();

    $item = fn (string $namn) => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $båt = $item('Båten');
    $motor = $item('Motorn');
    $mast = $item('Masten');
    $impeller = $item('Impellern');

    // from = förälder, to = barn — samma kanoniska riktning som LinkItems.
    grindKant($båt, $motor);
    grindKant($båt, $mast);
    grindKant($motor, $impeller);

    return [$container, $båt, $motor, $mast, $impeller];
}

/**
 * En kant skriven DIREKT i tabellen, förbi LinkItems. Fixturen behöver inte
 * gå genom API:et, och en rå insert är bokstavligen den väg förbi
 * cykelkontrollen som upplösningen redan prövas mot i issue 70.
 */
function grindKant(Item $från, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $från->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En itemgrant: en container_access-rad med `item_id` satt, alltså ett
 * omfångsbegränsat item snarare än hela containern.
 */
function grindGrant(Container $container, User $user, Item $item, string $nivå): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * En bilaga på $item, med byten på den fejkade disken — redo att levereras.
 */
function grindBilaga(Item $item, User $user, Account $account, string $innehåll = 'bilagans byten'): Attachment
{
    $storedFile = StoredFile::factory()->create([
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($innehåll),
    ]);

    Storage::disk('files')->put($storedFile->storage_path, $innehåll);

    return Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ]);
}

/**
 * Ett item skapat av en create-mottagare, med `parent` satt till $förälder.
 * Returnerar det nya itemet.
 */
function grindBarnItem(Container $container, Item $förälder, Account $mottagarKonto, array $headers): Item
{
    $svar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Impellerbyte 2026',
        'account' => $mottagarKonto->ulid,
        'parent' => $förälder->ulid,
    ], $headers);

    $svar->assertCreated();

    return Item::query()->where('ulid', $svar->json('data.ulid'))->firstOrFail();
}

// --- create-mottagaren -------------------------------------------------

it('en create-mottagare kan ladda upp en bilaga på sitt item', function () {
    [$container, , $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::CREATE);

    $svar = postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('impellerbyte.pdf', 'kvittot på bytet'),
        'account' => $mottagarKonto->ulid,
    ], $headers);

    $svar->assertCreated();

    expect(Attachment::query()->where('ulid', $svar->json('data.ulid'))->value('item_id'))->toBe($motor->id);
});

it('en create-mottagare kan skapa ett barn-item med parent och länken finns efteråt', function () {
    [$container, , $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::CREATE);

    $nytt = grindBarnItem($container, $motor, $mottagarKonto, $headers);

    expect(DB::table('item_link')
        ->where('from_item_id', $motor->id)
        ->where('to_item_id', $nytt->id)
        ->where('relation', 'parent')
        ->exists())->toBeTrue();

    // Följden ADR:ns § Beslut beskriver: mottagaren utvidgar sitt eget
    // omfång och når det hon själv skapat. Memon i ResolveItemScope är
    // registrerad `scoped()` och överlever därför mellan anropen i EN
    // testprocess — i drift är varje request en egen process. Nollställ den
    // så det andra anropet ser den nya kanten.
    app()->forgetScopedInstances();

    getJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}", $headers)->assertOk();
});

it('en create-mottagare nekas PATCH av ett befintligt item', function () {
    [$container, , $motor] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::CREATE);

    $svar = patchJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", ['name' => 'Ändrad'], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
    expect($motor->fresh()->name)->toBe('Motorn');
});

it('en create-mottagare nekas POST /items utan parent', function () {
    [$container, , $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::CREATE);

    $svar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Lös del',
        'account' => $mottagarKonto->ulid,
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
    expect(Item::query()->where('name', 'Lös del')->exists())->toBeFalse();
});

it('en create-mottagare nekas DELETE av item och av bilaga', function () {
    [$container, , $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::CREATE);
    $bilaga = grindBilaga($motor, $mottagare, $mottagarKonto);

    $itemSvar = deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", [], $headers);
    $itemSvar->assertStatus(403);
    expect($itemSvar->json('error.code'))->toBe('auth.forbidden');

    $bilagaSvar = deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments/{$bilaga->ulid}", [], $headers);
    $bilagaSvar->assertStatus(403);
    expect($bilagaSvar->json('error.code'))->toBe('auth.forbidden');

    expect($motor->fresh()->deleted_at)->toBeNull();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
});

// --- write-mottagaren --------------------------------------------------

it('en write-mottagare får PATCH men nekas DELETE av item och av bilaga', function () {
    [$container, , $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::WRITE);
    $bilaga = grindBilaga($motor, $mottagare, $mottagarKonto);

    $patch = patchJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", ['name' => 'Motorn (servad)'], $headers);
    $patch->assertOk();
    expect($patch->json('data.name'))->toBe('Motorn (servad)');

    $itemSvar = deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", [], $headers);
    $itemSvar->assertStatus(403);
    expect($itemSvar->json('error.code'))->toBe('auth.forbidden');

    $bilagaSvar = deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments/{$bilaga->ulid}", [], $headers);
    $bilagaSvar->assertStatus(403);
    expect($bilagaSvar->json('error.code'))->toBe('auth.forbidden');
});

it('en mottagare med grant på motorn får PATCHa impellern men nekas masten', function () {
    [$container, , $motor, $mast, $impeller] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::WRITE);

    patchJson("/api/containers/{$container->ulid}/items/{$impeller->ulid}", ['name' => 'Impellern (bytt)'], $headers)
        ->assertOk();

    $mastSvar = patchJson("/api/containers/{$container->ulid}/items/{$mast->ulid}", ['name' => 'Masten (kapad)'], $headers);
    $mastSvar->assertStatus(403);
    expect($mastSvar->json('error.code'))->toBe('auth.forbidden');
    expect($mast->fresh()->name)->toBe('Masten');
});

// --- delete-mottagaren -------------------------------------------------

it('en delete-mottagare kan mjukradera item och bilaga', function () {
    [$container, , $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::DELETE);
    $bilaga = grindBilaga($motor, $mottagare, $mottagarKonto);

    // Bilagan först: `{item}` binds genom containern och ett mjukraderat item
    // ger 404 innan någon grind hinner prövas.
    deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments/{$bilaga->ulid}", [], $headers)
        ->assertNoContent();

    deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", [], $headers)
        ->assertNoContent();

    // Mjukradering: deleted_at satt, raden kvar. Ingen ny väg till forceDelete().
    expect($motor->fresh()->deleted_at)->not->toBeNull();
    expect(DB::table('item')->where('id', $motor->id)->exists())->toBeTrue();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('attachment')->where('id', $bilaga->id)->exists())->toBeTrue();
});

// --- relationerna: write i båda ändar ----------------------------------

it('POST links nekas när mottagaren bara når ena änden, utan att motpartens namn läcker', function () {
    [$container, , $motor, $mast] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::WRITE);

    $svar = postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", [
        'item' => $mast->ulid,
        'relation' => 'parent',
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
    expect($svar->getContent())->not->toContain('Masten');
    expect(ItemLink::query()->where('from_item_id', $motor->id)->where('to_item_id', $mast->id)->exists())->toBeFalse();

    // Omvänd riktning: itemet i rutten är det hon inte når. Grinden på $item
    // ligger FÖRE uppslaget av motparten, så svaret bär inget om den.
    $omvänt = postJson("/api/containers/{$container->ulid}/items/{$mast->ulid}/links", [
        'item' => $motor->ulid,
        'relation' => 'parent',
    ], $headers);

    $omvänt->assertStatus(403);
    expect($omvänt->json('error.code'))->toBe('auth.forbidden');
    expect($omvänt->getContent())->not->toContain('Motorn');
});

it('POST links avvisar en okänd ULID som valideringsfel och nekar en känd men onåbar motpart', function () {
    [$container, , $motor, $mast] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::WRITE);

    // Motparten bevisas mot containern redan i StoreItemLinkRequest, så en
    // ULID som inte finns där är ett FÄLTVALIDERINGSfel — samma form som
    // `category` och `parent` i StoreItemRequest. Ingen 404-gren nås.
    $okänd = postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", [
        'item' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        'relation' => 'parent',
    ], $headers);

    $okänd->assertStatus(422);
    expect($okänd->json('error.code'))->toBe('validation.failed');

    // Känd i containern, men utanför omfånget: valideringen passerar, och
    // grinden på $other svarar 403.
    $onåbar = postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", [
        'item' => $mast->ulid,
        'relation' => 'parent',
    ], $headers);

    $onåbar->assertStatus(403);
    expect($onåbar->json('error.code'))->toBe('auth.forbidden');
});

it('DELETE links nekas på samma villkor som POST', function () {
    [$container, , $motor, $mast] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::WRITE);
    grindKant($motor, $mast, 'sibling');

    $svar = deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links/{$mast->ulid}", [], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
    expect($svar->getContent())->not->toContain('Masten');

    // Länken ligger kvar — en nekad grind får inte ha hunnit radera något.
    expect(ItemLink::query()->where('from_item_id', $motor->id)->where('to_item_id', $mast->id)->exists())->toBeTrue();
});

it('en write-mottagare med båda ändarna får länka och knyta upp', function () {
    [$container, , $motor, $mast] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::WRITE);
    grindGrant($container, $mottagare, $mast, AccessLevel::WRITE);

    postJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", [
        'item' => $mast->ulid,
        'relation' => 'sibling',
    ], $headers)->assertCreated();

    deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links/{$mast->ulid}", [], $headers)
        ->assertNoContent();
});

it('en read-mottagare får lista relationerna på sitt item', function () {
    [$container, , $motor, , $impeller] = grindFixture();
    [, $mottagare, $headers] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::READ);

    $svar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers);

    // Motorn har två kanter i fixturen: båten är dess förälder och impellern
    // dess barn. Bara barnet ligger inom omfånget — arvet går uteslutande
    // nedåt, så båten nås inte av en grant på motorn. Att länken till den
    // döljs helt är issue 73 § Beslut 7; fram till dess visades båda.
    $svar->assertOk();
    expect($svar->json('data'))->toHaveCount(1);
    expect($svar->json('data.0.item.name'))->toBe($impeller->name);
});

it('en mottagare utan grant nekas relationerna helt', function () {
    [$container, , $motor] = grindFixture();
    [, , $headers] = kontoMedMedlem();

    $svar = getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/links", $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('auth.forbidden');
});

// --- nedladdningen: filleverans ---------------------------------------

it('nedladdning av en bilaga på ett item utanför omfånget ger 403', function () {
    [$container, , $motor, $mast] = grindFixture();
    [$ägarkonto, $mottagare] = kontoMedMedlem();

    grindGrant($container, $mottagare, $motor, AccessLevel::READ);

    $påMotorn = grindBilaga($motor, $mottagare, $ägarkonto, 'motorns manual');
    $påMasten = grindBilaga($mast, $mottagare, $ägarkonto, 'mastens manual');

    actingAs($mottagare)->get("/files/{$påMotorn->ulid}")->assertOk();

    $svar = actingAs($mottagare)->get("/files/{$påMasten->ulid}");

    $svar->assertForbidden();
});

// --- regel 4: det frysta ägarkontot ------------------------------------

it('ett read_only-ägarkonto nekar allt skrivande oavsett nivå, och läser fortfarande', function () {
    $ägarkonto = Account::factory()->create(['status' => 'read_only']);
    [$container, , $motor] = grindFixture($ägarkonto);
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    // Den HÖGSTA nivån — ändå nekas allt skrivande, för spärren sitter på
    // ägarkontot och ligger ovanpå laddern.
    grindGrant($container, $mottagare, $motor, AccessLevel::DELETE);

    patchJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", ['name' => 'Ändrad'], $headers)
        ->assertStatus(403);

    deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", [], $headers)
        ->assertStatus(403);

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Nytt',
        'account' => $mottagarKonto->ulid,
        'parent' => $motor->ulid,
    ], $headers)->assertStatus(403);

    // Läsning påverkas aldrig av regel 4.
    getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", $headers)->assertOk();
    getJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments", $headers)->assertOk();
});

// --- oförändrat för dem som kunde allt före ----------------------------

it('en ägarkontomedlem kan allt hon kunde före issuen', function () {
    [$ägarkonto, $medlem, $headers] = kontoMedMedlem();
    [$container, $båt] = grindFixture($ägarkonto);

    $skapa = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Ny del',
        'account' => $ägarkonto->ulid,
    ], $headers);
    $skapa->assertCreated();
    $nytt = Item::query()->where('ulid', $skapa->json('data.ulid'))->firstOrFail();

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Barnet',
        'account' => $ägarkonto->ulid,
        'parent' => $nytt->ulid,
    ], $headers)->assertCreated();

    patchJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}", ['name' => 'Ny del (ändrad)'], $headers)
        ->assertOk();

    getJson("/api/containers/{$container->ulid}/items", $headers)->assertOk();

    $bilaga = grindBilaga($nytt, $medlem, $ägarkonto);
    getJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}/attachments", $headers)->assertOk();
    actingAs($medlem)->get("/files/{$bilaga->ulid}")->assertOk();

    deleteJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}/attachments/{$bilaga->ulid}", [], $headers)
        ->assertNoContent();

    postJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}/links", [
        'item' => $båt->ulid,
        'relation' => 'sibling',
    ], $headers)->assertCreated();

    deleteJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}/links/{$båt->ulid}", [], $headers)
        ->assertNoContent();

    deleteJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}", [], $headers)->assertNoContent();
});

it('en container-bred delete-innehavare kan allt hon kunde före issuen', function () {
    [$container, $båt, $motor] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    // Efter migreringen i issue 69 är det här vad en före detta `write`-rad
    // blev — hon kunde radera items och bilagor och ska fortfarande kunna det.
    beviljaAccess($container, $mottagare, AccessLevel::DELETE, 'member');

    $skapa = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Verkstadens del',
        'account' => $mottagarKonto->ulid,
    ], $headers);
    $skapa->assertCreated();
    $nytt = Item::query()->where('ulid', $skapa->json('data.ulid'))->firstOrFail();

    patchJson("/api/containers/{$container->ulid}/items/{$motor->ulid}", ['name' => 'Motorn (servad)'], $headers)
        ->assertOk();

    $bilaga = grindBilaga($motor, $mottagare, $mottagarKonto);
    deleteJson("/api/containers/{$container->ulid}/items/{$motor->ulid}/attachments/{$bilaga->ulid}", [], $headers)
        ->assertNoContent();

    postJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}/links", [
        'item' => $båt->ulid,
        'relation' => 'sibling',
    ], $headers)->assertCreated();

    deleteJson("/api/containers/{$container->ulid}/items/{$nytt->ulid}", [], $headers)->assertNoContent();
});

it('en container-bred create-innehavare skapar ett toppnivå-item men nekas PATCH', function () {
    [$container] = grindFixture();
    [$mottagarKonto, $mottagare, $headers] = kontoMedMedlem();

    beviljaAccess($container, $mottagare, AccessLevel::CREATE, 'member');

    $skapa = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Verkstadens del',
        'account' => $mottagarKonto->ulid,
    ], $headers);
    $skapa->assertCreated();

    patchJson("/api/containers/{$container->ulid}/items/{$skapa->json('data.ulid')}", ['name' => 'Ändrad'], $headers)
        ->assertStatus(403);
});
