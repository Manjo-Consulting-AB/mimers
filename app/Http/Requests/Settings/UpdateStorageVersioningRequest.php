<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /settings/storage/versioning — kontots tak för versionshistoriken,
 * se [[ADR-0088 Versionshistorik för items]] Riktning 1 och issue 411.
 *
 * Kroppen bär `account` (konto-ULID, samma väljare som sidan använder) och
 * `versioning_max_versions`, ett heltal 0–`max_versions_limit`. 0 betyder av.
 * Gränsen läses ur config och skrivs inte som en literalsiffra här, så
 * valideringen och vyn inte kan glida isär.
 *
 * **Medlemskapet prövas i kontrollern**, inte här: det är
 * `AccountPolicy::manageStorage()` som svarar på frågan, och requesten äger
 * bara formen på kroppen. En ULID som inte finns alls är däremot ett
 * valideringsfel (422), samma linje som de andra `exists`-reglerna i appen.
 */
class UpdateStorageVersioningRequest extends FormRequest
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
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
            'versioning_max_versions' => [
                'required',
                'integer',
                'min:0',
                'max:'.config('konton.versioning.max_versions_limit'),
            ],
        ];
    }
}
