<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lägger en fil på ett ärende — se [[ADR-0095 Integritetsbegäranden]] § 10
 * och [[Konton och åtkomst]] § privacy_request_attachment.
 *
 * **Filen kopieras in, källfilen lämnas orörd.** Handläggaren raderar den
 * själv när kopian är gjord; actionen rör den aldrig (ADR-0095 § 10: inga
 * onödiga kopior). Kopian hamnar på den egna disken `privacy` under
 * `{ärendets ulid}/{bilagans ulid}`, utan filändelse — originalnamnet står
 * bara i tabellen.
 *
 * **Storleken och SHA-256 räknas på servern**, aldrig tagna från en klient:
 * `sha256` är beviset på att filen som ligger på disken är den som lades
 * till. En fil som är större än `config('privacy.attachment_max_bytes')`
 * vägras, liksom en sökväg som inte är en läsbar vanlig fil — och då skrivs
 * varken rad eller fil.
 *
 * **Raden och händelsen `attachment_added` skrivs i EN transaktion**, efter
 * att filen lagts på disken. Faller transaktionen tas filen bort igen: en
 * fil utan rad går inte att hitta, och ingen kvot eller gallring räknar den.
 *
 * Bilagan hör inte till den innehållsadresserade lagringen: ingen
 * `stored_file`, ingen dedup, ingen kvot och ingen rutt som levererar den.
 * Ett avslutat ärende tar fortfarande emot bilagor — svaret arkiveras ofta
 * efter avslutet — så actionen prövar inte statusen.
 *
 * **`handleContents()` är samma väg för ett innehåll som redan finns i
 * minnet.** `privacy:collect` genererar sin sammanställning som en sträng
 * och får aldrig skriva den till en temporär fil först (ADR-0095 § 12):
 * bilagan ska bara finnas på disken `privacy`, och en kvarglömd tempfil är
 * en kopia ingen gallring känner. Raden, händelsen `attachment_added` och
 * hash-kontrollen är desamma som för en källfil.
 */
class AttachPrivacyAttachment
{
    /**
     * Disken i config/filesystems.php. Ingen annan disk får bära bilagorna.
     */
    private const DISK = 'privacy';

    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar värdet mot.
     */
    private const KIND = 'attachment_added';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $path  Sökvägen till filen som ska kopieras in.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när sökvägen inte är en läsbar vanlig
     *                                  fil, när filen eller kopian är för stor
     *                                  eller när kopian skiljer sig från
     *                                  filen som lästes först; varken rad
     *                                  eller fil finns då kvar.
     */
    public function handle(
        PrivacyRequest $request,
        string $path,
        string $actor,
    ): PrivacyRequestAttachment {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Filen {$path} är inte en läsbar vanlig fil.");
        }

        $size = filesize($path);

        if ($size === false) {
            throw new InvalidArgumentException("Storleken på {$path} kunde inte läsas.");
        }

        $max = (int) config('privacy.attachment_max_bytes');

        if ($size > $max) {
            throw new InvalidArgumentException(
                "Filen är större än gränsen på {$max} byte."
            );
        }

        return $this->store(
            request: $request,
            originalName: basename($path),
            expectedSize: $size,
            actor: $actor,
            write: fn (string $storagePath) => $this->copy($path, $storagePath),
        );
    }

    /**
     * Lägger ett innehåll som redan finns i minnet på ärendet.
     *
     * Samma rad, händelse och hash-kontroll som `handle()`, men utan en
     * källfil: innehållet skrivs direkt till disken `privacy`. Det är vägen
     * för `privacy:collect`, som aldrig får lämna en temporär kopia efter
     * sig (ADR-0095 § 12).
     *
     * @throws InvalidArgumentException när innehållet är större än gränsen;
     *                                  ingenting har då skrivits.
     */
    public function handleContents(
        PrivacyRequest $request,
        string $originalName,
        string $contents,
        string $actor,
    ): PrivacyRequestAttachment {
        $max = (int) config('privacy.attachment_max_bytes');
        $size = strlen($contents);

        if ($size > $max) {
            throw new InvalidArgumentException(
                "Filen är större än gränsen på {$max} byte."
            );
        }

        return $this->store(
            request: $request,
            originalName: $originalName,
            expectedSize: $size,
            actor: $actor,
            write: fn (string $storagePath) => $this->writeContents($storagePath, $contents),
        );
    }

    /**
     * Skriver filen på disken, kontrollerar kopian och skriver raden och
     * händelsen i EN transaktion. Skrivningen är en callable, så att en
     * källfil och ett innehåll i minnet går samma väg genom kontrollerna.
     *
     * @param  callable(string): void  $write
     *
     * @throws InvalidArgumentException när kopian blev för stor eller skiljer
     *                                  sig från det som skulle skrivas.
     */
    private function store(
        PrivacyRequest $request,
        string $originalName,
        int $expectedSize,
        string $actor,
        callable $write,
    ): PrivacyRequestAttachment {
        // ULID:n måste finnas innan filen skrivs: sökvägen på disken slutar
        // med den. HasUlid fyller bara i ett tomt värde, så den som sätts
        // här står kvar.
        $attachment = new PrivacyRequestAttachment;
        $attachment->ulid = (string) Str::ulid();
        $attachment->privacy_request_id = $request->getKey();
        $attachment->original_name = $originalName;
        $attachment->added_by = $actor;

        $storagePath = $request->ulid.'/'.$attachment->ulid;

        // Hashen och storleken räknas på kopian som faktiskt ligger på
        // disken — aldrig på källan. Ändras källan mellan den första
        // avläsningen och skrivningen är det kopian som gäller, och den
        // vägras: hashen är beviset på att filen på disken är den som lades
        // till.
        try {
            $write($storagePath);

            [$storedSize, $storedHash] = $this->inspectStoredCopy($storagePath);
        } catch (Throwable $e) {
            $this->deleteQuietly($storagePath);

            throw $e;
        }

        $max = (int) config('privacy.attachment_max_bytes');

        if ($storedSize > $max) {
            $this->deleteQuietly($storagePath);

            throw new InvalidArgumentException(
                "Filen är större än gränsen på {$max} byte."
            );
        }

        if ($storedSize !== $expectedSize) {
            $this->deleteQuietly($storagePath);

            throw new InvalidArgumentException(
                "Filen ändrades under kopieringen: kopian är {$storedSize} byte, "
                ."den första avläsningen {$expectedSize} byte."
            );
        }

        $attachment->size_bytes = $storedSize;
        $attachment->sha256 = $storedHash;

        try {
            return DB::transaction(function () use ($request, $attachment, $actor): PrivacyRequestAttachment {
                $attachment->save();

                $this->recordEvent->handle(
                    request: $request,
                    kind: self::KIND,
                    actor: $actor,
                    data: [
                        'ulid' => $attachment->ulid,
                        'original_name' => $attachment->original_name,
                        'size_bytes' => $attachment->size_bytes,
                        'sha256' => $attachment->sha256,
                    ],
                );

                return $attachment;
            });
        } catch (Throwable $e) {
            // Raden finns inte (eller rullades tillbaka). Filen får inte
            // ligga kvar utan rad — ingen gallring känner den.
            $this->deleteQuietly($storagePath);

            throw $e;
        }
    }

    /**
     * Läser tillbaka kopian från disken och räknar storleken och SHA-256 på
     * bytena som faktiskt ligger där. Strömmen läses bit för bit, så en fil
     * på 25 MB går inte genom minnet.
     *
     * @return array{0: int, 1: string} Storlek i byte och hexdigest.
     */
    private function inspectStoredCopy(string $storagePath): array
    {
        $stream = Storage::disk(self::DISK)->readStream($storagePath);

        if ($stream === null) {
            throw new RuntimeException('Kopian kunde inte läsas tillbaka från disken privacy.');
        }

        $hash = hash_init('sha256');

        try {
            $storedSize = hash_update_stream($hash, $stream);
        } finally {
            fclose($stream);
        }

        return [$storedSize, hash_final($hash)];
    }

    /**
     * Tar bort en fil utan att en misslyckad borttagning döljer felet som
     * ledde hit. Disken har `throw => true`, så `delete` kan självt kasta.
     */
    private function deleteQuietly(string $storagePath): void
    {
        try {
            Storage::disk(self::DISK)->delete($storagePath);
        } catch (Throwable) {
            // Kopian ligger kvar; felet som ledde hit är det som ska ut.
        }
    }

    /**
     * Skriv ett innehåll som redan ligger i minnet direkt till disken.
     * `throw => true` på disken gör en misslyckad skrivning till ett
     * undantag i stället för ett tyst `false`.
     */
    private function writeContents(string $storagePath, string $contents): void
    {
        $written = Storage::disk(self::DISK)->put($storagePath, $contents);

        if ($written === false) {
            throw new RuntimeException('Filen kunde inte skrivas till disken privacy.');
        }
    }

    /**
     * Kopiera bytena som en ström, så att en fil på 25 MB inte behöver
     * läsas in i minnet. Källan stängs alltid; `throw => true` på disken gör
     * en misslyckad skrivning till ett undantag i stället för ett tyst
     * `false`.
     */
    private function copy(string $path, string $storagePath): void
    {
        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new RuntimeException("Filen {$path} kunde inte öppnas.");
        }

        try {
            $written = Storage::disk(self::DISK)->writeStream($storagePath, $source);
        } finally {
            fclose($source);
        }

        if ($written === false) {
            throw new RuntimeException('Filen kunde inte skrivas till disken privacy.');
        }
    }
}
