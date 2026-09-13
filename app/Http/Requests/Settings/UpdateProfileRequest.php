<?php

namespace App\Http\Requests\Settings;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /settings/profile, se issue 53c § Beslut 2 och 7. Kroppen bär de
 * fyra fält en användare får ändra om sig själv: `name`, `locale`,
 * `timezone` och `unit_system`.
 *
 * Ingen auktorisering här. Rutten ligger bakom `auth` och raden som skrivs
 * är den inloggade användarens EGEN — `$request->user()` är både subjekt och
 * objekt, precis som i App\Http\Requests\UpdateQuietHoursRequest. Det finns
 * alltså inget annat objekt att pröva mot, och ingen policy att anropa.
 *
 * `name` är obligatoriskt. De tre övriga är `nullable` med flit: `null` är
 * inte "osatt" utan värdet "följ kontots inställning" — se [[Konton och
 * åtkomst]] § user ("Åsidosätter kontots värden för den här personen") och
 * App\Models\User::preferredLocale(). En användare utan eget `locale` ärver
 * kontots, och det valet måste gå att spara tillbaka till.
 *
 * `locale` prövas mot `sv_SE` och `en_GB` — datamodellens egna värden och de
 * två språk [[ADR-0013 Språk och i18n]] beslutar om. Katalognamnen `sv`/`en`
 * är något annat: de är vad App\Support\Notification\LocaleResolver
 * översätter TILL, och de hör inte hemma i kolumnen. Läs den klassens
 * docblock innan du byter värdemängd här.
 *
 * `timezone` prövas mot `DateTimeZone::listIdentifiers()`, exakt som
 * App\Http\Requests\UpdateQuietHoursRequest gör. Den requesten skriver SAMMA
 * kolumn (`user.timezone`) — tidszonen är oskiljaktig från ett tidsfönster,
 * se [[Notiser]] § Tysta timmar och tidszon — och regeln ska bara finnas på
 * ett ställe. Ändrar du den ena, ändra den andra. Se Beslut 4.
 *
 * Namnrummet `Settings` är inte kosmetiskt: /api har ingen motsvarande rutt i
 * den här issuen (Beslut 7), men en framtida inställningsendpoint ska kunna
 * återanvända klassen rakt av. Den validerar indata och gör ingenting annat —
 * ingen bindning, ingen sparning, ingen `$request->user()`. Det bor i
 * App\Http\Controllers\Settings\ProfileController enligt [[ADR-0024 Tunna
 * controllers och actions]].
 */
class UpdateProfileRequest extends FormRequest
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
            'locale' => ['nullable', Rule::in(['sv_SE', 'en_GB'])],
            'timezone' => ['nullable', Rule::in(DateTimeZone::listIdentifiers())],
            'unit_system' => ['nullable', Rule::in(['metric', 'imperial'])],
        ];
    }
}
