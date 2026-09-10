<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;

/*
 * Issue 16b · Bilagelistan och mjukraderingen. Se
 * App\Http\Controllers\Api\AttachmentController (index/destroy),
 * App\Models\Item::attachments(), routes/api.php och
 * App\Http\Resources\AttachmentResource (oförändrad från 16a).
 *
 * kontoMedMedlem() och beviljaAccess() återanvänds via Pests globala
 * namnrymd, samma mönster som UppladdningTest. Varje "Klart när"-punkt i
 * issuen motsvarar ett namngivet test här.
 *
 * Inga bytes skrivs i de här testerna utom där ett test själv stoppar in
 * dem — Storage::fake('files') i beforeEach ser till att ingenting hamnar i
 * den riktiga storage/files/ när sviten körs, samma mönster som 16a.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Skapar en bilaga direkt med fabrikerna på itemet. `$attribut` kan
 * överstyra `created_at`, `stored_file_id` med mera.
 */
function skapaBilagaPåItem(Item $item, Account $account, User $user, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

it('listar itemets bilagor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // Frys tiden och sätt created_at explicit så ordningen blir deterministisk.
    Carbon::setTestNow('2026-09-02 12:00:00');

    $äldst = skapaBilagaPåItem($item, $account, $user, ['created_at' => now()->subMinutes(2)]);
    $mellan = skapaBilagaPåItem($item, $account, $user, ['created_at' => now()->subMinute()]);
    $nyast = skapaBilagaPåItem($item, $account, $user, ['created_at' => now()]);
    // Samma sekund som $nyast — andrasorteringen på id ska ge en stabil ordning.
    $senareSammaSekund = skapaBilagaPåItem($item, $account, $user, ['created_at' => now()]);

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(4);
    expect(collect($response->json('data'))->pluck('ulid')->all())->toBe([
        $senareSammaSekund->ulid,
        $nyast->ulid,
        $mellan->ulid,
        $äldst->ulid,
    ]);

    Carbon::setTestNow();
});

it('listningen visar aldrig en annan items bilagor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $itemA = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $itemB = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $bilagaPåA = skapaBilagaPåItem($itemA, $account, $user);
    skapaBilagaPåItem($itemB, $account, $user);

    $response = getJson("/api/containers/{$container->ulid}/items/{$itemA->ulid}/attachments", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ulid'))->toBe($bilagaPåA->ulid);
});

it('mjukraderade bilagor syns inte i listningen', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $account, $user);
    $bilaga->delete();

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", $headers);

    $response->assertOk();
    $response->assertJsonPath('data', []);
    // Raden ligger KVAR i databasen — grunden för papperskorgen i 20a.
    expect(DB::table('attachment')->where('id', $bilaga->id)->exists())->toBeTrue();
});

it('radering är mjuk', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $account, $user);

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);

    $response->assertNoContent();
    expect(DB::table('attachment')->where('id', $bilaga->id)->exists())->toBeTrue();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
});

it('raderingen minskar inte reference_count', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $storedFile = StoredFile::factory()->create(['reference_count' => 3]);
    $bilaga = skapaBilagaPåItem($item, $account, $user, ['stored_file_id' => $storedFile->id]);

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);

    $response->assertNoContent();
    // Räknaren minskas först när bilagan lämnar papperskorgen, issue 17a —
    // aldrig vid själva mjukraderingen (Beslut 4).
    expect(DB::table('stored_file')->where('id', $storedFile->id)->value('reference_count'))->toBe(3);
});

it('raderingen tar inte bort bytena', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $storedFile = StoredFile::factory()->create();
    Storage::disk('files')->put($storedFile->storage_path, 'kvittot som ska ligga kvar');
    $bilaga = skapaBilagaPåItem($item, $account, $user, ['stored_file_id' => $storedFile->id]);

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);

    $response->assertNoContent();
    // Bytena ligger kvar — mjukraderingen rör varken stored_file-rader eller disk.
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
    expect(Storage::disk('files')->get($storedFile->storage_path))->toBe('kvittot som ska ligga kvar');
});

it('en redan raderad bilaga ger 404', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $account, $user);
    $bilaga->delete();

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('en bilaga på ett annat item kan inte raderas via detta item', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $itemA = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $itemB = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $bilagaPåB = skapaBilagaPåItem($itemB, $account, $user);

    $response = deleteJson("/api/containers/{$container->ulid}/items/{$itemA->ulid}/attachments/{$bilagaPåB->ulid}", [], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
    // Bilagan på item B rördes inte.
    expect(DB::table('attachment')->where('id', $bilagaPåB->id)->value('deleted_at'))->toBeNull();
});

it('en read-deltagare får lista men inte radera', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $egetKonto, $user);

    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", $headers)->assertOk();

    $radera = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
});

it('en write-deltagare får inte radera', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $egetKonto, $user);

    // Sedan issue 71 kräver raderingen `delete` på itemet; `write` räcker för
    // att ändra men inte för att ta bort, se [[ADR-0028 Åtkomst på itemnivå]]
    // § Beslut.
    $radera = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
});

it('en delete-deltagare får radera', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'delete', 'member');
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $egetKonto, $user);

    deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers)->assertNoContent();
    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
});

it('en användare utan åtkomst nekas', function () {
    [$egetKonto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $egetKonto->id,
    ]);
    $bilaga = skapaBilagaPåItem($item, $egetKonto, $user);

    $lista = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", $headers);
    $lista->assertStatus(403);
    expect($lista->json('error.code'))->toBe('auth.forbidden');

    $radera = deleteJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments/{$bilaga->ulid}", [], $headers);
    $radera->assertStatus(403);
    expect($radera->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $bilaga = Attachment::factory()->for($item, 'item')->create();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    $lista = getJson($url);
    $lista->assertStatus(401);
    expect($lista->json('error.code'))->toBe('auth.unauthenticated');

    $radera = deleteJson("{$url}/{$bilaga->ulid}");
    $radera->assertStatus(401);
    expect($radera->json('error.code'))->toBe('auth.unauthenticated');
});

it('listningen gör ett konstant antal frågor', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // Varje bilaga får sin egen stored_file och sitt eget faktureringskonto
    // via fabrikens standardvärden — en N+1 på någon av de relationer
    // AttachmentResource läser skulle växa med antalet bilagor.
    $skapa = fn () => Attachment::factory()->for($item, 'item')->create();

    $skapa();
    $skapa();
    $skapa();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80), samma mönster som ItemTaggTest.
    Carbon::setTestNow(now());

    // Värm Sanctum-guarden med ett omätt anrop.
    getJson($url, $headers)->assertOk();

    DB::enableQueryLog();
    getJson($url, $headers)->assertOk();
    $frågorMedTreBilagor = count(DB::getQueryLog());
    DB::flushQueryLog();

    $skapa();
    $skapa();
    $skapa();
    DB::flushQueryLog(); // rensa bort fabrikernas egna INSERT-frågor före mätningen

    getJson($url, $headers)->assertOk();
    $frågorMedSexBilagor = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($frågorMedSexBilagor)->toBe($frågorMedTreBilagor);

    Carbon::setTestNow();
});

it('svaret bär aldrig ett löpnummer', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    skapaBilagaPåItem($item, $account, $user);
    skapaBilagaPåItem($item, $account, $user);

    $response = getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    foreach ($response->json('data') as $rad) {
        expect($rad)->not->toHaveKeys([
            'id',
            'item_id',
            'stored_file_id',
            'uploaded_by_user_id',
            'billed_account_id',
            'content_hash',
            'storage_path',
            'reference_count',
        ]);
        expect($rad['ulid'])->toBeString();
    }
});
