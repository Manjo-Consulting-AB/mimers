<?php

namespace App\Jobs;

use App\Models\ImageDerivative;
use App\Models\StoredFile;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
 * Bara tre MIME-typer får derivat, och formatet följer källan (Beslut 4):
 * jpeg (kvalitet 82), png och webp (kvalitet 82). Allt annat — svg, gif,
 * heic, dokument — får inga derivat. Sökvägen är originalets plus variantens
 * namn: `ab/cd/<hash>_thumb.jpg`. Samma disk `files`, samma prefixstruktur —
 * derivaten ligger bredvid sitt original, och gallringen i 17b hittar dem
 * utan en extra fråga.
 *
 * Jobbet är repots första `app/Jobs/`-klass och följer Laravels standard:
 * `implements ShouldQueue`, `use Queueable` (som drar in Dispatchable,
 * InteractsWithQueue, Queueable och SerializesModels), konstruktorn tar
 * modellen, `handle()` gör arbetet. Kön är databasdriven (Beslut 8) — på
 * servern dras den med `queue:work`, aldrig `queue:listen` (AGENTS.md §
 * Driftmiljön saknar proc_open).
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
    ];

    public function __construct(public StoredFile $storedFile) {}

    /**
     * Får den här MIME-typen över huvud taget derivat (Beslut 4)? StoreAttachment
     * använder svaret för att slippa köa jobb som inte kan göra något.
     */
    public static function supportsMime(string $mime): bool
    {
        return isset(self::EXTENSION[$mime]);
    }

    /**
     * Genererar de varianter av originalets bild som är större än målet.
     */
    public function handle(): void
    {
        if (! extension_loaded('gd')) {
            Log::warning('GD saknas — genererar inga miniatyrer', [
                'stored_file_id' => $this->storedFile->id,
                'storage_path' => $this->storedFile->storage_path,
            ]);

            return;
        }

        if (! self::supportsMime($this->storedFile->mime_type)) {
            return;
        }

        try {
            // Storage::disk('files')->path() ger GD den lokala sökvägen.
            // All filhantering går genom Storage-abstraktionen
            // ([[ADR-0007 Fillagring hos inleed]]); `path()` är bara hur GD,
            // som inte kan läsa en abstraktion, nås fram till bytena.
            $original = $this->readImage(
                $this->storedFile->mime_type,
                Storage::disk('files')->path($this->storedFile->storage_path),
            );

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
            default => throw new RuntimeException("Okänd MIME-typ för bildläsning ({$mime})."),
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException("Bilden kunde inte avkodas av GD ({$mime}).");
        }

        return $image;
    }

    /**
     * Kodar om bilden till originalets format: jpeg och webp med kvalitet 82,
     * png utan kvalitetsparametrar (Beslut 4).
     */
    private function encode(string $mime, GdImage $image): string
    {
        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 82),
            'image/png' => imagepng($image),
            'image/webp' => imagewebp($image, null, 82),
            default => throw new RuntimeException("Okänd MIME-typ för bildkodning ({$mime})."),
        };

        $bytes = ob_get_clean();

        if (! $ok || $bytes === false) {
            throw new RuntimeException("Bildkodningen misslyckades ({$mime}).");
        }

        return $bytes;
    }
}
