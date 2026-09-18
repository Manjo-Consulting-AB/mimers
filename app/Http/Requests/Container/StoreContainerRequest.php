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
 * **Ett utelämnat `kind` lagras som `null`, och det avgörs HÄR.** Kolumnen är
 * nullbar sedan issue 84, och `null` är det ärliga värdet för "användaren har
 * inte svarat än" — en tom sträng hade varit precis den sentinel
 * [[ADR-0004 Fria taggar och kategorier]] vill undvika. Normaliseringen ligger
 * i requesten därför att BÅDA anropare delar den: webbens
 * App\Http\Controllers\ContainerController::store() och API:ets
 * App\Http\Controllers\Api\ContainerController::store().
 *
 * **Blanksteg trimmas bort vid inmatningen** ([[ADR-0016
 * Kostnadsregistrering]] § Motivering: stavningsvarianter löses när de
 * skrivs, inte i schemat). Ett fält som bara
 * var blanksteg blir därmed `null` och inte en art som ser tom ut men inte är
 * det. Värdet lagras i övrigt ORDAGRANT — ingen skiftlägesnormalisering, ingen
 * hopslagning av varianter.
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
     * Trimning och tomt-till-`null` — se klassdokumentationen. Körningen sker
     * FÖRE reglerna, så det som prövas av `nullable` och `max:40` är det
     * trimmade värdet. Ett icke-strängvärde lämnas orört: det är ett
     * valideringsfel och ska bli ett, inte tystnas bort här.
     */
    protected function prepareForValidation(): void
    {
        $kind = $this->input('kind');

        if (is_string($kind)) {
            $kind = trim($kind);

            $this->merge(['kind' => $kind === '' ? null : $kind]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', 'string', 'max:40'],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')],
        ];
    }
}
