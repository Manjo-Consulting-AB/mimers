<?php

namespace App\Http\Requests;

use App\Support\Api\ApiError;
use App\Support\Api\ValidationErrorMapper;
use App\Support\Contact\ContactCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inskickningen från kontaktformuläret — `POST /contact`, M89 ·
 * [[ADR-0096 Kontaktformuläret]] § 1–3.
 *
 * **Felkoden är API:ets, även om rutten ligger i web.php.** Rutten svarar
 * JSON, inte Inertia, och klienten behöver veta vilket fält felet hör till
 * (AGENTS.md § Felformat i API:et). Samma form som
 * App\Http\Requests\Help\HelpFeedbackStoreRequest: `422 validation.failed`
 * med en kod per fält, aldrig Laravels vanliga formfelrendering.
 *
 * **Namn och adress krävs bara av en gäst.** En inloggad person bär dem i
 * sessionen, och servern läser dem därifrån — reglerna för `name` och `email`
 * finns därför inte alls för henne. Att de uteblir ur `validated()` är hela
 * skyddet: ett `name` i kroppen kan aldrig råka bli det som skickas med.
 */
class ContactRequest extends FormRequest
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
        $rules = [
            'category' => ['required', 'string', Rule::in(ContactCategory::KEYS)],
            'message' => ['required', 'string', 'max:5000'],
        ];

        if ($this->user() === null) {
            $rules['name'] = ['required', 'string', 'max:100'];
            $rules['email'] = ['required', 'string', 'email'];
        }

        return $rules;
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
