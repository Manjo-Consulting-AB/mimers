<?php

namespace App\Http\Requests\Settings;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /settings/accounts/{account}, se issue 53c § Beslut 5 och 7. Kroppen
 * bär de fält en `owner` eller `admin` får ändra på kontot: `name`, `locale`,
 * `timezone`, `unit_system` och — sedan issue 85 · [[ADR-0037 Valutans arv]]
 * — `currency`.
 *
 * De fyra första är OBLIGATORISKA, till skillnad från användarens
 * motsvarigheter i App\Http\Requests\Settings\UpdateProfileRequest. Kontots
 * värden är botten i kedjan — det som gäller när användaren inte valt något
 * (Beslut 2) — och ett `null` där vore en inställning utan svar. Användarens
 * `null` betyder "följ kontot"; kontots `null` skulle betyda "följ ..."
 * ingenting.
 *
 * **`currency` är `sometimes` och ändå `required`.** Nyckeln får SAKNAS — då
 * rörs kolumnen inte — men ett värde som är där får aldrig vara tomt, av
 * samma skäl som de fyra andra: `account.currency` är obligatorisk i schemat,
 * och containerns arv (App\Models\Container::effectiveCurrency()) har
 * ingenting att falla tillbaka på om kontot saknar en valuta. `sometimes` är
 * för de anropare som redan skickar de fyra obligatoriska fälten och inte ska
 * behöva lära sig ett femte för att byta ett namn; inställningsformuläret
 * skickar alltid fältet. Att utelämna nyckeln KAN därför aldrig tömma
 * valutan, och ett tomt värde avvisas.
 *
 * **En ändring av kontots valuta märker aldrig om en skriven kostnadsrad.**
 * `cost_entry.currency` rörs inte av den här requesten eller av någon
 * migration i issue 85 — det som står i en rad är vad som betalades, och det
 * nya värdet gäller bara rader som skrivs härefter ([[ADR-0037 Valutans arv]]
 * § Beslut).
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
 *
 * `currency` prövas däremot på FORMEN (`alpha`, `size:3`) och normaliseras
 * till versaler, exakt som i App\Http\Requests\Container\
 * UpdateContainerRequest: en valuta är ingen uppräkning, och en lista i koden
 * vore domänen inbyggd i den ([[ADR-0033 Produktens omfång]]).
 */
class UpdateAccountSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Valutan trimmas och normaliseras till versaler, så en klient som
     * skickar `sek` lagrar `SEK` — samma väg som containerns valuta går i
     * App\Http\Requests\Container\UpdateContainerRequest. Blanksteg blir en
     * tom sträng, som `required` avvisar. En nyckel som SAKNAS lämnas orörd;
     * det är hela skillnaden mellan `sometimes` och ett tomt värde.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('currency')) {
            return;
        }

        $currency = $this->input('currency');

        if (is_string($currency)) {
            $this->merge(['currency' => mb_strtoupper(trim($currency))]);
        }
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
            'currency' => ['sometimes', 'required', 'string', 'alpha', 'size:3'],
        ];
    }
}
