<?php

namespace App\Http\Requests\Help;

use App\Models\HelpFeedback;
use App\Support\Api\ApiError;
use App\Support\Api\ValidationErrorMapper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Orsak och kommentar på ett Nej — `PATCH /help/feedback/{ulid}`,
 * M82 · issue 471, [[ADR-0094 Hjälpcentret]] § 7.
 *
 * Båda fälten är frivilliga och får komma var för sig: ytan sparar orsaken
 * när den väljs och kommentaren först med *Save*. `reason` måste vara en av
 * App\Models\HelpFeedback::REASONS; `comment` är högst 1000 tecken.
 *
 * **Kommentaren trimmas före valideringen**, så att taket räknas på det som
 * faktiskt sparas. En tom sträng blir `null` redan i Laravels
 * ConvertEmptyStringsToNull, och kontrollern sparar då null.
 *
 * Svaret på ett fältfel är API-höljet, av samma skäl som i
 * HelpFeedbackStoreRequest.
 */
class HelpFeedbackUpdateRequest extends FormRequest
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
            'reason' => ['nullable', Rule::in(HelpFeedback::REASONS)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $comment = $this->input('comment');

        if (is_string($comment)) {
            $this->merge(['comment' => trim($comment)]);
        }
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
