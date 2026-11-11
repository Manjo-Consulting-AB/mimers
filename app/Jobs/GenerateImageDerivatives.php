<?php

namespace App\Jobs;

use App\Models\ImageDerivative;
use App\Models\StoredFile;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use RuntimeException;
use Throwable;

/**
 * Genererar miniatyrerna av en uppladdad bild — se [[Filer och lagring]] §
 * image_derivative och issue 18.
 *
 * Två varianter, längsta sidan avgör (Beslut 2): `thumb` = 320 px och
 * `medium` = 1024 px, bildförhållandet bevarat. En bild som redan är mindre
 * än målet förstoras aldrig — då skrivs varianten inte alls, och avsaknaden
 * av raden betyder "originalet duger".
 *
 * Sex MIME-typer får derivat, och formatet följer källan (Beslut 4):
 * jpeg och webp (kvalitet 82), avif (kvalitet 60), png, gif och bmp. En
 * animerad gif får en miniatyr av första bildrutan — det är GD:s beteende.
 * HEIC, HEIF och TIFF läses i stället med Imagick och skrivs som JPEG
 * (kvalitet 82) — se [[ADR-0071 Miniatyrer med Imagick]]. Båda varianterna
 * skrivs alltid för de tre typerna, utan förstoring, och orienteringen ur
 * EXIF tillämpas före skalningen. På GD-vägen tillämpas den också, men bara
 * för JPEG: GD skriver derivatet utan EXIF, så en liggande miniatyr av en
 * stående mobilbild kan inte rättas av webbläsaren. Originalfilen ändras
 * aldrig, och en bild som inte behöver skalas får fortfarande inget derivat.
 * Formatet sätts uttryckligen före sökvägen
 * (`HEIC:`, `TIFF:`) och resursgränser sätts före läsningen, så att en fil
 * som utger sig för att vara något annat aldrig når en delegat som
 * Ghostscript. Allt annat — svg, pdf, video, dokument — får inga derivat.
 * Sökvägen är originalets plus variantens namn: `ab/cd/<hash>_thumb.jpg`.
 * Samma disk `files`, samma prefixstruktur — derivaten ligger bredvid sitt
 * original, och gallringen i 17b hittar dem utan en extra fråga.
 *
 * Jobbet är repots första `app/Jobs/`-klass och följer Laravels standard:
 * `implements ShouldQueue`, `use Queueable` (som drar in Dispatchable,
 * InteractsWithQueue, Queueable och SerializesModels), konstruktorn tar
 * modellen, `handle()` gör arbetet. Kön är databasdriven (Beslut 8) — på
 * servern dras den med `queue:work`, aldrig `queue:listen` (AGENTS.md §
 * Subprocesser i driftmiljön).
 *
 * Ett derivat kan alltid genereras om, så ett fel får aldrig fälla uppladdningen
 * eller retrya för evigt (Beslut 5): `handle()` fångar `Throwable`, loggar och
 * avslutar. Saknas GD-tillägget gör jobbet ingenting — bilagan är redan
 * uppladdad och komplett; ett saknat derivat är en försämring, inte ett fel.
 * Att tillägget faktiskt finns hos inleed är verifierat av Tony, inte av den
 * här koden.
 */
class GenerateImageDerivatives implements ShouldQueue
{
    use Queueable;

    /**
     * Variant → längsta sidan i bildpunkter (Beslut 2).
     *
     * @var array<string, int>
     */
    private const VARIANTS = [
        'thumb' => 320,
        'medium' => 1024,
    ];

    /**
     * MIME-typ → filändelse (Beslut 4). Samma uppsättning avgör både om
     * StoreAttachment köar jobbet och vilka typer jobbet läser.
     *
     * @var array<string, string>
     */
    private const EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/avif' => 'avif',
    ];

    /**
     * MIME-typ → Imagicks uttryckliga formatprefix (ADR-0071 Beslut 4).
     * Derivaten av dessa typer skrivs alltid som JPEG.
     *
     * @var array<string, string>
     */
    private const IMAGICK_FORMAT = [
        'image/heic' => 'HEIC',
        'image/heif' => 'HEIF',
        'image/tiff' => 'TIFF',
    ];

    /** JPEG-kvalitet för derivat som skrivs med Imagick (ADR-0071 Beslut 2). */
    private const IMAGICK_QUALITY = 82;

    /** Resursgränser innan Imagick läser (ADR-0071 Beslut 5). */
    private const LIMIT_MEMORY_BYTES = 256 * 1024 * 1024;

    private const LIMIT_MAP_BYTES = 512 * 1024 * 1024;

    private const LIMIT_AREA_PIXELS = 64_000_000;

    private const LIMIT_SIDE_PIXELS = 16_000;

    /** Byte per pixel i en GD-truecolorbild, med marginal. */
    private const GD_BYTES_PER_PIXEL = 5;

    /**
     * Söm för proven: `null` läser `extension_loaded('imagick')`, `true` och
     * `false` tvingar svaret.
     */
    public static ?bool $imagickLoaded = null;

    public function __construct(public StoredFile $storedFile) {}

    /**
     * Är Imagick tillgängligt (ADR-0071 Beslut 6)?
     */
    private static function imagickAvailable(): bool
    {
        return self::$imagickLoaded ?? extension_loaded('imagick');
    }

    /**
     * Får den här MIME-typen över huvud taget derivat (Beslut 4)? StoreAttachment
     * använder svaret för att slippa köa jobb som inte kan göra något. HEIC,
     * HEIF och TIFF får derivat bara när Imagick är laddat; PDF aldrig.
     */
    public static function supportsMime(string $mime): bool
    {
        if (isset(self::IMAGICK_FORMAT[$mime])) {
            return self::imagickAvailable();
        }

        return isset(self::EXTENSION[$mime]);
    }

    /**
     * Genererar de varianter av originalets bild som är större än målet.
     */
    public function handle(): void
    {
        if (! self::supportsMime($this->storedFile->mime_type)) {
            return;
        }

        if (isset(self::IMAGICK_FORMAT[$this->storedFile->mime_type])) {
            $this->handleWithImagick();

            return;
        }

        if (! extension_loaded('gd')) {
            Log::warning('GD saknas — genererar inga miniatyrer', [
                'stored_file_id' => $this->storedFile->id,
                'storage_path' => $this->storedFile->storage_path,
            ]);

            return;
        }

        $path = Storage::disk('files')->path($this->storedFile->storage_path);

        // Måtten prövas före avkodning: ett minnesfel är fatalt och fångas
        // inte av catch (Throwable) nedan — det tar köarbetaren med sig.
        $size = @getimagesize($path);

        if ($size === false) {
            Log::warning('Bildens mått kunde inte läsas — genererar inga miniatyrer', [
                'stored_file_id' => $this->storedFile->id,
                'storage_path' => $this->storedFile->storage_path,
            ]);

            return;
        }

        [$width, $height] = $size;

        if (
            $width > self::LIMIT_SIDE_PIXELS
            || $height > self::LIMIT_SIDE_PIXELS
            || $width * $height > self::LIMIT_AREA_PIXELS
            || ! self::fitsInMemory($width, $height, self::remainingMemoryBytes())
        ) {
            Log::warning('Bilden är för stor för miniatyrer', [
                'stored_file_id' => $this->storedFile->id,
                'storage_path' => $this->storedFile->storage_path,
                'width' => $width,
                'height' => $height,
            ]);

            return;
        }

        try {
            // Storage::disk('files')->path() ger GD den lokala sökvägen.
            // All filhantering går genom Storage-abstraktionen
            // ([[ADR-0007 Fillagring hos inleed]]); `path()` är bara hur GD,
            // som inte kan läsa en abstraktion, nås fram till bytena.
            $original = $this->readImage($this->storedFile->mime_type, $path);

            if ($this->storedFile->mime_type === 'image/jpeg') {
                $orientation = $this->readOrientation($path);

                if ($orientation !== 1) {
                    $original = $this->applyOrientation($original, $orientation);
                }
            }

            try {
                $this->generateVariants($original);
            } finally {
                // GD-resurserna frigörs alltid — annars äter en batch upp
                // memory_limit (issue 18 § Att se upp med).
                imagedestroy($original);
            }
        } catch (Throwable $e) {
            Log::error('Kunde inte generera miniatyrer', [
                'stored_file_id' => $this->storedFile->id,
                'storage_path' => $this->storedFile->storage_path,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ryms en avkodad bild på bredd × höjd pixlar i det minne som är kvar?
     * `null` = obegränsat (memory_limit -1).
     */
    public static function fitsInMemory(int $width, int $height, ?int $remainingBytes): bool
    {
        return $remainingBytes === null
            || $width * $height * self::GD_BYTES_PER_PIXEL <= $remainingBytes;
    }

    /**
     * memory_limit minus använt minne i byte, eller null när gränsen är -1.
     */
    private static function remainingMemoryBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $bytes = (int) $raw;

        $bytes *= match (strtoupper(substr($raw, -1))) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return max(0, $bytes - memory_get_usage(true));
    }

    /**
     * HEIC, HEIF och TIFF: läses med Imagick och skrivs som JPEG. Fel fångas
     * och loggas som för GD-vägen (Beslut 5) — en PDF som utger sig för att
     * vara en TIFF stoppas av TIFF-avkodaren och ger inga derivat.
     */
    private function handleWithImagick(): void
    {
        try {
            $format = self::IMAGICK_FORMAT[$this->storedFile->mime_type];
            $path = Storage::disk('files')->path($this->storedFile->storage_path);

            // Gränserna sätts före läsningen. De gäller processen, vilket är
            // avsikten: ingen annan kod i appen läser bilder med Imagick.
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::LIMIT_MEMORY_BYTES);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, self::LIMIT_MAP_BYTES);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, self::LIMIT_AREA_PIXELS);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, self::LIMIT_SIDE_PIXELS);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, self::LIMIT_SIDE_PIXELS);

            $original = new Imagick;

            try {
                // Uttryckligt format före sökvägen (Beslut 4) och `[0]` för
                // första sidan/bilden i en fil med flera.
                $original->readImage($format.':'.$path.'[0]');
                $original->setIteratorIndex(0);

                $this->orient($original);
                $original->setImageBackgroundColor('white');
                $original->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $original->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                $original->stripImage();

                $this->generateImagickVariants($original);
            } finally {
                $original->clear();
            }
        } catch (Throwable $e) {
            Log::error('Kunde inte generera miniatyrer', [
                'stored_file_id' => $this->storedFile->id,
                'storage_path' => $this->storedFile->storage_path,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tillämpar EXIF-orienteringen på pixlarna och nollställer taggen, så att
     * en bild från en iPhone inte står på sidan (och inte roteras två gånger).
     */
    private function orient(Imagick $image): void
    {
        $degrees = match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_BOTTOMRIGHT, Imagick::ORIENTATION_BOTTOMLEFT => 180,
            Imagick::ORIENTATION_RIGHTTOP, Imagick::ORIENTATION_RIGHTBOTTOM => 90,
            Imagick::ORIENTATION_LEFTBOTTOM, Imagick::ORIENTATION_LEFTTOP => 270,
            default => 0,
        };

        // Speglade orienteringar: vänd först, rotera sedan.
        match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_TOPRIGHT, Imagick::ORIENTATION_BOTTOMLEFT, Imagick::ORIENTATION_LEFTTOP, Imagick::ORIENTATION_RIGHTBOTTOM => $image->flopImage(),
            default => null,
        };

        if ($degrees !== 0) {
            $image->rotateImage('white', $degrees);
        }

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Skriver båda varianterna som JPEG. Längsta sidan krymps till målet men
     * bilden förstoras aldrig — är den mindre kodas den ändå om (Beslut 3).
     */
    private function generateImagickVariants(Imagick $original): void
    {
        $width = $original->getImageWidth();
        $height = $original->getImageHeight();
        $longestSide = max($width, $height);
        $disk = Storage::disk('files');

        foreach (self::VARIANTS as $variant => $target) {
            $copy = clone $original;

            try {
                if ($longestSide > $target) {
                    $scale = $target / $longestSide;
                    $copy->resizeImage(
                        max(1, (int) round($width * $scale)),
                        max(1, (int) round($height * $scale)),
                        Imagick::FILTER_LANCZOS,
                        1,
                    );
                }

                $copy->setImageFormat('jpeg');
                $copy->setImageCompression(Imagick::COMPRESSION_JPEG);
                $copy->setImageCompressionQuality(self::IMAGICK_QUALITY);

                $bytes = $copy->getImageBlob();
            } finally {
                $copy->clear();
            }

            $path = $this->storedFile->storage_path.'_'.$variant.'.jpg';
            $disk->put($path, $bytes);

            ImageDerivative::updateOrCreate(
                ['stored_file_id' => $this->storedFile->id, 'variant' => $variant],
                ['storage_path' => $path, 'byte_size' => strlen($bytes)],
            );
        }
    }

    /**
     * EXIF-orienteringen (1–8) ur en JPEG. Ett värde utanför 1–8, en fil som
     * inte går att läsa och varje fel räknas som 1 — metoden kastar aldrig.
     */
    private function readOrientation(string $path): int
    {
        try {
            $orientation = 1;

            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
            } elseif (self::imagickAvailable()) {
                $probe = new Imagick;

                try {
                    $probe->pingImage($path);
                    // Imagick::ORIENTATION_* har EXIF:s värden; 0 = odefinierad.
                    $orientation = $probe->getImageOrientation();
                } finally {
                    $probe->clear();
                }
            }

            return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * Returnerar en rättvänd bild enligt EXIF-orienteringen. Den gamla bilden
     * förstörs; imagerotate() roterar moturs.
     */
    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        // Speglade orienteringar: vänd först, rotera sedan.
        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };

        $degrees = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if ($flip !== null) {
            imageflip($image, $flip);
        }

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);
        imagedestroy($image);

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('Bilden kunde inte roteras.');
        }

        return $rotated;
    }

    /**
     * Skalar originalets bild till varje variant och skriver rad och fil.
     */
    private function generateVariants(GdImage $original): void
    {
        $width = imagesx($original);
        $height = imagesy($original);
        $disk = Storage::disk('files');

        foreach (self::VARIANTS as $variant => $target) {
            $longestSide = max($width, $height);

            // Förstora aldrig (Beslut 2): en bild som redan är mindre än
            // eller lika med målet får ingen variant.
            if ($longestSide <= $target) {
                continue;
            }

            $scale = $target / $longestSide;
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $derivative = imagecreatetruecolor($newWidth, $newHeight);

            if (! $derivative instanceof GdImage) {
                throw new RuntimeException('Bildytan för varianten kunde inte skapas.');
            }

            try {
                // imagescale() bevarar inte alfakanalen i PNG om den inte
                // förbereds (issue 18 § Att se upp med) — en genomskinlig
                // bild får inte bli svart. imagealphablending(false) +
                // imagesavealpha(true) gäller även webp.
                imagealphablending($derivative, false);
                imagesavealpha($derivative, true);

                $transparent = imagecolorallocatealpha($derivative, 0, 0, 0, 127);
                imagefill($derivative, 0, 0, $transparent);

                imagecopyresampled($derivative, $original, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                $bytes = $this->encode($this->storedFile->mime_type, $derivative);
            } finally {
                imagedestroy($derivative);
            }

            $path = $this->storedFile->storage_path.'_'.$variant.'.'.self::EXTENSION[$this->storedFile->mime_type];
            $disk->put($path, $bytes);

            // updateOrCreate gör omkörningen ofarlig (Beslut 1): UNIQUE
            // (stored_file_id, variant) — exakt en rad per variant, hur många
            // gånger jobbet än körs.
            ImageDerivative::updateOrCreate(
                ['stored_file_id' => $this->storedFile->id, 'variant' => $variant],
                ['storage_path' => $path, 'byte_size' => strlen($bytes)],
            );
        }
    }

    /**
     * Avkodar originalets byten med den funktion som matchar MIME-typen.
     * `@`-tystningen gör ett avkodningsfel till en RuntimeException i stället
     * för en E_WARNING — felet ska loggas och jobbet avslutas (Beslut 5),
     * inte skräpa i loggen som en varning.
     */
    private function readImage(string $mime, string $path): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/bmp' => @imagecreatefrombmp($path),
            'image/avif' => @imagecreatefromavif($path),
            default => throw new RuntimeException("Okänd MIME-typ för bildläsning ({$mime})."),
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException("Bilden kunde inte avkodas av GD ({$mime}).");
        }

        return $image;
    }

    /**
     * Kodar om bilden till originalets format: jpeg och webp med kvalitet 82,
     * avif med kvalitet 60, png, gif och bmp utan kvalitetsparametrar
     * (Beslut 4).
     */
    private function encode(string $mime, GdImage $image): string
    {
        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 82),
            'image/png' => imagepng($image),
            'image/webp' => imagewebp($image, null, 82),
            'image/gif' => imagegif($image),
            'image/bmp' => imagebmp($image),
            'image/avif' => imageavif($image, null, 60),
            default => throw new RuntimeException("Okänd MIME-typ för bildkodning ({$mime})."),
        };

        $bytes = ob_get_clean();

        if (! $ok || $bytes === false) {
            throw new RuntimeException("Bildkodningen misslyckades ({$mime}).");
        }

        return $bytes;
    }
}
