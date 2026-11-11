<?php

use App\Jobs\GenerateImageDerivatives;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\SkippedWithMessageException;

/*
 * Issue 1037 · Miniatyrer av JPEG via GD följer EXIF-orienteringen.
 * Se App\Jobs\GenerateImageDerivatives och ADR-0071.
 *
 * Fixturerna är GD-ritade JPEG-filer med en handbyggd EXIF-APP1-post som
 * bär orienteringen. Det kräver varken Imagick eller exif för att skapa
 * (till skillnad från `setImageOrientation()`), bara för att läsa.
 */

beforeEach(function () {
    Storage::fake('files');

    if (! function_exists('exif_read_data') && ! extension_loaded('imagick')) {
        throw new SkippedWithMessageException('Varken exif eller imagick finns — orienteringen kan inte läsas.');
    }
});

afterEach(function () {
    GenerateImageDerivatives::$imagickLoaded = null;
});

/**
 * Färger för kvadranterna A (uppe vänster), B (uppe höger), C (nere vänster)
 * och D (nere höger) i den lagrade bilden.
 */
function orienteringFärger(): array
{
    return ['A' => [255, 0, 0], 'B' => [0, 255, 0], 'C' => [0, 0, 255], 'D' => [255, 255, 0]];
}

/**
 * En JPEG med fyra färgade kvadranter och, om $orientering ges, en
 * EXIF-orienteringstagg inskjuten direkt efter SOI.
 */
function orienteringJpeg(int $bredd, int $höjd, ?int $orientering): string
{
    $bild = imagecreatetruecolor($bredd, $höjd);
    $färger = orienteringFärger();
    $färg = fn (string $nyckel) => imagecolorallocate($bild, ...$färger[$nyckel]);

    imagefilledrectangle($bild, 0, 0, intdiv($bredd, 2) - 1, intdiv($höjd, 2) - 1, $färg('A'));
    imagefilledrectangle($bild, intdiv($bredd, 2), 0, $bredd - 1, intdiv($höjd, 2) - 1, $färg('B'));
    imagefilledrectangle($bild, 0, intdiv($höjd, 2), intdiv($bredd, 2) - 1, $höjd - 1, $färg('C'));
    imagefilledrectangle($bild, intdiv($bredd, 2), intdiv($höjd, 2), $bredd - 1, $höjd - 1, $färg('D'));

    ob_start();
    imagejpeg($bild, null, 95);
    $jpeg = (string) ob_get_clean();
    imagedestroy($bild);

    if ($orientering === null) {
        return $jpeg;
    }

    // TIFF-huvud (big endian) + en IFD med en post: tagg 0x0112, typ SHORT, antal 1.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"
        ."\x00\x01"
        ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientering)."\x00\x00"
        ."\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function orienteringKör(string $bytes, string $mime = 'image/jpeg'): StoredFile
{
    $fil = StoredFile::factory()->create(['mime_type' => $mime]);
    Storage::disk('files')->put($fil->storage_path, $bytes);

    (new GenerateImageDerivatives($fil))->handle();

    return $fil;
}

/** @return array{0: int, 1: int} */
function orienteringMått(StoredFile $fil, string $variant): array
{
    $derivat = $fil->derivatives()->where('variant', $variant)->firstOrFail();
    $mått = getimagesize(Storage::disk('files')->path($derivat->storage_path));

    return [$mått[0], $mått[1]];
}

/**
 * Färgen mitt i en kvadrant av medium (kolumn 0/1 = vänster/höger,
 * rad 0/1 = uppe/nere).
 *
 * @return array{0: int, 1: int, 2: int}
 */
function orienteringKvadrant(StoredFile $fil, int $kolumn, int $rad): array
{
    $derivat = $fil->derivatives()->where('variant', 'medium')->firstOrFail();
    $bild = imagecreatefromjpeg(Storage::disk('files')->path($derivat->storage_path));
    $x = (int) (imagesx($bild) * (0.25 + 0.5 * $kolumn));
    $y = (int) (imagesy($bild) * (0.25 + 0.5 * $rad));
    $rgb = imagecolorat($bild, $x, $y);
    imagedestroy($bild);

    return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
}

function orienteringNära(array $faktisk, array $väntad): bool
{
    foreach ($faktisk as $i => $värde) {
        if (abs($värde - $väntad[$i]) > 40) {
            return false;
        }
    }

    return true;
}

it('en jpeg på 2000 x 1000 med orientering 6 eller 8 får en stående medium', function (int $orientering) {
    $fil = orienteringKör(orienteringJpeg(2000, 1000, $orientering));

    expect(orienteringMått($fil, 'medium'))->toBe([512, 1024]);
    expect(orienteringMått($fil, 'thumb'))->toBe([160, 320]);
})->with([6, 8]);

it('kvadranternas färger hamnar där EXIF-specifikationen säger för orientering 1–8', function (int $orientering, array $väntad) {
    $fil = orienteringKör(orienteringJpeg(2000, 1000, $orientering));
    $färger = orienteringFärger();

    // Väntad ordning: uppe vänster, uppe höger, nere vänster, nere höger.
    $faktisk = [
        orienteringKvadrant($fil, 0, 0),
        orienteringKvadrant($fil, 1, 0),
        orienteringKvadrant($fil, 0, 1),
        orienteringKvadrant($fil, 1, 1),
    ];

    foreach ($väntad as $i => $nyckel) {
        expect(orienteringNära($faktisk[$i], $färger[$nyckel]))
            ->toBeTrue("orientering {$orientering}, plats {$i}: väntade {$nyckel}");
    }
})->with([
    'orientering 1' => [1, ['A', 'B', 'C', 'D']],
    'orientering 2' => [2, ['B', 'A', 'D', 'C']],
    'orientering 3' => [3, ['D', 'C', 'B', 'A']],
    'orientering 4' => [4, ['C', 'D', 'A', 'B']],
    'orientering 5' => [5, ['A', 'C', 'B', 'D']],
    'orientering 6' => [6, ['C', 'A', 'D', 'B']],
    'orientering 7' => [7, ['D', 'B', 'C', 'A']],
    'orientering 8' => [8, ['B', 'D', 'A', 'C']],
]);

it('en jpeg utan EXIF och en med orientering 1 ger samma mått som förut', function (?int $orientering) {
    $fil = orienteringKör(orienteringJpeg(2000, 1000, $orientering));

    expect(orienteringMått($fil, 'medium'))->toBe([1024, 512]);
    expect(orienteringMått($fil, 'thumb'))->toBe([320, 160]);
})->with([null, 1]);

it('en roterad bild som inte behöver skalas får fortfarande inget derivat', function () {
    $fil = orienteringKör(orienteringJpeg(300, 200, 6));

    expect($fil->derivatives()->count())->toBe(0);
});

it('originalfilen ändras aldrig', function () {
    $bytes = orienteringJpeg(2000, 1000, 6);
    $fil = orienteringKör($bytes);

    expect(Storage::disk('files')->get($fil->storage_path))->toBe($bytes);
});

it('en png påverkas inte av vad som står i filen', function () {
    $bild = imagecreatetruecolor(2000, 1000);
    ob_start();
    imagepng($bild);
    $png = (string) ob_get_clean();
    imagedestroy($bild);

    $fil = orienteringKör($png, 'image/png');

    expect(orienteringMått($fil, 'medium'))->toBe([1024, 512]);
});

it('en fil vars orientering inte går att läsa ger derivat som om den vore 1', function (string $fall) {
    $jpeg = orienteringJpeg(2000, 1000, null);

    $bytes = match ($fall) {
        // Orientering 99 ligger utanför 1–8.
        'utanför 1–8' => orienteringJpeg(2000, 1000, 99),
        // EXIF-posten är avklippt mitt i IFD:n.
        'trasig EXIF' => substr($jpeg, 0, 2)."\xFF\xE1\x00\x10Exif\x00\x00MM\x00\x2A\x00\x00".substr($jpeg, 2),
        default => $jpeg,
    };

    $fil = orienteringKör($bytes);

    expect(orienteringMått($fil, 'medium'))->toBe([1024, 512]);
})->with(['utanför 1–8', 'trasig EXIF', 'ingen EXIF']);
