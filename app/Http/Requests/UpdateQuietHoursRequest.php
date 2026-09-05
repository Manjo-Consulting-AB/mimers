<?php

namespace App\Http\Requests;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/me/quiet-hours, se issue 31b § Beslut 5. Kroppen bär tre fält
 * och bara tre: `quiet_hours_start`, `quiet_hours_end` och `timezone`.
 *
 * Tidszonen hör hit fast den ser ut som en profilinställning — [[Notiser]] §
 * Tysta timmar och tidszon binder ihop dem: `22:00` betyder ingenting utan
 * att veta var. En allmän profilyta är issue 64:s (M10), inte den här
 * issuen: `locale` och `unit_system` ändras inte här.
 *
 * `quiet_hours_start`/`end` är `date_format:H:i` ELLER `null`, och båda
 * måste anges tillsammans — det ena satt och det andra `null` är inget
 * fönster (31a § Beslut 5). Fällan med `nullable` plus `required_with` är
 * att den senare inte triggar när det andra fältet är null, så paret
 * prövas i `withValidator()` i stället: `addFailure()` med regelnamnet
 * `RequiredWith` ger höljet `validation.required_with`, samma kod som om
 * regeln stått i `rules()` — samma mönster som
 * StoreOccurrenceDependencyRequest använder för sin `Unique`.
 *
 * `timezone` måste vara en giltig IANA-zon (`in:` mot
 * `DateTimeZone::listIdentifiers()`), inte en fri sträng, och är valfri —
 * en PATCH som bara vill rätta tidszonen ska inte tvingas skicka om
 * fönstret.
 */
class UpdateQuietHoursRequest extends FormRequest
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
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', Rule::in(DateTimeZone::listIdentifiers())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('quiet_hours_start');
            $end = $this->input('quiet_hours_end');

            $startSatt = $start !== null && $start !== '';
            $endSatt = $end !== null && $end !== '';

            if ($startSatt xor $endSatt) {
                $validator->addFailure(
                    $startSatt ? 'quiet_hours_end' : 'quiet_hours_start',
                    'RequiredWith',
                );
            }
        });
    }
}
