<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /settings/storage/versioning — kontots tak för versionshistoriken,
 * se [[ADR-0088 Versionshistorik för items]] Riktning 1 och issue 411.
 *
 * Kroppen bär `account` (konto-ULID, samma väljare som sidan använder) och
 * `versioning_max_versions`, ett heltal 0–`max_versions_limit`. 0 betyder av.
 * Gränsen läses ur config och skrivs inte som en literalsiffra här, så
 * valideringen och vyn inte kan glida isär.
 *
 * **Medlemskapet prövas FÖRE valideringen**, i authorize(). Kontot slås upp
 * bland användarens EGNA konton: en ULID som inte finns och en ULID som
 * tillhör någon annan svarar då samma sak (403), så konto-ULID:er inte går
 * att räkna upp. En `Rule::exists` här hade gett 422 för ett existerande
 * konto och 403 först efteråt — det är den ordningen fyndet gällde.
 * Kontrollern prövar `AccountPolicy::manageStorage` på nytt.
 */
class UpdateStorageVersioningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ulid = $this->input('account');

        if (! is_string($ulid) || $this->user() === null) {
            return false;
        }

        return $this->user()->accounts()
            ->where('ulid', $ulid)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account' => ['required', 'string'],
            'versioning_max_versions' => [
                'required',
                'integer',
                'min:0',
                'max:'.config('konton.versioning.max_versions_limit'),
            ],
        ];
    }
}
