<?php

namespace App\Http\Requests\Container;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers, se issue 8 § Beslut 8: kroppen är
 * `{"name", "kind", "account"}` där `account` är ett konto-ULID, inte
 * kontots löpnummer.
 *
 * Ett `account`-ULID som inte finns i det hela taget är ett VALIDERINGSFEL
 * (422 `validation.failed`, `exists`-regeln nedan) — skiljer sig från ett
 * konto som finns men som användaren inte är medlem i, vilket är ett
 * BEHÖRIGHETSFEL (403 `auth.forbidden`) som avgörs av
 * App\Policies\ContainerPolicy::create() i kontrollern, inte här.
 *
 * `template_source_id` tas medvetet INTE emot här — fältet finns i
 * kolumnen men sätts aldrig via API:et, se issue 8 § Att se upp med. Ett
 * klientskickat `template_source_id` i requestkroppen är alltså ett fält
 * `validated()` aldrig innehåller, och når därmed aldrig
 * App\Models\Container.
 */
class StoreContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörighet till DET ANGIVNA kontot avgörs av
        // App\Policies\ContainerPolicy::create() i kontrollern, efter att
        // valideringen nedan bevisat att kontot existerar — se
        // klassdokumentationen ovan.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in(Container::KINDS)],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
        ];
    }
}
