<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /containers/{container}/cover — containerns bild, se [[ADR-0047
 * Containerns bild]] § Beslut och [[M23 Mobilen och kartan]] § 159.
 * multipart/form-data, en fil per anrop i fältet `file`.
 *
 * **Formen är StoreAttachmentRequest:s, och skillnaden är vad som INTE står
 * här.** Ingen `account`-regel: en containerbild hör till containern, och
 * containern har exakt en ägare (AGENTS.md § Sådant som är lätt att göra fel).
 * Kontot som betalar är därför containerns eget och slås upp på servern i
 * App\Http\Controllers\ContainerCoverController — ett fält i kroppen hade
 * varit ett val utan yta, för arket som öppnar uppladdningen
 * (resources/js/components/ContainerCoverSheet.vue) har tre rader och ingen
 * kontoväljare (ADR-0047 § Beslut: *Ta ett foto*, *Välj från enheten* och
 * *Ta bort bilden*). Samma linje som PATCH /containers/{container}, vars
 * request heller aldrig tar emot ett konto.
 *
 * **`file` valideras för närvaro och storlek.** Taket är en TEKNISK spärr
 * (issue 16a § Beslut 9), inte en plangräns — `max` räknar kilobyte, därav
 * divisionen. `config('files.max_upload_bytes')` defaultar till 64 MiB, samma
 * tal som upload_max_filesize i public/.htaccess.
 *
 * **Slaget prövas inte här.** Att filen är en bild är App\Actions\Attachment\
 * StoreAttachment::handleForContainer():s krav, och det ställs på den SNIFFADE
 * MIME-typen efter att filen tagits emot (StoreAttachment § Beslut 5). En
 * `mimes:`-regel här hade varit en andra sanning om vad filen är, byggd på
 * filändelsen.
 */
class ContainerCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörigheten i
        // kontrollern, inte här — samma uppdelning som StoreAttachmentRequest.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_upload_bytes') / 1024)],
        ];
    }
}
