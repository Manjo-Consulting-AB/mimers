<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\MagicLinkBroker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Delas mellan webbens och API:ets "begär en magic link"-rutter, se
 * issue 5. Samma mönster som App\Http\Requests\Auth\LoginRequest: en
 * FormRequest med både valideringsregler och den handling som utförs, så
 * att båda ytorna garanterat gör exakt samma sak.
 */
class RequestMagicLinkRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
        ];
    }

    /**
     * Utfärdar tokenet om adressen finns — se App\Support\Auth\MagicLinkBroker.
     * Ingen returvärde med flit: anropande kontroller ska svara identiskt
     * oavsett utfall, se issue #18 § Beslut som redan är fattade punkt 6.
     */
    public function issue(): void
    {
        MagicLinkBroker::issue($this->string('email')->toString());
    }
}
