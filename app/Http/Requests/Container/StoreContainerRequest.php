<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers, se issue 8 § Beslut 8: kroppen är
 * `{"name", "kind", "account"}` där `account` är ett konto-ULID, inte
 * kontots löpnummer.
 *
 * `kind` är FRIVILLIGT sedan issue 84 · [[ADR-0036 Containerns art]]: att
 * tvinga fram en art vid skapandet är att ställa en fråga användaren ännu
 * inte kan svara på. Reglerna är längd och format — `max:40` är kolumnens
 * bredd — och aldrig medlemskap i en lista. Ett fritt fält som valideras mot
 * en sluten mängd vore samma domän i koden som CHECK-villkoret var.
 *
 * **Ett utelämnat `kind` blir den tomma strängen, och det avgörs HÄR.**
 * Kolumnen är NOT NULL och App\Actions\Container\CreateContainer::handle()
 * tar en `string`; den som lämnar fältet tomt ska mötas av en container utan
 * art, inte av ett typfel. Normaliseringen ligger i requesten och inte i
 * kontrollern därför att BÅDA anropare delar den: webbens
 * App\Http\Controllers\ContainerController::store() och API:ets
 * App\Http\Controllers\Api\ContainerController::store(). Den senare ligger
 * utanför den här issuns omfångsruta, och en `null` som nådde `handle()`
 * hade blivit en TypeError i en fil rutan inte får röra. Här är `null` och
 * "nyckeln saknas" samma sak: ingen art angiven.
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
     * Ingen art angiven är den tomma strängen — se klassdokumentationen.
     * Normaliseringen sker FÖRE reglerna, så `kind` är alltid en sträng när
     * `validated()` läses, och varken `nullable` eller en `?? ''` i
     * anroparen behövs.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['kind' => $this->input('kind') ?? '']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['string', 'max:40'],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
        ];
    }
}
