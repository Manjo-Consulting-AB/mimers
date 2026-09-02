<?php

namespace App\Http\Requests\Schedule;

use App\Models\Schedule;
use App\Models\ScheduleDependency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/containers/{container}/items/{item}/schedules/{schedule}/dependencies,
 * se issue 23 § Beslut 4 och 9. Kroppen är `{depends_on: "<ULID>"}` — ULID:en
 * för det schema rutten gäller ska vänta på. En ULID, inte en lista (§
 * Beslut 4): två beroenden är två anrop, för en lista kräver ett svar på vad
 * som händer om den tredje bildar en cykel men de två första inte gör det.
 *
 * `depends_on` förblir klientens ULID genom hela valideringen — fältets värde
 * skrivs aldrig om. Existensen och containern prövas med `Rule::exists` mot
 * `schedule.ulid`, begränsad i EN underfråga till containerns levande items:
 * en ULID som inte finns, är mjukraderad eller hör till en annan container är
 * ett VALIDERINGSFEL (422 `validation.failed` med `validation.exists` på
 * `depends_on`), inte en 404 och inte ett tyst "hittade inget" — samma
 * gränsdragning som issue 13a § Beslut 7 och 14 § Beslut 7.
 *
 * Dubbletten (samma par en gång till) är ett VALIDERINGSFEL (§ Beslut 9), inte
 * en tyst no-op och inte en 201 som låtsas ha skapat något. Den avvisas i
 * `withValidator()` genom att slå upp motpartens id och lägga felet på
 * `depends_on` med regelnamnet `Unique`, så höljet blir identiskt med
 * `Rule::unique` — `validation.unique` på fältet klienten skickade. Uppslaget
 * kostar ett konstant antal frågor och påverkar inte Beslut 6:s kriterium. Det
 * unika indexet i migrationen ligger kvar som sista skyddsnät.
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'depends_on' => [
                'required',
                'string',
                Rule::exists('schedule', 'ulid')->where(function ($query) {
                    $query
                        ->whereNull('deleted_at')
                        ->whereIn('item_id', function ($subQuery) {
                            $subQuery
                                ->select('id')
                                ->from('item')
                                ->where('container_id', $this->route('container')->id)
                                ->whereNull('deleted_at');
                        });
                }),
            ],
        ];
    }

    /**
     * Dubblettkontrollen (§ Beslut 9) behöver paret (det här schemat, den
     * tilltänkta motpartens id), så den kan inte uttryckas som en fristående
     * fältregel över ULID:en — den läggs i `after()` och slår upp id:t där.
     *
     * Hoppar över sig själv om `depends_on` redan fällts av `Rule::exists`
     * (fältet är ogiltigt, ingen mening att lägga ett andra fel ovanpå) eller
     * om värdet inte är en uppslagbar ULID.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $schedule = $this->route('schedule');
            $dependsOn = $this->input('depends_on');

            if (! is_string($dependsOn) || $dependsOn === '' || $validator->errors()->has('depends_on')) {
                return;
            }

            $other = Schedule::query()->where('ulid', $dependsOn)->first();

            if ($other === null || $other->is($schedule)) {
                return;
            }

            $alreadyDependsOn = ScheduleDependency::query()
                ->where('schedule_id', $schedule->id)
                ->where('depends_on_schedule_id', $other->id)
                ->exists();

            if ($alreadyDependsOn) {
                $validator->addFailure('depends_on', 'Unique');
            }
        });
    }
}
