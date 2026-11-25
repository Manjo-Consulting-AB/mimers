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
     *                                  fil eller filen är för stor; ingenting
     *                                  har då skrivits.
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

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new InvalidArgumentException("Hashen för {$path} kunde inte räknas.");
        }

        // ULID:n måste finnas innan filen skrivs: sökvägen på disken slutar
        // med den. HasUlid fyller bara i ett tomt värde, så den som sätts
        // här står kvar.
        $attachment = new PrivacyRequestAttachment;
        $attachment->ulid = (string) Str::ulid();
        $attachment->privacy_request_id = $request->getKey();
        $attachment->original_name = basename($path);
        $attachment->size_bytes = $size;
        $attachment->sha256 = $hash;
        $attachment->added_by = $actor;

        $storagePath = $request->ulid.'/'.$attachment->ulid;

        $this->copy($path, $storagePath);

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
            Storage::disk(self::DISK)->delete($storagePath);

            throw $e;
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
