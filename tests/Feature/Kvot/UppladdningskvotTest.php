<?php

use App\Actions\Attachment\StoreAttachment;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\postJson;

/*
 * Issue 27b · Uppladdningens två gränser — styckstorleken och totalkvoten —
 * i App\Support\Plan\Entitlements, App\Http\Controllers\Api\AttachmentController
 * och App\Actions\Attachment\StoreAttachment. Se [[Planer och kvoter]]
 * § Kontrollpunkter och [[Filer och lagring]] § Kvot kontra faktisk lagring.
 *
 * Två kontrollpunkter, två platser, en auktoritativ (issue 27b § Beslut 4):
 * styckstorleken kontrolleras i kontrollern före StoreAttachment; totalkvoten
 * kontrolleras i kontrollern som en billig avvisning OCH en gång till, under
 * radlåset på usage_counter-raden, inne i StoreAttachments transaktion.
 * Testerna här bevisar att gränserna sitter server-side och att totalkvoten
 * håller även när den tidiga kontrollen hunnit passera.
 *
 * kontoMedMedlem(), beviljaAccess() och bjudInRad() är Pests globala
 * hjälpare i tests/Feature/Container/. Gratisfallet kräver ingen fixture;
 * ett Pro-fall kräver en subscription-rad. Hjälpfunktionerna nedan är
 * lokala för den här filen — namnen krockar inte med andra testfiler.
 *
 * sättPlangräns() ändrar en rad i tabellen `plan` under testets gång.
 * RefreshDatabase rullar tillbaka raden när testet är klart, så en
 * gränssänkning i ett test läcker inte in i nästa.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Ett konto med ägarmedlem, container och item under den, plus headerpar.
 * Containern är skapad med fabriken och har alltså INTE räknats av
 * ContainerController::store — inget test här räknar containers, bara
 * storage_bytes och filstorlek.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: array<string, string>}
 */
function uppladdningSetup(): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $container, $item, $headers];
}

it('en fil under plangränsen laddas upp', function () {
    [$account, , $container, $item, $headers] = uppladdningSetup();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'en liten manual'),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect(Attachment::count())->toBe(1);
});

it('en fil över gratisplanens styckgräns nekas', function () {
    sättPlangräns('free', 'max_file_bytes', 1024);
    [$account, , $container, $item, $headers] = uppladdningSetup();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.max_file_size_exceeded');
    expect($response->json('error.data'))->toBe(['limit_bytes' => 1024, 'file_bytes' => 2048]);
});

it('en nekad fil skapar varken attachment eller stored_file', function () {
    sättPlangräns('free', 'max_file_bytes', 1024);
    [$account, , $container, $item, $headers] = uppladdningSetup();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(403);

    // Styckstorleken kontrolleras i kontrollern, före StoreAttachment
    // (Beslut 4): inga byten skrivs till disken och ingen rad skapas.
    expect(DB::table('stored_file')->count())->toBe(0);
    expect(DB::table('attachment')->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
});

it('ett prokonto får ladda upp en fil som gratiskontot nekas', function () {
    sättPlangräns('free', 'max_file_bytes', 1024);

    $pro = Plan::where('code', 'pro')->firstOrFail();
    [$account, $user, $headers] = kontoMedMedlem();
    Subscription::factory()->for($account)->for($pro)->create();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
});

it('en uppladdning som spränger totalkvoten nekas', function () {
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);
    [$account, , $container, $item, $headers] = uppladdningSetup();

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 9 * 1024 * 1024,
        'container_count' => 0,
    ]);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 4 * 1024 * 1024)),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.storage_exceeded');
    expect($response->json('error.data'))->toBe([
        'limit_bytes' => 10 * 1024 * 1024,
        'used_bytes' => 9 * 1024 * 1024,
        'file_bytes' => 4 * 1024 * 1024,
    ]);
});

it('en uppladdning som exakt fyller kvoten går igenom', function () {
    sättPlangräns('free', 'storage_bytes', 1024 * 1024);
    [$account, , $container, $item, $headers] = uppladdningSetup();

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 0,
        'container_count' => 0,
    ]);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 1024 * 1024)),
        'account' => $account->ulid,
    ], $headers);

    // used + file == limit nekar inte — det är used + file > limit som nekar
    // (issue 27b § Att se upp med).
    $response->assertCreated();
    expect(DB::table('usage_counter')->where('account_id', $account->id)->value('storage_bytes'))
        ->toBe(1024 * 1024);
});

it('kontrollen görs mot det uppladdande kontot, inte containerns ägare', function () {
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);

    // Kundens gratiskonto är nästan fullt — ägarkontot vars plan INTE ska
    // prövas. Containern och itemet ligger under henne.
    $kund = Account::factory()->create();
    $container = Container::factory()->for($kund, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    UsageCounter::factory()->create([
        'account_id' => $kund->id,
        'storage_bytes' => 9 * 1024 * 1024,
        'container_count' => 0,
    ]);

    // Varvet är också gratis men har ingen förbrukning. En kontroll mot
    // ägarkontot skulle neka (9 + 4 > 10 MiB); mot det uppladdande kontot
    // går 0 + 4 ≤ 10 MiB igenom (Beslut 3).
    $varv = Account::factory()->create();
    $varvsmedlem = User::factory()->create();
    $varv->users()->attach($varvsmedlem, ['role' => 'member']);
    $token = $varvsmedlem->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];
    beviljaAccess($container, $varv, 'write', 'managed');

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('service-bild.pdf', str_repeat('a', 4 * 1024 * 1024)),
        'account' => $varv->ulid,
    ], $headers);

    $response->assertCreated();
    expect(DB::table('usage_counter')->where('account_id', $varv->id)->value('storage_bytes'))
        ->toBe(4 * 1024 * 1024);
    expect(DB::table('usage_counter')->where('account_id', $kund->id)->value('storage_bytes'))
        ->toBe(9 * 1024 * 1024);
});

it('dedup ger ingen rabatt på kvoten', function () {
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);
    [$account, , $container, $item, $headers] = uppladdningSetup();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";
    $innehåll = str_repeat('a', 6 * 1024 * 1024);

    postJson($url, [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', $innehåll),
        'account' => $account->ulid,
    ], $headers)->assertCreated();

    // Andra uppladdningen av samma innehåll räknas FULLT mot kvoten (logisk
    // storlek, Beslut 6): 6 använda + 6 nya > 10 MiB-taket, trots att dedupen
    // inte skulle skriva en enda ny byte till disken.
    $andra = postJson($url, [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', $innehåll),
        'account' => $account->ulid,
    ], $headers);

    $andra->assertStatus(403);
    expect($andra->json('error.code'))->toBe('quota.storage_exceeded');
    expect($andra->json('error.data'))->toBe([
        'limit_bytes' => 10 * 1024 * 1024,
        'used_bytes' => 6 * 1024 * 1024,
        'file_bytes' => 6 * 1024 * 1024,
    ]);
    expect(Attachment::count())->toBe(1);
    expect(DB::table('usage_counter')->where('account_id', $account->id)->value('storage_bytes'))
        ->toBe(6 * 1024 * 1024);
});

it('den auktoritativa kontrollen sitter innanför transaktionen', function () {
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);
    [$account, $user, $container, $item] = uppladdningSetup();

    UsageCounter::factory()->create([
        'account_id' => $account->id,
        'storage_bytes' => 2 * 1024 * 1024,
        'container_count' => 0,
    ]);

    $fil = UploadedFile::fake()->createWithContent('manual.pdf', str_repeat('a', 4 * 1024 * 1024));

    // Kontrollerns tidiga kontroll skulle passera: 2 + 4 ≤ 10 MiB.
    (new Entitlements)->assertStorageWithinLimit($account, 4 * 1024 * 1024);

    // Mellan den tidiga kontrollen och transaktionen hinner en samtidig
    // uppladdning öka räknaren till 7 MiB — precis det race som bara den
    // auktoritativa kontrollen, under radlåset inne i StoreAttachments
    // transaktion, fångar (Beslut 4). SQLite kan inte öva riktig samtidighet,
    // så racet simuleras: räknaren höjs för hand mellan de två kontrollerna.
    (new AdjustUsage)->handle($account->id, bytesDelta: 5 * 1024 * 1024);

    $exception = null;
    try {
        (new StoreAttachment)->handle($item, $fil, $user, $account);
    } catch (ApiException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    $response = $exception->toResponse(Request::create('/api'));
    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true))->toBe([
        'error' => [
            'code' => 'quota.storage_exceeded',
            'data' => [
                'limit_bytes' => 10 * 1024 * 1024,
                'used_bytes' => 7 * 1024 * 1024,
                'file_bytes' => 4 * 1024 * 1024,
            ],
        ],
    ]);

    // Kastet rullade tillbaka hela transaktionen: varken stored_file- eller
    // attachment-raden finns, och räknaren står kvar på 7 MiB.
    expect(DB::table('stored_file')->count())->toBe(0);
    expect(DB::table('attachment')->count())->toBe(0);
    expect(DB::table('usage_counter')->where('account_id', $account->id)->value('storage_bytes'))
        ->toBe(7 * 1024 * 1024);
});

it('det tekniska taket ger fortfarande 422', function () {
    config(['files.max_upload_bytes' => 2 * 1024]); // 2 KiB

    [$account, , $container, $item, $headers] = uppladdningSetup();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->create('stor.pdf', 3), // 3 KiB
        'account' => $account->ulid,
    ], $headers);

    // Det TEKNISKA taket är ett valideringsfel (422), inte en plangräns
    // (403) — Beslut 2. En plangräns skulle säga "uppgradera", det tekniska
    // taket säger "filen är för stor för systemet".
    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.file.0.code'))->toBe('validation.max');
});

it('en obehörig användare får auth_forbidden, inte en kvotkod', function () {
    sättPlangräns('free', 'max_file_bytes', 1024);

    // Ägarkontot ligger över sin (sänkta) styckgräns redan vid en liten fil —
    // en kvotkontroll som körde före medlemskapskontrollen skulle avslöja
    // gränserna för inkräktaren (issue 27 § Beslut 3).
    [$ägarkonto] = kontoMedMedlem();
    $container = Container::factory()->for($ägarkonto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    UsageCounter::factory()->create([
        'account_id' => $ägarkonto->id,
        'storage_bytes' => 0,
        'container_count' => 0,
    ]);

    // Inkräktaren har write-access till containern men är inte medlem i
    // ägarkontot — Gate::authorize passerar, medlemskapskontrollen nekar.
    [, $inkräktare, $inkräktareHeaders] = kontoMedMedlem();
    beviljaAccess($container, $inkräktare, 'write', 'member');

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
        'account' => $ägarkonto->ulid,
    ], $inkräktareHeaders);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect($response->json('error.data'))->toBe([]);
    expect(Attachment::count())->toBe(0);
});

it('felsvaren följer höljet', function () {
    sättPlangräns('free', 'max_file_bytes', 1024);
    [$account, , $container, $item, $headers] = uppladdningSetup();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('stor.pdf', str_repeat('a', 2048)),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('quota.max_file_size_exceeded');
    expect($response->json('error.data'))->toBe(['limit_bytes' => 1024, 'file_bytes' => 2048]);

    // Ingen message-nyckel, vare sig på toppnivå eller i höljet (AGENTS.md §
    // Felformat i API:et), och data är ett JSON-objekt, aldrig en array.
    expect($response->json('message'))->toBeNull();
    expect($response->json('error.message'))->toBeNull();
    expect(json_decode($response->getContent())->error->data)->toBeInstanceOf(stdClass::class);
});
