<?php

namespace App\Http\Requests\Schedule;

use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items/{item}/schedules/{schedule}/dependencies,
 * se issue 23 § Beslut 4 och 9. Kroppen är `{depends_on: "<ULID>"}` — ULID:en
 * för det schema rutten gäller ska vänta på. En ULID, inte en lista (§
 * Beslut 4): två beroenden är två anrop, för en lista kräver ett svar på vad
 * som händer om den tredje bildar en cykel men de två första inte gör det.
 *
 * `depends_on`-ULID:en måste finnas i DEN container rutten redan bär och får
 * inte vara mjukraderad — en ULID som finns men hör till en annan container
 * är ett VALIDERINGSFEL (422 `validation.failed`), inte en 404 och inte ett
 * behörighetsfel, samma gränsdragning som issue 13a § Beslut 7 och 14 §
 * Beslut 7.
 *
 * `prepareForValidation()` löser ULID:en till schemats löpnummer INNAN
 * reglerna prövas — Rule::unique jämför fältets värde mot en kolumn, och
 * kolumnen `depends_on_schedule_id` är ett löpnummer, inte en ULID. Fältet
 * bär därför schemats id internt; svaret och felhöljet exponerar det aldrig.
 * En ULID som inte finns (eller är mjukraderad) blir null och fäller
 * `required`.
 *
 * Dubbletten (samma par en gång till) är ett VALIDERINGSFEL (§ Beslut 9):
 * `Rule::unique` på paret, inte en tyst no-op och inte en 201 som låtsas ha
 * skapat något. Det unika indexet i migrationen ligger kvar som sista
 * skyddsnät.
 *
 * Att `depends_on` inte är schemat självt (dependency_self) och att ingen
 * cykel uppstår (dependency_cycle) prövas i App\Actions\Schedule\DependSchedule,
 * inte här — de är domänregler, inte fältfel (§ Beslut 5).
 */
class StoreScheduleDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 23 § Beslut 3.
        return true;
    }

    /**
     * Löser `depends_on`-ULID:en till schemats löpnummer, se klassdocblocket.
     * Sökningen går genom Schedule-modellen, så SoftDeletes globala scope
     * filtrerar redan bort mjukraderade scheman.
     */
    protected function prepareForValidation(): void
    {
        $dependsOn = $this->input('depends_on');

        if ($dependsOn === null) {
            return;
        }

        if (! is_string($dependsOn)) {
            // En ULID är en sträng — ett heltal eller en array i kroppen är
            // alltid fel. Null gör att `required` fäller.
            $this->merge(['depends_on' => null]);

            return;
        }

        $this->merge([
            'depends_on' => Schedule::query()->where('ulid', $dependsOn)->value('id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $container = $this->route('container');
        $schedule = $this->route('schedule');

        return [
            'depends_on' => [
                'required',
                'integer',
                Rule::exists('schedule', 'id')->where(
                    fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->whereIn('item_id', Item::query()->select('id')->where('container_id', $container->id))
                ),
                Rule::unique('schedule_dependency', 'depends_on_schedule_id')->where(
                    fn ($query) => $query->where('schedule_id', $schedule->id)
                ),
            ],
        ];
    }
}
