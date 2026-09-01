<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;

use function Pest\Laravel\postJson;

/*
 * Issue 16a · Uppladdning av bilagor, bara POST-ytan. Se
 * App\Http\Controllers\Api\AttachmentController,
 * App\Actions\Attachment\StoreAttachment,
 * App\Http\Requests\Attachment\StoreAttachmentRequest,
 * App\Http\Resources\AttachmentResource, App\Models\Attachment och
 * App\Models\StoredFile.
 *
 * kontoMedMedlem() och beviljaAccess() återanvänds via Pests globala
 * namnrymd, samma mönster som ItemCrudTest. Varje "Klart när"-punkt i
 * issuen motsvarar ett namngivet test här.
 *
 * Storage::fake('files') i beforeEach — inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs (issue 16a § Att se upp med).
 */

beforeEach(function () {
    Storage::fake('files');
});

it('en fil laddas upp och ger 201', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $innehåll = 'PDF-innehåll som faktiskt ser ut som en manual';

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('victron-manual.pdf', $innehåll),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $attachment = Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    $storedFile = $attachment->storedFile;

    expect($attachment->item_id)->toBe($item->id);
    expect($attachment->filename)->toBe('victron-manual.pdf');
    expect($storedFile->content_hash)->toBe(hash('sha256', $innehåll));
    expect(Storage::disk('files')->exists($storedFile->storage_path))->toBeTrue();
    expect(Storage::disk('files')->get($storedFile->storage_path))->toBe($innehåll);
});

it('en klientskickad hash ignoreras', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = 'innehåll med egen hash';
    $klientsHash = str_repeat('a', 64);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', $innehåll),
        'account' => $account->ulid,
        'content_hash' => $klientsHash,
    ], $headers);

    $response->assertCreated();

    $stored = StoredFile::query()->firstOrFail();
    expect($stored->content_hash)->toBe(hash('sha256', $innehåll));
    expect($stored->content_hash)->not->toBe($klientsHash);
});

it('samma innehåll två gånger ger en stored_file och två attachments', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = 'samma victron-manual';
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    postJson($url, ['file' => UploadedFile::fake()->createWithContent('manual.pdf', $innehåll), 'account' => $account->ulid], $headers)->assertCreated();
    postJson($url, ['file' => UploadedFile::fake()->createWithContent('manual.pdf', $innehåll), 'account' => $account->ulid], $headers)->assertCreated();

    expect(StoredFile::count())->toBe(1);
    expect(StoredFile::firstOrFail()->reference_count)->toBe(2);
    expect(Attachment::count())->toBe(2);
    // Bytena skrevs bara en gång: den andra uppladdningen tog
    // incrementsvägen utan att anropa putFileAs.
    expect(count(Storage::disk('files')->allFiles()))->toBe(1);
});

it('samma innehåll under två olika filnamn delar stored_file', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = 'delad manual';
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    postJson($url, ['file' => UploadedFile::fake()->createWithContent('victron-manual.pdf', $innehåll), 'account' => $account->ulid], $headers)->assertCreated();
    postJson($url, ['file' => UploadedFile::fake()->createWithContent('garmin-handbok.pdf', $innehåll), 'account' => $account->ulid], $headers)->assertCreated();

    $attachments = Attachment::query()->orderBy('id')->get();
    expect($attachments)->toHaveCount(2);
    expect($attachments[0]->filename)->toBe('victron-manual.pdf');
    expect($attachments[1]->filename)->toBe('garmin-handbok.pdf');
    expect($attachments[0]->stored_file_id)->toBe($attachments[1]->stored_file_id);
    expect(StoredFile::count())->toBe(1);
});

it('olika innehåll ger två stored_file', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    postJson($url, ['file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll ett'), 'account' => $account->ulid], $headers)->assertCreated();
    postJson($url, ['file' => UploadedFile::fake()->createWithContent('b.pdf', 'innehåll två'), 'account' => $account->ulid], $headers)->assertCreated();

    expect(StoredFile::count())->toBe(2);
    expect(Attachment::count())->toBe(2);
});

it('mime-typen bestäms av innehållet', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    // En riktig PDF, men klienten påstår text/plain i Content-Type — servern
    // sniffar innehållet (Beslut 4) och lagrar application/pdf.
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
    $path = tempnam(sys_get_temp_dir(), 'laravel-upload-test');
    file_put_contents($path, $pdf);
    $file = new UploadedFile($path, 'manual.pdf', 'text/plain', null, true);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => $file,
        'account' => $account->ulid,
    ], $headers);

    unlink($path);

    $response->assertCreated();
    expect($response->json('data.mime_type'))->toBe('application/pdf');
    expect($response->json('data.kind'))->toBe('document');

    $stored = StoredFile::query()->firstOrFail();
    expect($stored->mime_type)->toBe('application/pdf');
});

it('kind härleds ur den sniffade typen', function (string $innehåll, string $filnamn, string $förväntadKind) {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent($filnamn, $innehåll),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.kind'))->toBe($förväntadKind);
})->with([
    'en bild' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), 'bild.png', 'image'],
    'ett dokument' => ["%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF", 'manual.pdf', 'document'],
    'en okänd binär' => ["\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f", 'okand.bin', 'other'],
]);

it('storage_path byggs ur hashen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = 'bygger sökväg ur hash';

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', $innehåll),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $hash = hash('sha256', $innehåll);
    $stored = StoredFile::query()->firstOrFail();
    expect($stored->storage_path)->toBe(substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash);
    expect($stored->storage_path)->not->toContain('.');
    expect(Storage::disk('files')->exists($stored->storage_path))->toBeTrue();
});

it('byte_size är filens faktiska storlek', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $innehåll = str_repeat('a', 4096);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', $innehåll),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.byte_size'))->toBe(4096);

    $stored = StoredFile::query()->firstOrFail();
    expect($stored->byte_size)->toBe(strlen($innehåll));
});

it('scan_status är skipped', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect(StoredFile::query()->firstOrFail()->scan_status)->toBe('skipped');
});

it('billed_account_id är det angivna kontot, inte containerns ägare', function () {
    $kundkonto = Account::factory()->create();
    $container = Container::factory()->for($kundkonto, 'account')->create();

    $varv = Account::factory()->create();
    $varvsmedlem = User::factory()->create();
    $varv->users()->attach($varvsmedlem, ['role' => 'member']);
    $token = $varvsmedlem->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    beviljaAccess($container, $varv, 'write', 'managed');

    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('service-bild.jpg', 'varvets servicebild'),
        'account' => $varv->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.billed_account'))->toBe($varv->ulid);

    $attachment = Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($attachment->billed_account_id)->toBe($varv->id);
    expect($attachment->billed_account_id)->not->toBe($kundkonto->id);
});

it('en användare som inte är medlem i det angivna kontot nekas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $annatKonto = Account::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $annatKonto->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
    expect(StoredFile::count())->toBe(0);
    expect(Attachment::count())->toBe(0);
});

it('uploaded_by_user_id kommer från token', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $annanAnvändare = User::factory()->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
        'uploaded_by_user_id' => $annanAnvändare->id,
    ], $headers);

    $response->assertCreated();

    $attachment = Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($attachment->uploaded_by_user_id)->toBe($user->id);
    expect($attachment->uploaded_by_user_id)->not->toBe($annanAnvändare->id);
});

it('en fil över taket avvisas', function () {
    config(['files.max_upload_bytes' => 2 * 1024]); // 2 KiB

    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->create('stor.pdf', 3), // 3 KiB
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.file.0.code'))->toBe('validation.max');
    expect($response->json('error.data.fields.file.0.data.max'))->toBe(2);

    expect(StoredFile::count())->toBe(0);
    expect(Attachment::count())->toBe(0);
});

it('en begäran utan fil avvisas', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.file.0.code'))->toBe('validation.required');
});

it('en read-deltagare nekas ladda upp', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $user->accounts->first()->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('en write-deltagare får ladda upp', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'write', 'member');
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $user->accounts->first()->ulid,
    ], $headers);

    $response->assertCreated();
});

it('en användare utan åtkomst nekas', function () {
    [, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $user->accounts->first()->ulid,
    ], $headers);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('auth.forbidden');
});

it('oautentiserad begäran ger 401', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
    ]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('auth.unauthenticated');
});

it('ett item i en annan container kan inte laddas upp till', function () {
    [$account, , $headers] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    $containerB = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($containerB, 'container')->create();

    $response = postJson("/api/containers/{$containerA->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('ett mjukraderat item kan inte laddas upp till', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();
    $item->delete();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('svaret bär aldrig content_hash, storage_path, reference_count eller ett löpnummer', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    $data = $response->json('data');
    expect($data)->not->toHaveKeys([
        'id',
        'content_hash',
        'storage_path',
        'reference_count',
        'item_id',
        'stored_file_id',
        'uploaded_by_user_id',
        'billed_account_id',
    ]);
    expect($data['ulid'])->toBeString();
    expect($data['mime_type'])->toBeString();
    expect($data['byte_size'])->toBeInt();
});

it('en misslyckad uppladdning lämnar ingen halv rad', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    // Simulera en misslyckad diskrivning: putFileAs kastar (throw => true på
    // disken) och transaktionen rullar tillbaka — ingen rad får lämnas kvar.
    $adapter = Mockery::mock(FilesystemAdapter::class);
    $adapter->shouldReceive('putFileAs')->andThrow(UnableToWriteFile::class, 'failed to write');
    Storage::shouldReceive('disk')->with('files')->andReturn($adapter);

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('a.pdf', 'innehåll'),
        'account' => $account->ulid,
    ], $headers);

    $response->assertStatus(500);
    expect(DB::table('stored_file')->count())->toBe(0);
    expect(DB::table('attachment')->count())->toBe(0);
});
