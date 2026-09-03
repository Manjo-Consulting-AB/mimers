<?php

namespace App\Http\Requests\Schedule;

use App\Models\OccurrenceDependency;
use App\Models\ScheduleOccurrence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/containers/{container}/items/{item}/schedules/{schedule}
 * /occurrences/{occurrence}/dependencies, se issue 23b § Beslut 3 och 7.
 * Kroppen är `{depends_on: "<ULID>"}` — ULID:en för den förekomst rutten
 * gäller ska vänta på. En ULID, inte en lista, av samma skäl som 23a §
 * Beslut 4.
 *
 * `depends_on` förblir klientens ULID genom hela valideringen — fältets värde
 * skrivs aldrig om. Existensen och containern prövas med `Rule::exists` mot
 * `schedule_occurrence.ulid`, begränsad i EN underfråga till förekomster vars
 * schema ligger under containerns levande items: en ULID som inte finns, hör
 * till ett mjukraderat schema eller till en annan container är ett
 * VALIDERINGSFEL (422 `validation.failed` med `validation.exists` på
 * `depends_on`), inte en 404 och inte ett tyst "hittade inget" — samma
 * gränsdragning som 23a § Beslut 4.
 *
 * Dubbletten (samma par en gång till) är ett VALIDERINGSFEL av samma skäl som
 * 23a § Beslut 9, inte en tyst no-op och inte en 201 som låtsas ha skapat
 * något. Den avvisas i `withValidator()` med regelnamnet `Unique`, så höljet
 * blir identiskt med `Rule::unique` — `validation.unique` på fältet klienten
 * skickade. Uppslaget kostar ett konstant antal frågor. Det unika indexet i
 * migrationen ligger kvar som sista skyddsnät.
 *
 * Att `depends_on` inte är förekomsten själv (dependency_self), att ingen
 * cykel uppstår (dependency_cycle) och att den väntande sidan är öppen
 * (not_open) prövas i App\Actions\Schedule\DependOccurrence, inte här — de är
 * domänregler, inte fältfel (§ Beslut 7).
 */
class StoreOccurrenceDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 23b § Beslut 3.
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
                Rule::exists('schedule_occurrence', 'ulid')->where(function ($query) {
                    $query->whereIn('schedule_id', function ($subQuery) {
                        $subQuery
                            ->select('id')
                            ->from('schedule')
                            ->whereNull('deleted_at')
                            ->whereIn('item_id', function ($itemQuery) {
                                $itemQuery
                                    ->select('id')
                                    ->from('item')
                                    ->where('container_id', $this->route('container')->id)
                                    ->whereNull('deleted_at');
                            });
                    });
                }),
            ],
        ];
    }

    /**
     * Dubblettkontrollen behöver paret (den väntande förekomsten, den
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
            $occurrence = $this->route('occurrence');
            $dependsOn = $this->input('depends_on');

            if (! is_string($dependsOn) || $dependsOn === '' || $validator->errors()->has('depends_on')) {
                return;
            }

            $other = ScheduleOccurrence::query()->where('ulid', $dependsOn)->first();

            if ($other === null || $other->is($occurrence)) {
                return;
            }

            $alreadyDependsOn = OccurrenceDependency::query()
                ->where('occurrence_id', $occurrence->id)
                ->where('depends_on_occurrence_id', $other->id)
                ->exists();

            if ($alreadyDependsOn) {
                $validator->addFailure('depends_on', 'Unique');
            }
        });
    }
}
