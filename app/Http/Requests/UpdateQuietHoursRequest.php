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
 * fönster (31a § Beslut 5). Formatet är `H:i` in och `H:i` ut: kontrollern
 * normaliserar TIME-kolumnens `22:00:00` till `22:00`, så issue 65 skickar
 * `22:00` och får tillbaka exakt den strängen.
 *
 * Paret prövas i `withValidator()`, inte med `nullable` plus `required_with`:
 * den senare triggar inte när det andra fältet är null, och fällan är djupare
 * än så. `validated()` tar bara med nycklar som faktiskt står i kroppen, så
 * en PATCH med bara `{"quiet_hours_start": null}` (utan `quiet_hours_end`-
 * nyckeln alls) skulle skriva bara den kolumnen och lämna den andra orörd i
 * databasen. Regeln avgör därför paret på nyckelns NÄRVARO först — endera
 * nyckeln närvarande men inte den andra faller alltid, oavsett värde — och på
 * värdets nullhet först när båda nycklarna är med: det ena satt och det andra
 * null är inget fönster. `addFailure()` med regelnamnet `RequiredWith` ger
 * höljet `validation.required_with`, samma kod som om regeln stått i
 * `rules()` — samma mönster som StoreOccurrenceDependencyRequest använder
 * för sin `Unique`.
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
            $hasStart = $this->has('quiet_hours_start');
            $hasEnd = $this->has('quiet_hours_end');

            // Bara den ena nyckeln i kroppen — den andra är varken satt eller
            // uttryckligen null. validated() tar bara med nycklar som finns i
            // kroppen, så en sådan PATCH skulle skriva bara den kolumnen och
            // lämna den andra orörd i databasen (Beslut 5). Närvaron avgör,
            // inte värdets nullhet: `{"quiet_hours_start": null}` är inte
            // "ingendera satt", det är ett fönster med bara ena sidan.
            if ($hasStart xor $hasEnd) {
                $validator->addFailure(
                    $hasStart ? 'quiet_hours_end' : 'quiet_hours_start',
                    'RequiredWith',
                );

                return;
            }

            if (! $hasStart) {
                return;
            }

            // Båda nycklarna närvarande — nu räknar värdets nullhet: det ena
            // satt och det andra null är inget fönster.
            $startSatt = $this->input('quiet_hours_start') !== null
                && $this->input('quiet_hours_start') !== '';
            $endSatt = $this->input('quiet_hours_end') !== null
                && $this->input('quiet_hours_end') !== '';

            if ($startSatt xor $endSatt) {
                $validator->addFailure(
                    $startSatt ? 'quiet_hours_end' : 'quiet_hours_start',
                    'RequiredWith',
                );
            }
        });
    }
}
