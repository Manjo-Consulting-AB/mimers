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
    private const VARIANTER = [
        'thumb' => 320,
        'medium' => 1024,
    ];

    /**
     * MIME-typ → filändelse (Beslut 4). Samma uppsättning avgör både om
     * StoreAttachment köar jobbet och vilka typer jobbet läser.
     *
     * @var array<string, string>
     */
    private const ÄNDELSE = [
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
        return isset(self::ÄNDELSE[$mime]);
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
            $original = $this->läsBild(
                $this->storedFile->mime_type,
                Storage::disk('files')->path($this->storedFile->storage_path),
            );

            try {
                $this->generera($original);
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
    private function generera(GdImage $original): void
    {
        $bredd = imagesx($original);
        $höjd = imagesy($original);
        $disk = Storage::disk('files');

        foreach (self::VARIANTER as $variant => $mål) {
            $längsta = max($bredd, $höjd);

            // Förstora aldrig (Beslut 2): en bild som redan är mindre än
            // eller lika med målet får ingen variant.
            if ($längsta <= $mål) {
                continue;
            }

            $skala = $mål / $längsta;
            $nyBredd = max(1, (int) round($bredd * $skala));
            $nyHöjd = max(1, (int) round($höjd * $skala));

            $derivat = imagecreatetruecolor($nyBredd, $nyHöjd);

            if (! $derivat instanceof GdImage) {
                throw new RuntimeException('Bildytan för varianten kunde inte skapas.');
            }

            try {
                // imagescale() bevarar inte alfakanalen i PNG om den inte
                // förbereds (issue 18 § Att se upp med) — en genomskinlig
                // bild får inte bli svart. imagealphablending(false) +
                // imagesavealpha(true) gäller även webp.
                imagealphablending($derivat, false);
                imagesavealpha($derivat, true);

                $transparent = imagecolorallocatealpha($derivat, 0, 0, 0, 127);
                imagefill($derivat, 0, 0, $transparent);

                imagecopyresampled($derivat, $original, 0, 0, 0, 0, $nyBredd, $nyHöjd, $bredd, $höjd);

                $byten = $this->koda($this->storedFile->mime_type, $derivat);
            } finally {
                imagedestroy($derivat);
            }

            $sökväg = $this->storedFile->storage_path.'_'.$variant.'.'.self::ÄNDELSE[$this->storedFile->mime_type];
            $disk->put($sökväg, $byten);

            // updateOrCreate gör omkörningen ofarlig (Beslut 1): UNIQUE
            // (stored_file_id, variant) — exakt en rad per variant, hur många
            // gånger jobbet än körs.
            ImageDerivative::updateOrCreate(
                ['stored_file_id' => $this->storedFile->id, 'variant' => $variant],
                ['storage_path' => $sökväg, 'byte_size' => strlen($byten)],
            );
        }
    }

    /**
     * Avkodar originalets byten med den funktion som matchar MIME-typen.
     * `@`-tystningen gör ett avkodningsfel till en RuntimeException i stället
     * för en E_WARNING — felet ska loggas och jobbet avslutas (Beslut 5),
     * inte skräpa i loggen som en varning.
     */
    private function läsBild(string $mime, string $sökväg): GdImage
    {
        $bild = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sökväg),
            'image/png' => @imagecreatefrompng($sökväg),
            'image/webp' => @imagecreatefromwebp($sökväg),
            default => throw new RuntimeException("Okänd MIME-typ för bildläsning ({$mime})."),
        };

        if (! $bild instanceof GdImage) {
            throw new RuntimeException("Bilden kunde inte avkodas av GD ({$mime}).");
        }

        return $bild;
    }

    /**
     * Kodar om bilden till originalets format: jpeg och webp med kvalitet 82,
     * png utan kvalitetsparametrar (Beslut 4).
     */
    private function koda(string $mime, GdImage $bild): string
    {
        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($bild, null, 82),
            'image/png' => imagepng($bild),
            'image/webp' => imagewebp($bild, null, 82),
            default => throw new RuntimeException("Okänd MIME-typ för bildkodning ({$mime})."),
        };

        $byten = ob_get_clean();

        if (! $ok || $byten === false) {
            throw new RuntimeException("Bildkodningen misslyckades ({$mime}).");
        }

        return $byten;
    }
}
