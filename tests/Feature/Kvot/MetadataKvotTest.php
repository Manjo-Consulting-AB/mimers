<?php

// rott-pa-basen: provet gäller kvoten, inte inboxen; den andra halvan flyttas till en vanlig container och är grön även utan implementationen.

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\CreateItem;
use App\Actions\Item\DeleteItem;
use App\Actions\Item\UpdateItem;
use App\Actions\Plan\ReadPlanUsage;
use App\Actions\Trash\PurgeContent;
use App\Actions\Trash\RestoreContent;
use App\Actions\Usage\AdjustUsage;
use App\Console\GeneratesQuotaWarnings;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Usage\CountedRows;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * Issue 1303 · Kvoten gäller filer och text tillsammans
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 1 och 5). Förbrukningen
 * är `UsageCounter::usedBytes()`; text prövas i MetadataLedger genom
 * Entitlements::assertMetadataWithinLimit().
 *
 * Gratisplanens `storage_bytes` sänks till ett litet tal, och räknarna sätts
 * med AdjustUsage så att provet styr exakt hur mycket konton förbrukar.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Ett konto med ägare, gratisplan sänkt till $tak, och förbrukning satt.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container}
 */
function textKvotKonto(int $tak, int $filer = 0, int $text = 0): array
{
    sättPlangräns('free', 'storage_bytes', $tak);
    [$konto, $användare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    if ($filer > 0 || $text > 0) {
        (new AdjustUsage)->handle($konto->id, bytesDelta: $filer, metadataDelta: $text);
    }

    return [$konto, $användare, $headers, $container];
}

/**
 * Ett item skapat genom CreateItem, så att räknaren följer med. Vikten är
 * 256 + namnets byte.
 */
function textKvotItem(Container $container, Account $konto, User $användare, string $namn): Item
{
    return app(CreateItem::class)->handle(
        $container, $konto, $användare, new Item(['name' => $namn]), null, collect(), null,
    );
}

it('en uppladdning nekas när filer och text tillsammans går över gränsen, fast filerna ensamma ryms', function () {
    [$konto, , $headers, $container] = textKvotKonto(1000, filer: 600, text: 600);
    $item = Item::factory()->for($container, 'container')->create();

    $svar = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('liten.pdf', str_repeat('a', 100)),
        'account' => $konto->ulid,
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.storage_exceeded');
    expect($svar->json('error.data'))->toBe(['limit_bytes' => 1000, 'used_bytes' => 1200, 'file_bytes' => 100]);
});

it('samma uppladdning går igenom när texten inte lägger sig ovanpå', function () {
    [$konto, , $headers, $container] = textKvotKonto(1000, filer: 600, text: 0);
    $item = Item::factory()->for($container, 'container')->create();

    postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('liten.pdf', str_repeat('a', 100)),
        'account' => $konto->ulid,
    ], $headers)->assertCreated();
});

it('ett item som tar kontot över gränsen nekas på /api med added_bytes', function () {
    [$konto, , $headers, $container] = textKvotKonto(1000, filer: 900);

    $svar = postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'A',
        'account' => $konto->ulid,
    ], $headers);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.storage_exceeded');
    expect($svar->json('error.data'))->toBe(['limit_bytes' => 1000, 'used_bytes' => 900, 'added_bytes' => 257]);
    expect(Item::query()->count())->toBe(0);
    expect(UsageCounter::usedBytes($konto->id))->toBe(900);
});

it('ett item som rymmer sig exakt i kvoten går igenom', function () {
    [$konto, , $headers, $container] = textKvotKonto(1000, filer: 743);

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'A',
        'account' => $konto->ulid,
    ], $headers)->assertCreated();

    expect(UsageCounter::usedBytes($konto->id))->toBe(1000);
});

it('på webben blir kvotfelet ett fältfel med den nya meningen', function () {
    [$konto, $användare, , $container] = textKvotKonto(1000, filer: 900);

    actingAs($användare)
        ->post("/containers/{$container->ulid}/items", ['name' => 'A', 'account' => $konto->ulid])
        ->assertSessionHasErrors([
            'name' => 'The storage is full. The account has 900 B of 1 KB, and this change adds 257 B.',
        ]);

    expect(Item::query()->count())->toBe(0);
});

it('en gräns som är null betyder obegränsat', function () {
    [$konto, , $headers, $container] = textKvotKonto(1000, filer: 900);
    $plan = Plan::where('code', 'free')->firstOrFail();
    $limits = $plan->limits;
    $limits['storage_bytes'] = null;
    $plan->update(['limits' => $limits]);

    postJson("/api/containers/{$container->ulid}/items", [
        'name' => 'A',
        'account' => $konto->ulid,
    ], $headers)->assertCreated();
});

it('en ändring som växer över gränsen nekas, och en som krymper går igenom även när kontot ligger över', function () {
    [$konto, $användare, , $container] = textKvotKonto(100000);
    $item = textKvotItem($container, $konto, $användare, 'Motor');

    // Kontot ligger nu över gränsen.
    sättPlangräns('free', 'storage_bytes', UsageCounter::usedBytes($konto->id) - 1);

    $item->fill(['name' => 'Motorn']);

    try {
        app(UpdateItem::class)->handle($item, $användare, false, collect());
        $kastade = null;
    } catch (ApiException $e) {
        $kastade = $e;
    }

    expect($kastade)->not->toBeNull();
    expect($kastade->errorCode())->toBe('quota.storage_exceeded');
    expect($kastade->data()['added_bytes'])->toBe(1);
    expect(Item::query()->find($item->id)->name)->toBe('Motor');

    $item = Item::query()->find($item->id);
    $item->fill(['name' => 'M']);
    app(UpdateItem::class)->handle($item, $användare, false, collect());

    expect(Item::query()->find($item->id)->name)->toBe('M');
});

it('att slänga, återställa och radera går igenom när kontot ligger över', function () {
    [$konto, $användare, , $container] = textKvotKonto(100000);
    $item = textKvotItem($container, $konto, $användare, 'Motor');

    sättPlangräns('free', 'storage_bytes', 10);
    expect(UsageCounter::usedBytes($konto->id))->toBeGreaterThan(10);

    app(DeleteItem::class)->handle($item, $användare);
    expect(Item::onlyTrashed()->count())->toBe(1);

    app(RestoreContent::class)->handle('item', Item::withTrashed()->find($item->id), $användare);
    expect(Item::query()->count())->toBe(1);

    app(DeleteItem::class)->handle(Item::query()->find($item->id), $användare);
    app(PurgeContent::class)->item(Item::withTrashed()->find($item->id));

    expect(Item::withTrashed()->count())->toBe(0);
    expect(UsageCounter::usedBytes($konto->id))->toBe(0);
});

it('en medlem som skriver i någon annans container prövas mot sin egen kvot, inte ägarens', function () {
    [$ägare, $ägarUser, , $container] = textKvotKonto(1000);
    [$skrivarKonto, $skrivare] = kontoMedMedlem();
    beviljaAccess($container, $skrivare, 'write', 'member');

    // Ägaren ligger på gränsen, skrivaren har gott om plats.
    (new AdjustUsage)->handle($ägare->id, metadataDelta: 1000);

    textKvotItem($container, $skrivarKonto, $skrivare, 'A');
    expect(Item::query()->where('billed_account_id', $skrivarKonto->id)->count())->toBe(1);
    expect(UsageCounter::usedBytes($ägare->id))->toBe(1000);

    // Nu tvärtom: skrivaren fyller sin egen kvot, ägaren har plats.
    (new AdjustUsage)->handle($skrivarKonto->id, metadataDelta: 1000);
    (new AdjustUsage)->handle($ägare->id, metadataDelta: -1000);

    expect(fn () => textKvotItem($container, $skrivarKonto, $skrivare, 'B'))
        ->toThrow(ApiException::class);
    expect(UsageCounter::usedBytes($ägare->id))->toBe(0);
});

it('planvyn och kvotvarningarna räknar med summan av filer och text', function () {
    [$konto, $användare] = textKvotKonto(1000, filer: 400, text: 500);

    $användning = app(ReadPlanUsage::class)->handle($konto);
    expect($användning['usage']['storage']['usedBytes'])->toBe(900);

    (new GeneratesQuotaWarnings)->handle();

    $varning = Notification::query()
        ->where('account_id', $konto->id)
        ->where('type', Notification::TYPE_QUOTA_WARNING)
        ->first();

    // 900 av 1000 är över varje tröskel som finns; filerna ensamma (400) är
    // under dem alla.
    expect($varning)->not->toBeNull();
    expect($varning->payload['used_bytes'])->toBe(900);
});

it('ett ägarbyte nekas när mottagaren inte rymmer bilagorna och raderna tillsammans', function () {
    [$säljare, $säljarUser, $säljarHeaders, $container] = textKvotKonto(5000);
    $item = textKvotItem($container, $säljare, $säljarUser, 'Motor');
    $lagrad = StoredFile::factory()->create(['byte_size' => 1000]);
    Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $lagrad->id,
        'billed_account_id' => $säljare->id,
    ]);
    (new AdjustUsage)->handle($säljare->id, bytesDelta: 1000);

    [$köpare, , $köparHeaders] = kontoMedMedlem();
    // Bilagan (1000) ensam ryms, raderna (container + item) gör att den inte gör det.
    $rader = UsageCounter::usedBytes($säljare->id) - 1000;
    expect($rader)->toBeGreaterThan(256);
    (new AdjustUsage)->handle($köpare->id, bytesDelta: 5000 - 1000 - 100);

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köpare->id, 'to_email' => null]);

    $svar = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $svar->assertStatus(403);
    expect($svar->json('error.code'))->toBe('quota.storage_exceeded');
    // Bilagor och rader tillsammans; ingen enskild fil, så ingen `file_bytes`.
    expect($svar->json('error.data'))->toBe([
        'limit_bytes' => 5000,
        'used_bytes' => 5000 - 1000 - 100,
        'added_bytes' => 1000 + $rader + CountedRows::containerBytes($container->id),
    ]);
    expect($container->fresh()->account_id)->toBe($säljare->id);
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
});

it('på webben blir kvotfelet ett fältfel även när ett item ändras', function () {
    [$konto, $användare, , $container] = textKvotKonto(100000);
    $item = textKvotItem($container, $konto, $användare, 'Motor');
    sättPlangräns('free', 'storage_bytes', UsageCounter::usedBytes($konto->id));

    actingAs($användare)
        ->patch("/containers/{$container->ulid}/items/{$item->ulid}", ['name' => 'Motorn'])
        ->assertSessionHasErrors('name');

    expect(Item::query()->find($item->id)->name)->toBe('Motor');
});

it('på webben blir kvotfelet ett fältfel när en tagg skapas', function () {
    [, $användare, , $container] = textKvotKonto(10);

    actingAs($användare)
        ->post("/containers/{$container->ulid}/tags", ['name' => 'Verkstad'])
        ->assertSessionHasErrors('name');

    expect(DB::table('tag')->count())->toBe(0);
});

it('på webben blir kvotfelet ett fältfel när ett lån skapas', function () {
    [$konto, $användare, , $container] = textKvotKonto(100000);
    $item = textKvotItem($container, $konto, $användare, 'Motor');
    sättPlangräns('free', 'storage_bytes', UsageCounter::usedBytes($konto->id));

    actingAs($användare)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/loans", ['borrower_name' => 'Kalle', 'lent_at' => '2026-11-01'])
        ->assertSessionHasErrors('borrower_name');

    expect(DB::table('loan')->count())->toBe(0);
});

it('att återställa en bilaga går igenom när kontot ligger över', function () {
    [$konto, $användare, , $container] = textKvotKonto(1000, filer: 600, text: 600);
    $item = Item::factory()->for($container, 'container')->create();
    $lagrad = StoredFile::factory()->create(['byte_size' => 100]);
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $lagrad->id,
        'billed_account_id' => $konto->id,
        'deleted_at' => now(),
    ]);

    app(RestoreContent::class)->handle('attachment', Attachment::withTrashed()->find($bilaga->id), $användare);

    expect(Attachment::query()->find($bilaga->id))->not->toBeNull();
});

it('nedgraderingsförhandsvisningen räknar med summan av filer och text', function () {
    [$konto] = textKvotKonto(1000, filer: 400, text: 800);

    $användning = app(ReadPlanUsage::class)->handle($konto);

    // Filerna ensamma (400) ryms; filer och text (1200) gör det inte.
    expect($användning['downgrade']['overBytes'])->toBe(200);
});

it('ett konto över gränsen kan lösa ut sin inbox, men nekas ett item med text i en vanlig container', function () {
    [$konto, $användare, , $container] = textKvotKonto(1000, filer: 1000);

    $inbox = app(ResolveInbox::class)->handle($användare);

    expect($inbox->exists)->toBeTrue();
    expect(UsageCounter::usedBytes($konto->id))->toBeGreaterThan(1000);

    $fångad = null;
    try {
        app(CreateItem::class)->handle(
            $container, $konto, $användare, new Item(['name' => 'Text']), null, collect(), null,
        );
    } catch (ApiException $e) {
        $fångad = $e;
    }

    expect($fångad?->errorCode())->toBe('quota.storage_exceeded');
});
