<?php

namespace App\Http\Requests\Account;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * DELETE /api/accounts/{account}/storage, se issue 28 § Beslut 6. Kroppen
 * är `{"attachments": ["01J...", "01J..."]}` — de bilagor användaren valt
 * att rensa bort ur kontots lagringsplats.
 *
 * Valideringen bevisar att varje ULID finns i `attachment`, tillhör KONTOT
 * (`billed_account_id`) och är levande (`deleted_at IS NULL`) — en ULID som
 * inte gör det är 422 `validation.failed` för HELA begäran, aldrig en tyst
 * delvis radering (Beslut 6). `Rule::exists` går direkt mot tabellen; om
 * `whereNull('deleted_at')` inte fanns skulle en mjukraderad bilaga passera
 * valideringen och rensningen bli en no-op som ändå svarar 200.
 *
 * Gränserna: minst 1, högst 100 — en bulkrensning ska inte kunna bli ett
 * oändligt arbete i ett anrop.
 *
 * Kontot i rutten är inte den inloggade användarens enda konto, så auktorisationen
 * avgörs av kontrollern mot `AccountPolicy::manageStorage()`, inte här — samma
 * mönster som RestoreRequest::authorize() (issue 20a § Beslut 6).
 */
class RemoveStorageRequest extends FormRequest
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
            'attachments' => ['required', 'array', 'min:1', 'max:100'],
            'attachments.*' => ['required', 'string', $this->attachmentExistsRule()],
        ];
    }

    private function attachmentExistsRule(): Exists
    {
        $account = $this->route('account');
        assert($account instanceof Account);

        return Rule::exists('attachment', 'ulid')->where(
            fn ($query) => $query
                ->where('billed_account_id', $account->id)
                ->whereNull('deleted_at')
        );
    }
}
