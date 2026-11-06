<?php

use App\Console\PurgesExpiredStoredFiles;
use App\Jobs\GenerateImageDerivatives;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\postJson;

/*
 * Issue 18 · Miniatyrer. Se App\Jobs\GenerateImageDerivatives,
 * [[Filer och lagring]] § image_derivative och issue 18 § Beslut 1–8.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Testerna får inte kräva en riktig kö (issue 18 § Att se upp med):
 * `Queue::fake()` bevisar att jobbet köas, och bildarbetet prövas sedan
 * direkt genom `(new GenerateImageDerivatives($storedFile))->handle()`.
 * Undantaget är "en uppladdning lyckas även om derivatgenereringen
 * misslyckas": där körs jobbet för hand — fast med en trasig bild, och det
 * misslyckandet får inte fälla uppladdningen.
 *
 * Storage::fake('files') i beforeEach — inga bytes får hamna i den riktiga
 * storage/files/ när sviten körs, samma mönster som UppladdningTest (16a).
 * Storage::disk('files')->path() ger GD en lokal sökväg också på en fejkad
 * disk.
 */

beforeEach(function () {
    Storage::fake('files');
});

afterEach(function () {
    Carbon::setTestNow();
    GenerateImageDerivatives::$imagickLoaded = null;
});

/**
 * Konto, container och item redo för en uppladdning — samma uppställning
 * som UppladdningTest.
 */
function miniatyrFörbered(): array
{
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    return [$account, $headers, $container, $item];
}

/**
 * POST:ar en bilaga och returnerar attachment-raden.
 */
function miniatyrLaddaUpp(Container $container, Item $item, string $kontoUlid, array $headers, UploadedFile $fil): Attachment
{
    $response = postJson("/api/containers/{$container->ulid}/items/{$item->ulid}/attachments", [
        'file' => $fil,
        'account' => $kontoUlid,
    ], $headers);

    $response->assertCreated();

    return Attachment::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
}

/**
 * Laddar upp en bild och kör miniatyrjobbet för hand — Queue::fake() ser till
 * att uppladdningen inte kör jobbet själv (QUEUE_CONNECTION=sync i phpunit).
 */
function miniatyrGenereraFör(Container $container, Item $item, string $kontoUlid, array $headers, UploadedFile $fil): StoredFile
{
    Queue::fake();

    $attachment = miniatyrLaddaUpp($container, $item, $kontoUlid, $headers, $fil);

    (new GenerateImageDerivatives($attachment->storedFile))->handle();

    return $attachment->storedFile;
}

/**
 * Bygger en PNG med helt genomskinlig bakgrund och en röd ruta — GD:s
 * alfakanal: 0 = opak, 127 = helt genomskinlig.
 */
function miniatyrTransparentPng(int $bredd, int $höjd): string
{
    $bild = imagecreatetruecolor($bredd, $höjd);
    imagesavealpha($bild, true);

    $transparent = imagecolorallocatealpha($bild, 0, 0, 0, 127);
    imagefill($bild, 0, 0, $transparent);

    $röd = imagecolorallocate($bild, 255, 0, 0);
    imagefilledrectangle(
        $bild,
        (int) round($bredd * 0.1),
        (int) round($höjd * 0.1),
        (int) round($bredd * 0.3),
        (int) round($höjd * 0.3),
        $röd,
    );

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

/**
 * Läser alfakanalen (0–127) i en bildfil på en punkt.
 */
function miniatyrAlfaVid(string $absolutSökväg, int $x, int $y): int
{
    $bild = imagecreatefrompng($absolutSökväg);
    $rgba = imagecolorsforindex($bild, imagecolorat($bild, $x, $y));
    imagedestroy($bild);

    return $rgba['alpha'];
}

/**
 * Bygger en bild i ett givet format med GD — samma väg som jobbet läser
 * den. Behövs för gif, bmp och avif, som UploadedFile::fake()->image()
 * inte kan skapa.
 */
function miniatyrBild(string $format, int $bredd, int $höjd): string
{
    $bild = imagecreatetruecolor($bredd, $höjd);
    $färg = imagecolorallocate($bild, 255, 0, 0);
    imagefilledrectangle($bild, 0, 0, $bredd - 1, $höjd - 1, $färg);

    ob_start();
    match ($format) {
        'gif' => imagegif($bild),
        'bmp' => imagebmp($bild),
        'avif' => imageavif($bild, null, 60),
        default => throw new InvalidArgumentException("Okänt format ({$format})."),
    };
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

it('en uppladdad gif ger två derivat', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('a.gif', miniatyrBild('gif', 2000, 1000)));

    // Båda varianterna med rad och fil, i originalets format (Beslut 4).
    $derivat = ImageDerivative::where('stored_file_id', $fil->id)->get();

    expect($derivat)->toHaveCount(2);
    expect($derivat->pluck('variant')->sort()->values()->all())->toBe(['medium', 'thumb']);

    foreach ($derivat as $rad) {
        expect($rad->storage_path)->toEndWith('.gif');
        expect(Storage::disk('files')->exists($rad->storage_path))->toBeTrue();
    }
});

it('en uppladdad bmp ger två derivat', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('a.bmp', miniatyrBild('bmp', 2000, 1000)));

    $derivat = ImageDerivative::where('stored_file_id', $fil->id)->get();

    expect($derivat)->toHaveCount(2);
    expect($derivat->pluck('variant')->sort()->values()->all())->toBe(['medium', 'thumb']);

    foreach ($derivat as $rad) {
        expect($rad->storage_path)->toEndWith('.bmp');
        expect(Storage::disk('files')->exists($rad->storage_path))->toBeTrue();
    }
});

it('en uppladdad avif ger två derivat', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('a.avif', miniatyrBild('avif', 2000, 1000)));

    $derivat = ImageDerivative::where('stored_file_id', $fil->id)->get();

    expect($derivat)->toHaveCount(2);
    expect($derivat->pluck('variant')->sort()->values()->all())->toBe(['medium', 'thumb']);

    foreach ($derivat as $rad) {
        expect($rad->storage_path)->toEndWith('.avif');
        expect(Storage::disk('files')->exists($rad->storage_path))->toBeTrue();
    }
})->skip(! (gd_info()['AVIF Support'] ?? false), 'GD saknar AVIF-stöd.');

it('en uppladdad jpeg ger två derivat', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->image('a.jpg', 2000, 1000));

    // Båda varianterna med rad och fil (Beslut 2). En 2000×1000-bild är
    // större än båda målen, så båda varianterna ska finnas.
    $derivat = ImageDerivative::where('stored_file_id', $fil->id)->get();

    expect($derivat)->toHaveCount(2);
    expect($derivat->pluck('variant')->sort()->values()->all())->toBe(['medium', 'thumb']);

    foreach ($derivat as $rad) {
        expect(Storage::disk('files')->exists($rad->storage_path))->toBeTrue();
    }
});

it('längsta sidan blir 320 respektive 1024', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->image('a.jpg', 2000, 1000));

    // Bildförhållandet 2:1 bevarat (Beslut 2): thumb blir 320×160, medium
    // 1024×512.
    $thumb = ImageDerivative::where(['stored_file_id' => $fil->id, 'variant' => 'thumb'])->firstOrFail();
    $medium = ImageDerivative::where(['stored_file_id' => $fil->id, 'variant' => 'medium'])->firstOrFail();

    [$thumbBredd, $thumbHöjd] = getimagesize(Storage::disk('files')->path($thumb->storage_path));
    [$mediumBredd, $mediumHöjd] = getimagesize(Storage::disk('files')->path($medium->storage_path));

    expect([$thumbBredd, $thumbHöjd])->toBe([320, 160]);
    expect([$mediumBredd, $mediumHöjd])->toBe([1024, 512]);
});

it('en bild mindre än målet förstoras inte', function (int $bredd, int $höjd, array $förväntadeVarianter) {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->image('a.jpg', $bredd, $höjd));

    // Avsaknaden av en rad betyder "originalet duger" (Beslut 2).
    $varianter = ImageDerivative::where('stored_file_id', $fil->id)->pluck('variant')->sort()->values()->all();

    expect($varianter)->toBe($förväntadeVarianter);
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeTrue();
})->with([
    'mindre än båda målen' => [200, 150, []],
    'mellan thumb och medium' => [500, 400, ['thumb']],
]);

it('en png behåller genomskinligheten', function () {
    Queue::fake();

    [$account, $headers, $container, $item] = miniatyrFörbered();

    $attachment = miniatyrLaddaUpp(
        $container,
        $item,
        $account->ulid,
        $headers,
        UploadedFile::fake()->createWithContent('a.png', miniatyrTransparentPng(800, 600)),
    );
    $fil = $attachment->storedFile;

    (new GenerateImageDerivatives($fil))->handle();

    $thumb = ImageDerivative::where(['stored_file_id' => $fil->id, 'variant' => 'thumb'])->firstOrFail();

    expect($thumb->storage_path)->toEndWith('.png');
    expect(Storage::disk('files')->exists($thumb->storage_path))->toBeTrue();

    // En punkt långt från den röda rutan — genomskinlig i originalet (127)
    // och måste vara genomskinlig också i miniatyren, inte svart.
    expect(miniatyrAlfaVid(Storage::disk('files')->path($thumb->storage_path), 6, 5))->toBeGreaterThan(100);
});

it('en pdf får inga derivat', function () {
    Queue::fake();

    [$account, $headers, $container, $item] = miniatyrFörbered();

    miniatyrLaddaUpp($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"));

    // Varken köat (Beslut 3 — mime-typen får derivat i Beslut 4) eller rad.
    Queue::assertPushed(GenerateImageDerivatives::class, 0);
    expect(ImageDerivative::count())->toBe(0);
});

it('en svg får inga derivat', function () {
    Queue::fake();

    [$account, $headers, $container, $item] = miniatyrFörbered();

    miniatyrLaddaUpp($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('bild.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));

    Queue::assertPushed(GenerateImageDerivatives::class, 0);
    expect(ImageDerivative::count())->toBe(0);
});

it('jobbet köas vid uppladdning av en bild', function () {
    Queue::fake();

    [$account, $headers, $container, $item] = miniatyrFörbered();

    $attachment = miniatyrLaddaUpp($container, $item, $account->ulid, $headers, UploadedFile::fake()->image('a.jpg', 800, 600));

    // En gång, med bytena som argument — derivaten hör till stored_file,
    // inte till kopplingen (Beslut 3).
    Queue::assertPushed(GenerateImageDerivatives::class, 1);
    Queue::assertPushed(GenerateImageDerivatives::class, fn (GenerateImageDerivatives $job) => $job->storedFile->is($attachment->storedFile));
});

it('jobbet köas inte vid en dedup-träff', function () {
    Queue::fake();

    [$account, $headers, $container, $item] = miniatyrFörbered();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/attachments";

    // Samma bild två gånger — samma bytes, dedupen slår till andra gången
    // och inget nytt derivatjobb köas (Beslut 3).
    postJson($url, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600), 'account' => $account->ulid], $headers)->assertCreated();
    postJson($url, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600), 'account' => $account->ulid], $headers)->assertCreated();

    expect(StoredFile::count())->toBe(1);
    expect(Attachment::count())->toBe(2);
    Queue::assertPushed(GenerateImageDerivatives::class, 1);
});

it('jobbet är idempotent', function () {
    Queue::fake();

    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrLaddaUpp($container, $item, $account->ulid, $headers, UploadedFile::fake()->image('a.jpg', 2000, 1000))->storedFile;

    (new GenerateImageDerivatives($fil))->handle();
    (new GenerateImageDerivatives($fil))->handle();

    // UNIQUE (stored_file_id, variant) gör omkörningen ofarlig (Beslut 1):
    // exakt en rad per variant.
    expect(ImageDerivative::where('stored_file_id', $fil->id)->count())->toBe(2);
    expect(ImageDerivative::where(['stored_file_id' => $fil->id, 'variant' => 'thumb'])->count())->toBe(1);
    expect(ImageDerivative::where(['stored_file_id' => $fil->id, 'variant' => 'medium'])->count())->toBe(1);
});

it('derivaten ligger bredvid originalet på disken', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $fil = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->image('a.jpg', 2000, 1000));

    // Originalets sökväg är ab/cd/<hash>, derivatet ab/cd/<hash>_thumb.jpg
    // (Beslut 4) — samma disk, samma prefixkataloger.
    foreach (['thumb' => 'jpg', 'medium' => 'jpg'] as $variant => $ändelse) {
        $derivat = ImageDerivative::where(['stored_file_id' => $fil->id, 'variant' => $variant])->firstOrFail();

        expect($derivat->storage_path)->toBe($fil->storage_path.'_'.$variant.'.'.$ändelse);
        expect(dirname($derivat->storage_path))->toBe(dirname($fil->storage_path));
        expect(Storage::disk('files')->exists($derivat->storage_path))->toBeTrue();
    }
});

it('gallringen tar derivaten med originalet', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');

    $fil = StoredFile::factory()->create([
        'reference_count' => 0,
        'purge_after' => '2026-09-01 12:00:00',
    ]);
    Storage::disk('files')->put($fil->storage_path, 'bytena');

    $thumb = ImageDerivative::factory()->create([
        'stored_file_id' => $fil->id,
        'variant' => 'thumb',
        'storage_path' => $fil->storage_path.'_thumb.jpg',
    ]);
    $medium = ImageDerivative::factory()->create([
        'stored_file_id' => $fil->id,
        'variant' => 'medium',
        'storage_path' => $fil->storage_path.'_medium.jpg',
    ]);
    Storage::disk('files')->put($thumb->storage_path, 't');
    Storage::disk('files')->put($medium->storage_path, 'm');

    (new PurgesExpiredStoredFiles)->handle();

    // Varken raderna eller filerna finns kvar (Beslut 7).
    expect(StoredFile::whereKey($fil->id)->exists())->toBeFalse();
    expect(ImageDerivative::where('stored_file_id', $fil->id)->count())->toBe(0);
    expect(Storage::disk('files')->exists($fil->storage_path))->toBeFalse();
    expect(Storage::disk('files')->exists($thumb->storage_path))->toBeFalse();
    expect(Storage::disk('files')->exists($medium->storage_path))->toBeFalse();
});

it('gallringen faller inte på främmande nyckel', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    $logg = Log::spy();

    $fil = StoredFile::factory()->create([
        'reference_count' => 0,
        'purge_after' => '2026-09-01 12:00:00',
    ]);
    ImageDerivative::factory()->create([
        'stored_file_id' => $fil->id,
        'variant' => 'thumb',
        'storage_path' => $fil->storage_path.'_thumb.jpg',
    ]);

    // FK:n är RESTRICT: derivatraderna måste bort före stored_file-raden,
    // annars faller gallringen på ett främmandenyckelfel varje natt (Beslut 7).
    $antal = (new PurgesExpiredStoredFiles)->handle();

    expect($antal)->toBe(1);
    expect(StoredFile::whereKey($fil->id)->exists())->toBeFalse();
    expect(ImageDerivative::count())->toBe(0);
    $logg->shouldNotHaveReceived('error');
});

it('en uppladdning lyckas även om derivatgenereringen misslyckas', function () {
    // Ingen Queue::fake — QUEUE_CONNECTION=sync kör jobbet direkt i
    // requesten. En .jpg vars byten GD inte kan avkoda får jobbet att
    // misslyckas, och det misslyckandet får inte fälla uppladdningen
    // (Beslut 5): bilagan finns och svaret är 201.
    $logg = Log::spy();

    [$account, $headers, $container, $item] = miniatyrFörbered();

    miniatyrLaddaUpp($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('trasig.jpg', 'det här är inte en bild'));

    expect(StoredFile::count())->toBe(1);
    expect(Attachment::count())->toBe(1);
    expect(ImageDerivative::count())->toBe(0);
    $logg->shouldHaveReceived('error')->once();
});

/*
 * Issue 348 · HEIC, HEIF och TIFF med Imagick ([[ADR-0071 Miniatyrer med
 * Imagick]]). Fixturerna byggs i proven med Imagick — inga binära filer i
 * repot. Proven som kräver Imagick hoppas över där tillägget saknas och körs
 * i CI, som laddar det; HEIC kräver dessutom att ImageMagick kan formatet.
 */

/**
 * Bygger en bild i givet format (tiff eller heic) med Imagick. En
 * orientering sätts bara när den anges.
 *
 * @param  int<0, 8>|null  $orientering
 */
function miniatyrImagickBild(string $format, int $bredd, int $höjd, ?int $orientering = null, int $sidor = 1): string
{
    $bild = new Imagick;

    for ($i = 0; $i < $sidor; $i++) {
        $sida = new Imagick;
        // Första sidan har den givna storleken, övriga är mindre så att
        // ett prov kan se vilken sida derivatet kom ifrån.
        $sida->newPseudoImage($i === 0 ? $bredd : 10, $i === 0 ? $höjd : 10, 'gradient:red-blue');
        if ($orientering !== null) {
            $sida->setImageOrientation($orientering);
        }
        $bild->addImage($sida);
    }

    $bild->setImageFormat($format);
    $byten = $bild->getImagesBlob();
    $bild->clear();

    return $byten;
}

/**
 * Bredd och höjd för ett derivat på disken.
 *
 * @return array{0: int, 1: int}
 */
function miniatyrMått(ImageDerivative $derivat): array
{
    $mått = getimagesize(Storage::disk('files')->path($derivat->storage_path));

    return [$mått[0], $mått[1]];
}

it('en tiff större än 1024 px får thumb och medium som jpeg', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $storedFile = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('skanning.tiff', miniatyrImagickBild('tiff', 2000, 1500)));

    $thumb = $storedFile->derivatives()->where('variant', 'thumb')->firstOrFail();
    $medium = $storedFile->derivatives()->where('variant', 'medium')->firstOrFail();

    expect($thumb->storage_path)->toEndWith('_thumb.jpg');
    expect($medium->storage_path)->toEndWith('_medium.jpg');
    expect(miniatyrMått($thumb))->toBe([320, 240]);
    expect(miniatyrMått($medium))->toBe([1024, 768]);
    expect(getimagesize(Storage::disk('files')->path($thumb->storage_path))[2])->toBe(IMAGETYPE_JPEG);
})->skip(fn () => ! extension_loaded('imagick'), 'Imagick saknas lokalt — provet körs i CI.');

it('en tiff mindre än 320 px får båda varianterna i originalets storlek', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $storedFile = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('liten.tiff', miniatyrImagickBild('tiff', 200, 100)));

    expect($storedFile->derivatives()->count())->toBe(2);

    foreach (['thumb', 'medium'] as $variant) {
        $derivat = $storedFile->derivatives()->where('variant', $variant)->firstOrFail();

        expect($derivat->storage_path)->toEndWith("_{$variant}.jpg");
        expect(miniatyrMått($derivat))->toBe([200, 100]);
    }
})->skip(fn () => ! extension_loaded('imagick'), 'Imagick saknas lokalt — provet körs i CI.');

it('en heic får båda varianterna som jpeg', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $storedFile = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('foto.heic', miniatyrImagickBild('heic', 1600, 1200)));

    expect($storedFile->mime_type)->toBe('image/heic');

    $thumb = $storedFile->derivatives()->where('variant', 'thumb')->firstOrFail();
    $medium = $storedFile->derivatives()->where('variant', 'medium')->firstOrFail();

    expect($thumb->storage_path)->toEndWith('_thumb.jpg');
    expect(max(...miniatyrMått($thumb)))->toBe(320);
    expect(max(...miniatyrMått($medium)))->toBe(1024);
    expect(getimagesize(Storage::disk('files')->path($medium->storage_path))[2])->toBe(IMAGETYPE_JPEG);
})->skip(fn () => ! extension_loaded('imagick') || Imagick::queryFormats('HEIC') === [], 'Imagick eller ImageMagicks HEIC-stöd saknas lokalt — provet körs i CI om formatet finns där.');

it('en tiff med EXIF-orientering 6 ger ett roterat derivat', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    // Lagrad som 600 x 300 liggande; orientering 6 (RIGHTTOP) betyder att
    // bilden ska visas stående, alltså 300 x 600.
    $storedFile = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('stående.tiff', miniatyrImagickBild('tiff', 600, 300, Imagick::ORIENTATION_RIGHTTOP)));

    $thumb = $storedFile->derivatives()->where('variant', 'thumb')->firstOrFail();

    expect(miniatyrMått($thumb))->toBe([160, 320]);
})->skip(fn () => ! extension_loaded('imagick'), 'Imagick saknas lokalt — provet körs i CI.');

it('en tiff med flera sidor ger derivat av första sidan', function () {
    [$account, $headers, $container, $item] = miniatyrFörbered();

    $storedFile = miniatyrGenereraFör($container, $item, $account->ulid, $headers, UploadedFile::fake()->createWithContent('flera.tiff', miniatyrImagickBild('tiff', 400, 200, null, 3)));

    $thumb = $storedFile->derivatives()->where('variant', 'thumb')->firstOrFail();

    expect(miniatyrMått($thumb))->toBe([320, 160]);
})->skip(fn () => ! extension_loaded('imagick'), 'Imagick saknas lokalt — provet körs i CI.');

it('en pdf förklädd till tiff ger inga derivat och loggas', function () {
    $logg = Log::spy();

    // Uppladdningen sniffar typen och skulle kalla filen pdf, så raden skapas
    // direkt: mime_type säger image/tiff, bytena är en PDF.
    $storedFile = StoredFile::factory()->create(['mime_type' => 'image/tiff']);
    Storage::disk('files')->put($storedFile->storage_path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");

    (new GenerateImageDerivatives($storedFile))->handle();

    expect(ImageDerivative::count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([$storedFile->storage_path]);
    $logg->shouldHaveReceived('error')->once();
})->skip(fn () => ! extension_loaded('imagick'), 'Imagick saknas lokalt — provet körs i CI.');

it('supportsMime svarar ja för heic, heif och tiff bara när imagick är laddat', function () {
    GenerateImageDerivatives::$imagickLoaded = true;

    foreach (['image/heic', 'image/heif', 'image/tiff'] as $mime) {
        expect(GenerateImageDerivatives::supportsMime($mime))->toBeTrue();
    }

    GenerateImageDerivatives::$imagickLoaded = false;

    foreach (['image/heic', 'image/heif', 'image/tiff'] as $mime) {
        expect(GenerateImageDerivatives::supportsMime($mime))->toBeFalse();
    }

    // De sex GD-typerna påverkas inte.
    expect(GenerateImageDerivatives::supportsMime('image/jpeg'))->toBeTrue();
});

it('supportsMime svarar alltid nej för pdf', function () {
    foreach ([true, false, null] as $laddat) {
        GenerateImageDerivatives::$imagickLoaded = $laddat;

        expect(GenerateImageDerivatives::supportsMime('application/pdf'))->toBeFalse();
    }
});
