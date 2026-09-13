<?php

namespace App\Http\Requests\Settings;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /settings/accounts/{account}, se issue 53c § Beslut 5 och 7. Kroppen
 * bär de fyra fält en `owner` eller `admin` får ändra på kontot: `name`,
 * `locale`, `timezone` och `unit_system`.
 *
 * Alla fyra är OBLIGATORISKA, till skillnad från användarens motsvarigheter i
 * App\Http\Requests\Settings\UpdateProfileRequest. Kontots värden är botten i
 * kedjan — det som gäller när användaren inte valt något (Beslut 2) — och ett
 * `null` där vore en inställning utan svar. Användarens `null` betyder "följ
 * kontot"; kontots `null` skulle betyda "följ ..." ingenting.
 *
 * Ingen auktorisering här. OBJEKTET är kontot i rutten (bundet på ULID via
 * #[RouteKey('ulid')] på App\Models\Account), och prövningen mot det bor i
 * App\Policies\AccountPolicy::update() — anropad med `Gate::authorize()` i
 * App\Http\Controllers\Settings\AccountSettingsController, se [[ADR-0024
 * Tunna controllers och actions]] och samma linje som
 * App\Http\Controllers\Api\ContainerController::update() drar. Att lägga
 * roll- eller regel 4-kontrollen här hade gjort policyn till en andra åsikt
 * om samma sak.
 *
 * `locale` prövas mot `sv_SE` och `en_GB`, `timezone` mot
 * `DateTimeZone::listIdentifiers()` och `unit_system` mot
 * `metric`/`imperial` — samma värdemängder som datamodellen i [[Konton och
 * åtkomst]] § account anger, och samma regler som profilens request
 * använder. `timezone`-regeln delas dessutom med
 * App\Http\Requests\UpdateQuietHoursRequest, som skriver samma kolumn på
 * användaren; ändrar du den ena, ändra den andra.
 */
class UpdateAccountSettingsRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['sv_SE', 'en_GB'])],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'unit_system' => ['required', Rule::in(['metric', 'imperial'])],
        ];
    }
}
