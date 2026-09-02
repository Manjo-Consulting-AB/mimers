<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 19a · Nedladdning av bilagor. Se
 * App\Http\Controllers\AttachmentDownloadController, routes/web.php,
 * config/files.php och [[ADR-0019 Filleverans]].
 *
 * kontoMedMedlem() och beviljaAccess() återanvänds via Pests globala
 * namnrymd, samma mönster som UppladdningTest (16a). Varje "Klart när"-punkt
 * i issuen motsvarar ett namngivet test här.
 *
 * Storage::fake('files') i beforeEach — inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs. config('files.internal_redirect') nollställs
 * i beforeEach: testerna för den sanna grenen sätter den i testet, och ett
 * läckage till nästa test skulle annars ge svitberoende (Beslut 6).
 */

beforeEach(function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false]);
});

/**
 * Container, item, bilaga och stored_file redo för en nedladdning — bytena
 * ligger på den fejkade disken.
 *
 * @return array{0: Item, 1: Attachment, 2: StoredFile}
 */
function nedladdningFörberedelse(
    Container $container,
    string $filnamn = 'manual.pdf',
    string $innehåll = 'originalets byten',
    string $mime = 'application/pdf',
): array {
    $storedFile = StoredFile::factory()->create([
        'mime_type' => $mime,
        'byte_size' => strlen($innehåll),
    ]);

    Storage::disk('files')->put($storedFile->storage_path, $innehåll);

    $item = Item::factory()->for($container, 'container')->create();
    $attachment = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => $filnamn,
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
    ]);

    return [$item, $attachment, $storedFile];
}

it('en behörig användare får filen', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'manualens byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->streamedContent())->toBe('manualens byten');
    $response->assertHeaderMissing('X-LiteSpeed-Location');
});

it('svaret bär X-LiteSpeed-Location när intern omdirigering är på', function () {
    config(['files.internal_redirect' => true]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment, $storedFile] = nedladdningFörberedelse($container, innehåll: 'byten som LiteSpeed ska leverera');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    $response->assertHeader('X-LiteSpeed-Location', '/_protected/'.$storedFile->storage_path);
    expect($response->getContent())->toBe('');
});

it('Content-Type sätts explicit i båda lägena', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'pdf-innehåll');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    // Aldrig text/html — LiteSpeed sätter inte typen efter innehållet vid
    // intern omdirigering, så PHP:s standard hade följt med hela vägen ut.
    $response->assertHeader('Content-Type', 'application/pdf');
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('Content-Disposition är alltid attachment — även för en bild', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'foto.png', innehåll: 'bildbyten', mime: 'image/png');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('ett filnamn med citattecken och å-ä-ö kodas korrekt', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'kvitto "sommar" återbetalning.pdf', innehåll: 'byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toContain('filename*=');
    expect($disposition)->toContain('kvitto%20%22sommar%22%20%C3%A5terbetalning.pdf');
});

it('X-Content-Type-Options är nosniff', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('en svg levereras som attachment och inte inline', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'bild.svg', innehåll: '<svg xmlns="http://www.w3.org/2000/svg"></svg>', mime: 'image/svg+xml');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toStartWith('attachment');
    expect($disposition)->not->toContain('inline');
});

it('variant=thumb levererar miniatyren', function (bool $internalRedirect) {
    config(['files.internal_redirect' => $internalRedirect]);

    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment, $storedFile] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    $derivative = ImageDerivative::factory()->create([
        'stored_file_id' => $storedFile->id,
        'variant' => 'thumb',
        'storage_path' => $storedFile->storage_path.'_thumb.jpg',
        'byte_size' => strlen('miniatyrens byten'),
    ]);
    Storage::disk('files')->put($derivative->storage_path, 'miniatyrens byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    $response->assertHeader('Content-Type', 'image/jpeg');

    if ($internalRedirect) {
        $response->assertOk();
        $response->assertHeader('X-LiteSpeed-Location', '/_protected/'.$derivative->storage_path);
        expect($response->getContent())->toBe('');
    } else {
        $response->assertOk();
        expect($response->streamedContent())->toBe('miniatyrens byten');
    }
})->with([
    'strömning' => [false],
    'intern omdirigering' => [true],
]);

it('en saknad variant ger 404 och aldrig originalet', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    // Ingen thumb-rad trots att originalets byten ligger på disken.
    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=thumb");

    $response->assertNotFound();
});

it('ett okänt variant-värde ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, filnamn: 'bild.jpg', innehåll: 'originalets byten', mime: 'image/jpeg');

    $response = actingAs($user)->get("/files/{$attachment->ulid}?variant=stor");

    $response->assertNotFound();
});

it('en användare utan åtkomst nekas', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'någon annans manual');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertForbidden();
});

it('en read-deltagare får ladda ner', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member');
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'läsarens byten');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertOk();
    expect($response->streamedContent())->toBe('läsarens byten');
});

it('en återkallad åtkomst slutar fungera omedelbart', function () {
    [, $user] = kontoMedMedlem();
    $container = Container::factory()->for(Account::factory()->create(), 'account')->create();
    beviljaAccess($container, $user, 'read', 'member', revokedAt: now());
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten bakom en återkallad access');

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertForbidden();
});

it('oautentiserad begäran nekas', function () {
    [$account] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten som aldrig får nå ut');

    $response = get("/files/{$attachment->ulid}");

    expect($response->getStatusCode())->toBeIn([302, 401]);

    if ($response->getStatusCode() === 302) {
        $response->assertRedirect();
    }
});

it('en mjukraderad bilaga ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten i papperskorgen');
    $attachment->delete();

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertNotFound();
});

it('en bilaga vars item är mjukraderat ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [$item, $attachment] = nedladdningFörberedelse($container, innehåll: 'byten på ett raderat item');
    $item->delete();

    $response = actingAs($user)->get("/files/{$attachment->ulid}");

    $response->assertNotFound();
});

it('en okänd ulid ger 404', function () {
    [$account, $user] = kontoMedMedlem();
    Container::factory()->for($account, 'account')->create();

    $response = actingAs($user)->get('/files/01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $response->assertNotFound();
});

it('en bearer-token fungerar på samma rutt som en session', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    [, $attachment] = nedladdningFörberedelse($container, innehåll: 'tokenets byten');

    $response = get("/files/{$attachment->ulid}", $headers);

    $response->assertOk();
    expect($response->streamedContent())->toBe('tokenets byten');
});

it('nedladdningen gör ett konstant antal frågor', function () {
    // Frys tiden runt mätningarna så eventuella tidsstämplar skriver
    // deterministiskt, samma mönster som BilagelistaTest.
    Carbon::setTestNow(now());

    [$account, $user] = kontoMedMedlem();
    $containerA = Container::factory()->for($account, 'account')->create();
    [, $attachmentA] = nedladdningFörberedelse($containerA, innehåll: 'byten A');
    $containerB = Container::factory()->for($account, 'account')->create();
    [, $attachmentB] = nedladdningFörberedelse($containerB, innehåll: 'byten B');

    // Värm guarden med ett omätt anrop.
    actingAs($user)->get("/files/{$attachmentA->ulid}")->assertOk();

    DB::enableQueryLog();
    actingAs($user)->get("/files/{$attachmentA->ulid}")->assertOk();
    $frågorFörsta = count(DB::getQueryLog());
    DB::flushQueryLog();

    actingAs($user)->get("/files/{$attachmentB->ulid}")->assertOk();
    $frågorAndra = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Eager loading — relationerna (storedFile, item, container) ska vara
    // inlästa i ett konstant antal frågor, inte en fråga per nedladdning
    // som växer med vad som än levereras.
    expect($frågorAndra)->toBe($frågorFörsta);
    expect($frågorFörsta)->toBeLessThanOrEqual(8);

    Carbon::setTestNow();
});
