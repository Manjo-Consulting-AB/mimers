<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Tar bort en bilaga från ett ärende — se [[ADR-0095 Integritetsbegäranden]]
 * § 10 och [[Konton och åtkomst]] § privacy_request_attachment.
 *
 * **Skälet krävs.** En bilaga som tas bort är ett bevis som försvinner, och
 * skälet sparas på händelsen `attachment_removed` i dess `body` — namnet och
 * hashen står i `data`, så att det går att se vilken fil som avsågs även
 * sedan raden är borta.
 *
 * **Filen först, raden sedan**, samma ordning som PurgesExpiredStoredFiles:
 * en fil som redan saknas är inte ett fel — ett tidigare försök kan ha
 * hunnit halvvägs — och raden tas bort ändå. Motsatta ordningen hade lämnat
 * en fil utan rad, som ingen gallring känner.
 *
 * Filen, händelsen och borttagningen av raden sker i EN transaktion. Faller
 * skrivningen ligger raden kvar och ett nytt försök kan ta bort den.
 */
class DetachPrivacyAttachment
{
    /**
     * Disken i config/filesystems.php.
     */
    private const DISK = 'privacy';

    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som RecordPrivacyEvent prövar värdet mot.
     */
    private const KIND = 'attachment_removed';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $attachmentUlid  Bilagans ULID.
     * @param  string  $reason  Skälet till att bilagan tas bort; obligatoriskt.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när skälet saknas eller bilagan inte
     *                                  finns på ärendet; ingenting har då
     *                                  tagits bort.
     */
    public function handle(
        PrivacyRequest $request,
        string $attachmentUlid,
        string $reason,
        string $actor,
    ): PrivacyRequestAttachment {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('--reason måste anges: skälet till att bilagan tas bort.');
        }

        $attachment = $request->attachments()
            ->where('ulid', $attachmentUlid)
            ->first();

        if ($attachment === null) {
            throw new InvalidArgumentException(
                "Ingen bilaga med ULID {$attachmentUlid} på ärendet."
            );
        }

        $storagePath = $request->ulid.'/'.$attachment->ulid;

        return DB::transaction(function () use ($request, $attachment, $storagePath, $reason, $actor): PrivacyRequestAttachment {
            Storage::disk(self::DISK)->delete($storagePath);

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                body: $reason,
                data: [
                    'original_name' => $attachment->original_name,
                    'sha256' => $attachment->sha256,
                ],
            );

            $attachment->delete();

            return $attachment;
        });
    }
}
