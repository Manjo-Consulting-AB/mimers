<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /settings/profile/avatar och POST /api/me/avatar — profilbilden, se
 * [[ADR-0076 Profilbilden]] § 2 och 3 samt [[M59 Profilbilden]] § 380.
 * multipart/form-data, en fil per anrop i fältet `file`.
 *
 * **Inget kontofält.** Klienten väljer inget konto: kontot som betalar är
 * personens personkonto, och det härleds ur personen i
 * App\Actions\User\SetUserAvatar (ADR-0076 § 3). Det är skillnaden mot
 * App\Http\Requests\Container\ContainerCoverRequest, där `account` står i
 * kroppen för att containerns bild kan laddas upp av en mottagare som
 * belastar sitt eget konto.
 *
 * **`file` valideras för närvaro och storlek.** Taket är en TEKNISK spärr
 * (issue 16a § Beslut 9), inte en plangräns — `max` räknar kilobyte, därav
 * divisionen. `config('files.max_upload_bytes')` defaultar till 64 MiB, samma
 * tal som upload_max_filesize i public/.htaccess.
 *
 * **Slaget prövas inte här.** Att filen är en bild är
 * App\Actions\Attachment\StoreAttachment::handleForUser():s krav, och det
 * ställs på den SNIFFADE MIME-typen efter att filen tagits emot
 * (StoreAttachment § Beslut 5). En `mimes:`-regel här hade varit en andra
 * sanning om vad filen är, byggd på filändelsen.
 *
 * Samma request på båda ytorna: webben behåller Laravels vanliga
 * valideringsfel, och `/api` lägger dem i `validation.failed`-höljet genom
 * bootstrap/app.php — skillnaden sitter i renderingen, inte i reglerna.
 */
class UserAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Objektet är anroparen SJÄLV, ur $request->user() — det finns ingen
        // ruttparameter och ingen policy att pröva, samma form som
        // UpdateQuietHoursRequest.
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
