<?php

namespace App\Http\Requests\Help;

use App\Support\Api\ApiError;
use App\Support\Api\ValidationErrorMapper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Frågan och taket för `GET /help/search` — M81 · issue 466,
 * [[ADR-0094 Hjälpcentret]] § 5.
 *
 * `q` är obligatorisk och högst 100 tecken. En fråga som blir tom först efter
 * normaliseringen — bara skiljetecken — är däremot inget fel: sökningen
 * svarar `results: []` (se HelpSearch).
 *
 * `limit` är 1–20 med 8 som standard; standarden sätts i kontrollern, inte
 * här, så att ett utelämnat fält förblir utelämnat genom valideringen.
 *
 * **Felkoden är API:ets, även om rutten ligger i web.php.** Rutten svarar
 * JSON, inte Inertia, och klienten är sökrutan som behöver veta vilket fält
 * felet hör till (AGENTS.md § Felformat i API:et). Därför byggs `422`
 * `validation.failed` här i stället för att låta Laravels vanliga
 * formfelrendering ta över: webbens formfel hade gett en redirect eller
 * `{message, errors}`, och ingen av dem är det kontrakt sökytan bygger mot.
 */
class HelpSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ];
    }

    /**
     * Svaret på ett valideringsfel — API-höljet med en kod per fält.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);

        throw new HttpResponseException(ApiError::response('validation.failed', [
            'fields' => ValidationErrorMapper::fields($exception),
        ], 422));
    }
}
