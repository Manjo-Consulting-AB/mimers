<?php

use App\Actions\Attachment\TrashAttachment;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 109 · Innehållshändelserna. Varje skrivning på ett item och det som
 * hänger på det loggas i händelseloggen: itemet självt, taggarna,
 * relationerna, bilagorna och kostnadsraderna — genom
 * App\Actions\Audit\RecordAuditEvent, med `item_id` satt, i handlingens
 * transaktion. Se [[ADR-0043 Tre loggar]] § Händelseloggen och
 * App\Models\AuditLog för handlingarnas namn.
 *
 * Två saker prövas i nästan varje test och är lätta att tappa:
 *
 * 1. **Webben och `/api` skriver samma rad.** Items och relationer skrivs i
 *    båda ytorna, och instrumenteras de var för sig glider de isär. Sedan
 *    issue 109 går båda genom samma Action — App\Actions\Item\CreateItem,
 *    UpdateItem, DeleteItem, LinkItems och UnlinkItems.
 * 2. **Fritext följer aldrig med.** Raden säger VILKA fält som ändrades,
 *    inte vad som stod där. Namn, beskrivning, anteckning, filnamn och
 *    leverantör får bara finnas som fältnamn.
 *
 * kontoMedMedlem() är en global testhjälpare i tests/Support/Testhjalpare.php.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Ett ägarkonto med en medlem, dess API-headers, och en container ägd av
 * kontot.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function innehallsKontext(): array
{
    [$konto, $anvandare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $headers, $container];
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function innehallsItem(Container $container, User $skapare, string $namn = 'Motorn', array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * Rader i händelseloggen för ett item och en handling.
 *
 * @return Collection<int, stdClass>
 */
function innehallsRader(Item $item, string $action): Collection
{
    return DB::table('audit_log')
        ->where('item_id', $item->id)
        ->where('action', $action)
        ->get();
}

/**
 * Den enda raden för ett item och en handling, som stdClass.
 */
function innehallsRad(Item $item, string $action): stdClass
{
    $rader = innehallsRader($item, $action);

    expect($rader)->toHaveCount(1);

    return $rader->first();
}

it('varje skrivning på ett item skriver exakt en rad via webben och via API:t', function () {
    [$konto, $anvandare, $headers, $container] = innehallsKontext();

    /*
     * Webben: skapa, ändra, radera, återställ — fyra handlingar, fyra rader.
     */
    actingAs($anvandare)->post("/containers/{$container->ulid}/items", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
    ])->assertRedirect();

    $webb = Item::query()->where('container_id', $container->id)->firstOrFail();

    actingAs($anvandare)->patch("/containers/{$container->ulid}/items/{$webb->ulid}", [
        'name' => 'Nya motorn',
        'account' => $konto->ulid,
    ])->assertRedirect();

    actingAs($anvandare)->delete("/containers/{$container->ulid}/items/{$webb->ulid}")->assertRedirect();

    actingAs($anvandare)->post("/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $webb->ulid,
    ])->assertRedirect();

    expect(innehallsRader($webb, AuditLog::ACTION_ITEM_CREATED))->toHaveCount(1);
    expect(innehallsRader($webb, AuditLog::ACTION_ITEM_UPDATED))->toHaveCount(1);
    expect(innehallsRader($webb, AuditLog::ACTION_ITEM_DELETED))->toHaveCount(1);
    expect(innehallsRader($webb, AuditLog::ACTION_ITEM_RESTORED))->toHaveCount(1);
    expect(DB::table('audit_log')->where('item_id', $webb->id)->count())->toBe(4);
    expect($webb->fresh()->deleted_at)->toBeNull();

    /*
     * API:t: samma fyra skrivningar, samma fyra rader.
     */
    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'Pumpen',
        'account' => $konto->ulid,
    ], $headers)->assertCreated();

    $api = Item::query()->where('container_id', $container->id)->where('name', 'Pumpen')->firstOrFail();

    patchJson("/api/containers/{$container->ulid}/items/{$api->ulid}", [
        'name' => 'Nya pumpen',
    ], $headers)->assertOk();

    deleteJson("/api/containers/{$container->ulid}/items/{$api->ulid}", [], $headers)->assertNoContent();

    postJson("/api/containers/{$container->ulid}/trash/restore", [
        'type' => 'item',
        'ulid' => $api->ulid,
    ], $headers)->assertOk();

    expect(innehallsRader($api, AuditLog::ACTION_ITEM_CREATED))->toHaveCount(1);
    expect(innehallsRader($api, AuditLog::ACTION_ITEM_UPDATED))->toHaveCount(1);
    expect(innehallsRader($api, AuditLog::ACTION_ITEM_DELETED))->toHaveCount(1);
    expect(innehallsRader($api, AuditLog::ACTION_ITEM_RESTORED))->toHaveCount(1);
    expect(DB::table('audit_log')->where('item_id', $api->id)->count())->toBe(4);

    /*
     * Varje rad bär item_id, container_id, account_id och den handlande
     * användaren — ingen rad är en systemhändelse när en användare handlade.
     */
    expect(DB::table('audit_log')->whereNull('item_id')->count())->toBe(0);

    foreach (DB::table('audit_log')->get() as $rad) {
        expect($rad->item_id)->not->toBeNull();
        expect($rad->container_id)->toBe($container->id);
        expect($rad->account_id)->toBe($konto->id);
        expect($rad->user_id)->toBe($anvandare->id);
    }
});

it('relationer och taggar på ett item skriver en rad', function () {
    [$konto, $anvandare, $headers, $container] = innehallsKontext();
    $motorn = innehallsItem($container, $anvandare, 'Motorn');
    $pumpen = innehallsItem($container, $anvandare, 'Pumpen');
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);

    // Relationen knyts via webben: en rad, med `item_id` på itemet anropet
    // gällde och parets båda ULID:er i `meta`.
    actingAs($anvandare)->post("/containers/{$container->ulid}/items/{$motorn->ulid}/links", [
        'item' => $pumpen->ulid,
        'relation' => 'child',
    ])->assertRedirect();

    $skapad = innehallsRad($motorn, AuditLog::ACTION_ITEM_LINK_CREATED);
    $meta = json_decode($skapad->meta, true);
    expect($meta['relation'])->toBe('parent');
    expect($meta['from'])->toBe($motorn->ulid);
    expect($meta['to'])->toBe($pumpen->ulid);
    expect($skapad->user_id)->toBe($anvandare->id);

    // ... och löses upp via API:t: samma Action, alltså samma rad.
    deleteJson("/api/containers/{$container->ulid}/items/{$motorn->ulid}/links/{$pumpen->ulid}", [], $headers)
        ->assertNoContent();

    $borttagen = innehallsRad($motorn, AuditLog::ACTION_ITEM_LINK_DELETED);
    expect(json_decode($borttagen->meta, true))->toBe($meta);

    /*
     * Taggmängden. `meta` bär ULID:er — aldrig taggens namn, som är
     * användarens fritext.
     */
    actingAs($anvandare)->patch("/containers/{$container->ulid}/items/{$motorn->ulid}", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'tags' => [$tagg->ulid],
    ])->assertRedirect();

    $taggRad = innehallsRad($motorn, AuditLog::ACTION_ITEM_TAGS_CHANGED);
    $taggMeta = json_decode($taggRad->meta, true);
    expect($taggMeta['added'])->toBe([$tagg->ulid]);
    expect($taggMeta['removed'])->toBe([]);
    expect($taggRad->meta)->not->toContain('Vinter');
    expect($taggRad->item_id)->toBe($motorn->id);

    // Tömningen är också en ändring, och skriver sin egen rad.
    actingAs($anvandare)->patch("/containers/{$container->ulid}/items/{$motorn->ulid}", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'tags' => [],
    ])->assertRedirect();

    expect(innehallsRader($motorn, AuditLog::ACTION_ITEM_TAGS_CHANGED))->toHaveCount(2);
    expect(json_decode(innehallsRader($motorn, AuditLog::ACTION_ITEM_TAGS_CHANGED)->last()->meta, true))
        ->toBe(['added' => [], 'removed' => [$tagg->ulid]]);
});

it('en bilaga som laddas upp eller raderas skriver en rad med kind men utan filnamn', function () {
    [$konto, $anvandare, $headers, $container] = innehallsKontext();
    $item = innehallsItem($container, $anvandare);

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    postJson($url, [
        'file' => UploadedFile::fake()->createWithContent('victron-manual.pdf', 'innehållet'),
        'account' => $konto->ulid,
    ], $headers)->assertCreated();

    $skapad = innehallsRad($item, AuditLog::ACTION_ATTACHMENT_CREATED);
    expect($skapad->item_id)->toBe($item->id);
    expect($skapad->user_id)->toBe($anvandare->id);
    expect(json_decode($skapad->meta, true))->toBe(['kind' => 'document']);
    expect($skapad->meta)->not->toContain('victron-manual');

    $bilaga = Attachment::query()->where('item_id', $item->id)->firstOrFail();

    deleteJson("{$url}/{$bilaga->ulid}", [], $headers)->assertNoContent();

    $raderad = innehallsRad($item, AuditLog::ACTION_ATTACHMENT_DELETED);
    expect($raderad->item_id)->toBe($item->id);
    expect($raderad->user_id)->toBe($anvandare->id);
    expect(json_decode($raderad->meta, true))->toBe(['kind' => 'document']);
    expect($raderad->meta)->not->toContain('victron-manual');
});

it('en kostnadsrad skriver en rad med belopp och valuta men utan beskrivning och leverantör', function () {
    [$konto, $anvandare, $headers, $container] = innehallsKontext();
    $item = innehallsItem($container, $anvandare);

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    postJson($url, [
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'SEK',
        'description' => 'Hemlig beskrivning',
        'supplier' => 'Hemlig leverantör',
    ], $headers)->assertCreated();

    $skapad = innehallsRad($item, AuditLog::ACTION_COST_ENTRY_CREATED);
    expect($skapad->item_id)->toBe($item->id);
    expect($skapad->user_id)->toBe($anvandare->id);
    expect(json_decode($skapad->meta, true))->toBe(['amount' => 120050, 'currency' => 'SEK']);
    expect($skapad->meta)->not->toContain('Hemlig');

    $kostnad = CostEntry::query()->where('item_id', $item->id)->firstOrFail();

    // Ändringen: beloppet följer med som gammalt och nytt värde,
    // beskrivningen bara som namn.
    patchJson("{$url}/{$kostnad->ulid}", [
        'amount' => '1500',
        'currency' => 'SEK',
        'description' => 'Ny hemlig beskrivning',
    ], $headers)->assertOk();

    $ändrad = innehallsRad($item, AuditLog::ACTION_COST_ENTRY_UPDATED);
    $meta = json_decode($ändrad->meta, true);
    expect($meta['changed'])->toContain('amount')->toContain('description');
    expect($meta['values'])->toBe(['amount' => ['from' => 120050, 'to' => 150000]]);
    expect($ändrad->meta)->not->toContain('Hemlig');

    deleteJson("{$url}/{$kostnad->ulid}", [], $headers)->assertNoContent();

    $raderad = innehallsRad($item, AuditLog::ACTION_COST_ENTRY_DELETED);
    expect($raderad->item_id)->toBe($item->id);
    expect($raderad->meta)->not->toContain('Hemlig');
});

it('en ändring loggar fältens namn och aldrig fritext', function () {
    [$konto, $anvandare, $headers, $container] = innehallsKontext();
    $item = innehallsItem($container, $anvandare, 'Motorn', ['notes' => 'den gamla anteckningen']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Verktyg']);

    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Nya motorn',
        'description' => 'en beskrivning',
        'notes' => 'en anteckning',
        'purchased_at' => '2024-05-17',
        'category' => $kategori->ulid,
    ], $headers)->assertOk();

    $rad = innehallsRad($item, AuditLog::ACTION_ITEM_UPDATED);
    $meta = json_decode($rad->meta, true);

    expect($meta['changed'])->toEqualCanonicalizing(['name', 'description', 'notes', 'purchased_at', 'category']);

    // Datumet och referensen följer med som gamla och nya värden — kategorin
    // som ULID, aldrig som löpnummer.
    expect($meta['values']['purchased_at'])->toBe(['from' => null, 'to' => '2024-05-17']);
    expect($meta['values']['category'])->toBe(['from' => null, 'to' => $kategori->ulid]);

    // Innehållet gör det aldrig.
    expect($rad->meta)->not->toContain('Nya motorn');
    expect($rad->meta)->not->toContain('en beskrivning');
    expect($rad->meta)->not->toContain('en anteckning');
    expect($rad->meta)->not->toContain('den gamla anteckningen');

    // Redigeringen tog: raden beskriver en ändring som faktiskt hände.
    expect($item->fresh()->name)->toBe('Nya motorn');
});

it('en ändring som inte ändrar något skriver ingen rad', function () {
    [$konto, $anvandare, $headers, $container] = innehallsKontext();
    $item = innehallsItem($container, $anvandare, 'Motorn', ['notes' => 'anteckningen']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->tags()->attach($tagg->id);

    expect(DB::table('audit_log')->count())->toBe(0);

    // Samma värden och samma taggmängd som förut: ingen ändring, ingen rad.
    patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Motorn',
        'notes' => 'anteckningen',
        'tags' => [$tagg->ulid],
    ], $headers)->assertOk();

    expect(DB::table('audit_log')->count())->toBe(0);

    // Samma sak via webben, som alltid skickar hela taggmängden.
    actingAs($anvandare)->patch("/containers/{$container->ulid}/items/{$item->ulid}", [
        'name' => 'Motorn',
        'account' => $konto->ulid,
        'tags' => [$tagg->ulid],
    ])->assertRedirect();

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('en handling som rullas tillbaka skriver ingen rad', function () {
    [, $anvandare, $headers, $container] = innehallsKontext();
    $item = innehallsItem($container, $anvandare, 'Motorn');

    try {
        DB::transaction(function () use ($item, $container, $headers): void {
            patchJson("/api/containers/{$container->ulid}/items/{$item->ulid}", [
                'name' => 'Nya motorn',
            ], $headers)->assertOk();

            // Ändringen och loggraden skrevs i samma transaktion — den här
            // rullar tillbaka båda.
            throw new RuntimeException('rulla tillbaka ändringen');
        });
    } catch (RuntimeException) {
        // förväntad — transaktionen rullades tillbaka
    }

    expect(DB::table('audit_log')->count())->toBe(0);
    expect($item->fresh()->name)->toBe('Motorn');
});

it('en borttagning ett jobb gör skriver en rad utan användare', function () {
    [, $anvandare, , $container] = innehallsKontext();
    $item = innehallsItem($container, $anvandare);

    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'uploaded_by_user_id' => $anvandare->id,
        'billed_account_id' => $container->account_id,
    ]);

    // Nedgraderingens jobb (App\Console\EnforcesDowngrades) går genom samma
    // Action men har ingen användare att skicka med.
    app(TrashAttachment::class)->handle($bilaga);

    $rad = innehallsRad($item, AuditLog::ACTION_ATTACHMENT_DELETED);
    expect($rad->user_id)->toBeNull();
    expect($rad->item_id)->toBe($item->id);
    expect($rad->container_id)->toBe($container->id);
});
