<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /settings/profile/hero — personens hjältebild, se [[ADR-0090
 * Dashboardens hjälte]] § 2 och [[ADR-0076 Profilbilden]] § 2 och 3, issue
 * 434. multipart/form-data, en fil per anrop i fältet `file`.
 *
 * **Inget kontofält.** Klienten väljer inget konto: kontot som betalar är
 * personens personkonto, och det härleds ur personen i
 * App\Actions\User\SetUserHero. Samma form som App\Http\Requests\
 * UserAvatarRequest.
 *
 * **`file` valideras för närvaro och storlek.** Taket är en TEKNISK spärr
 * (issue 16a § Beslut 9), inte en plangräns. Slaget prövas inte här: att
 * filen är en bild är App\Actions\Attachment\StoreAttachment::handleForUser:s
 * krav, ställt på den SNIFFADE MIME-typen efter att filen tagits emot.
 */
class UserHeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Objektet är anroparen SJÄLV, ur $request->user() — det finns ingen
        // ruttparameter och ingen policy att pröva.
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
