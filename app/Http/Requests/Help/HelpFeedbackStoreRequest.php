<?php

namespace App\Http\Requests\Help;

use App\Support\Api\ApiError;
use App\Support\Api\ValidationErrorMapper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Ja eller Nej på en artikel — `POST /help/articles/{article}/feedback`,
 * M82 · issue 471, [[ADR-0094 Hjälpcentret]] § 7.
 *
 * Bara `helpful` finns i kroppen. Orsaken och kommentaren sparas i ett eget
 * anrop mot radens ULID, så att ytan kan spara dem direkt när de väljs och
 * inte som en del av Ja/Nej.
 *
 * **Felkoden är API:ets, även om rutten ligger i web.php.** Rutten svarar
 * JSON, inte Inertia, och klienten behöver veta vilket fält felet hör till
 * (AGENTS.md § Felformat i API:et). Samma form som HelpSearchRequest: `422`
 * `validation.failed` med en kod per fält, aldrig Laravels vanliga
 * formfelrendering.
 */
class HelpFeedbackStoreRequest extends FormRequest
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
            'helpful' => ['required', 'boolean'],
        ];
    }

    /**
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
