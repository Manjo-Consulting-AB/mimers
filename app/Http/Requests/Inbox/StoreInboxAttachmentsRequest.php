<?php

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kroppen för `POST /inbox/attachments` — `multipart/form-data` med en eller
 * flera filer i fältet `files`. M27 · issue 245, se [[ADR-0054 Inboxen]] § 4
 * och 8.
 *
 * **Fältet är en lista och inte en fil.** Itemets uppladdning
 * (App\Http\Requests\Attachment\StoreAttachmentRequest) tar en fil per anrop
 * därför att vyn kör en kö av ett-anrop-per-fil; inboxen fångar det användaren
 * släppte in på en gång, och här är en lista den ärliga formen. Kontrollern
 * skickar dem ett i taget till App\Actions\Attachment\StoreAttachment ändå —
 * actionen rör en fil, och en andra väg in i den hade varit en andra sanning
 * om hashen, dedupen och referensräkningen.
 *
 * **Inget `account`-fält.** Kontot är personkontot — inboxens ägare
 * ([[ADR-0054 Inboxen]] § 3) — och bestäms av servern, inte av klienten. Den
 * som fångar en fil får inte välja vem som betalar för den.
 *
 * `max` räknar kilobyte och speglar det TEKNISKA taket, samma tal som
 * `StoreAttachmentRequest` använder (issue 16a § Beslut 9). Det är inte
 * planens gräns; den prövas i kontrollern.
 */
class StoreInboxAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'max:'.(int) (config('files.max_upload_bytes') / 1024)],
        ];
    }
}
