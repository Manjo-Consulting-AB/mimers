<?php

use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 1172 · Nedladdningsuppsåtet. Se AttachmentDownloadController,
 * FileDeliveryController och [[ADR-0019 Filleverans]] (Beslut 5 och
 * § Uppföljning 2026-08-31).
 *
 * `?download=1` på appdomänens rutt signeras in i URL:en till filoriginet och
 * kan bara skärpa leveransen till attachment. Miljön sätts i `beforeAll` av
 * samma skäl som i FiloriginTest: `files.deliver` registreras vid uppstarten.
 */

function uppsatsBas(): string
{
    return 'https://files.test';
}

beforeAll(function () {
    putenv('FILES_URL='.uppsatsBas());
    $_ENV['FILES_URL'] = uppsatsBas();
    $_SERVER['FILES_URL'] = uppsatsBas();
});

afterAll(function () {
    putenv('FILES_URL');
    unset($_ENV['FILES_URL'], $_SERVER['FILES_URL']);
});

beforeEach(function () {
    Storage::fake('files');
    config([
        'files.internal_redirect' => false,
        'files.url' => uppsatsBas(),
    ]);
});

/** @return array{0: User, 1: Attachment} */
function uppsatsBilaga(string $mime): array
{
    [$account, $user] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $storedFile = StoredFile::factory()->create(['mime_type' => $mime, 'byte_size' => 5]);
    Storage::disk('files')->put($storedFile->storage_path, 'bytes');

    $item = Item::factory()->for($container, 'container')->create();
    $attachment = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => 'fil',
        'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
    ]);

    return [$user, $attachment];
}

function uppsatsLänk(Attachment $attachment, bool $download): string
{
    $parametrar = ['attachment' => $attachment->ulid];

    if ($download) {
        $parametrar['download'] = 1;
    }

    return URL::temporarySignedRoute('files.deliver', now()->addMinutes(15), $parametrar);
}

it('präglar download=1 in i den signerade länken', function () {
    [$user, $attachment] = uppsatsBilaga('application/pdf');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}?download=1");

    $svar->assertRedirect();
    parse_str((string) parse_url($svar->headers->get('location'), PHP_URL_QUERY), $fråga);
    expect((string) ($fråga['download'] ?? null))->toBe('1');
    expect($fråga)->toHaveKeys(['expires', 'signature']);
});

it('lägger inte till download i länken utan uppsåt', function () {
    [$user, $attachment] = uppsatsBilaga('application/pdf');

    $svar = actingAs($user)->get("/files/{$attachment->ulid}");

    parse_str((string) parse_url($svar->headers->get('location'), PHP_URL_QUERY), $fråga);
    expect($fråga)->not->toHaveKey('download');
});

it('svarar attachment för en signerad download=1, också för typer på tillåt-listan', function (string $mime) {
    [, $attachment] = uppsatsBilaga($mime);

    $svar = get(uppsatsLänk($attachment, true));

    $svar->assertOk();
    expect($svar->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with(['application/pdf', 'image/png']);

it('svarar inline utan download, som förut', function (string $mime) {
    [, $attachment] = uppsatsBilaga($mime);

    $svar = get(uppsatsLänk($attachment, false));

    $svar->assertOk();
    expect($svar->headers->get('Content-Disposition'))->toStartWith('inline');
})->with(['application/pdf', 'image/png']);

it('ger 403 när download=1 lagts till efter signeringen', function () {
    [, $attachment] = uppsatsBilaga('application/pdf');

    get(uppsatsLänk($attachment, false).'&download=1')->assertForbidden();
});

it('ger 403 när download=1 tagits bort efter signeringen', function () {
    [, $attachment] = uppsatsBilaga('application/pdf');

    $länk = preg_replace(['/\\?download=1&/', '/&download=1/'], ['?', ''], uppsatsLänk($attachment, true), 1);

    get($länk)->assertForbidden();
});

it('levererar en typ utanför tillåt-listan som attachment med och utan download', function (bool $download) {
    [, $attachment] = uppsatsBilaga('text/html');

    $svar = get(uppsatsLänk($attachment, $download));

    $svar->assertOk();
    expect($svar->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with([true, false]);

it('levererar attachment utan filorigin, som förut', function () {
    config(['files.url' => null]);
    [$user, $attachment] = uppsatsBilaga('application/pdf');

    foreach (['', '?download=1'] as $fråga) {
        $svar = actingAs($user)->get("/files/{$attachment->ulid}{$fråga}");

        $svar->assertOk();
        expect($svar->headers->get('Content-Disposition'))->toStartWith('attachment');
    }
});
