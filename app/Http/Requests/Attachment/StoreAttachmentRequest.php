<?php

namespace App\Http\Requests\Attachment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items/{item}/attachments, se issue 16a
 * § Beslut 2, 3, 9 och 14. multipart/form-data, en fil per anrop i fältet
 * `file`.
 *
 * `account` är ett obligatoriskt konto-ULID — kontot som betalar för
 * bytena, samma regel som issue 13a § Beslut 6: det finns inget serverside
 * "aktivt konto". En ULID som inte finns alls är ett valideringsfel; ett
 * konto som finns men som användaren inte är medlem i är ett
 * behörighetsfel (403 `auth.forbidden`) som kontrollern kastar, inte här.
 *
 * `file` valideras för närvaro och storlek. Taket är en TEKNISK spärr
 * (issue 16a § Beslut 9), inte en plangräns — `max` räknar kilobyte, därav
 * divisionen. `config('files.max_upload_bytes')` defaultar till 64 MiB,
 * samma tal som upload_max_filesize i public/.htaccess.
 *
 * Ingen `content_hash`-regel: en klientskickad hash ignoreras tyst (§
 * Beslut 3) — ett fält som ignoreras är dött, ett som avvisas är ett fält
 * en klient kan sondera med. Ingen `uploaded_by_user_id`-/`uploaded_by`-
 * regel heller: den kolumnen kommer alltid från token (§ Beslut 14).
 */
class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörigheten i
        // kontrollern, inte här — se issue 13a § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_upload_bytes') / 1024)],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
        ];
    }
}
