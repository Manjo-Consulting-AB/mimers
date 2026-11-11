<?php

use App\Jobs\GenerateImageDerivatives;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\postJson;

/*
 * Miniatyrjobbets GD-väg prövar bildens mått före avkodning. Se
 * App\Jobs\GenerateImageDerivatives och [[ADR-0071 Miniatyrer med Imagick]].
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Laddar upp en bild och skriver, om `$bytes` anges, över originalet, så att
 * jobbet läser en fil med de påstådda måtten utan att något allokeras.
 */
function gränsStoredFile(UploadedFile $fil, ?string $bytes = null): StoredFile
{
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    Queue::fake();

    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => $fil,
        'account' => $account->ulid,
    ], $headers);
    $response->assertCreated();

    $stored = Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail()->storedFile;

    if ($bytes !== null) {
        Storage::disk('files')->put($stored->storage_path, $bytes);
    }

    return $stored;
}

/** Giltig PNG-signatur och IHDR med påstådda mått — inga pixeldata. */
function gränsPngHuvud(int $bredd, int $höjd): string
{
    $ihdr = pack('NN', $bredd, $höjd)."\x08\x06\x00\x00\x00";

    return "\x89PNG\r\n\x1a\n"
        .pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
}

function gränsKörOchFörväntaVarning(StoredFile $stored, string $meddelande): void
{
    $logg = Log::spy();

    (new GenerateImageDerivatives($stored))->handle();

    $logg->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === $meddelande)->once();
    expect(ImageDerivative::where('stored_file_id', $stored->id)->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toHaveCount(1);
}

it('en png med huvud 20000 x 100 ger inga derivat och en varning', function () {
    $stored = gränsStoredFile(UploadedFile::fake()->image('a.png', 10, 10), gränsPngHuvud(20000, 100));

    gränsKörOchFörväntaVarning($stored, 'Bilden är för stor för miniatyrer');
});

it('en bild över areagränsen men under sidgränsen avvisas', function () {
    // 10000 × 10000 = 100 Mpx > 64 Mpx, båda sidor < 16000.
    $stored = gränsStoredFile(UploadedFile::fake()->image('a.png', 10, 10), gränsPngHuvud(10000, 10000));

    gränsKörOchFörväntaVarning($stored, 'Bilden är för stor för miniatyrer');
});

it('minnesprövningen säger nej för 4000 x 3000 mot 32 MB och ja mot 128 MB', function () {
    expect(GenerateImageDerivatives::fitsInMemory(4000, 3000, 32 * 1024 * 1024))->toBeFalse();
    expect(GenerateImageDerivatives::fitsInMemory(4000, 3000, 128 * 1024 * 1024))->toBeTrue();
});

it('minnesprövningen säger ja för alla mått när gränsen är null', function () {
    expect(GenerateImageDerivatives::fitsInMemory(16000, 4000, null))->toBeTrue();
    expect(GenerateImageDerivatives::fitsInMemory(1, 1, null))->toBeTrue();
});

it('en fil som inte går att läsa som bild ger en varning och inga derivat', function () {
    $stored = gränsStoredFile(UploadedFile::fake()->image('a.png', 10, 10), 'detta är ingen bild');

    gränsKörOchFörväntaVarning($stored, 'Bildens mått kunde inte läsas — genererar inga miniatyrer');
});

it('en vanlig jpeg på 2000 x 1500 får fortfarande thumb och medium', function () {
    $stored = gränsStoredFile(UploadedFile::fake()->image('a.jpg', 2000, 1500));

    (new GenerateImageDerivatives($stored))->handle();

    expect(ImageDerivative::where('stored_file_id', $stored->id)->pluck('variant')->sort()->values()->all())
        ->toBe(['medium', 'thumb']);
});
