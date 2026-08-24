<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Auth\MagicLinkBroker;
use App\Support\Auth\MagicLinkExpiredException;
use App\Support\Auth\MagicLinkInvalidException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Delas mellan webbens och API:ets "lös in en magic link"-rutter, se
 * issue 5. `email` och `token` läses av `$this->validated()` oavsett om de
 * kom som query-parametrar (webbens GET-länk) eller JSON-kropp (API:ets
 * POST) — FormRequest-validering skiljer inte på källan.
 *
 * `consume()` kastar App\Support\Auth\MagicLinkInvalidException respektive
 * MagicLinkExpiredException oförändrat vidare — varje yta översätter dem
 * till sitt eget svar (se App\Http\Controllers\Auth\MagicLinkLoginController
 * och API-motsvarigheten), samma delnings-/översättningsmönster som
 * App\Http\Requests\Auth\LoginRequest::authenticate() och
 * App\Http\Controllers\Api\Auth\AuthenticatedTokenController.
 */
class ConsumeMagicLinkRequest extends FormRequest
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
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @throws MagicLinkInvalidException
     * @throws MagicLinkExpiredException
     */
    public function consume(): User
    {
        return MagicLinkBroker::consume(
            $this->string('email')->toString(),
            $this->string('token')->toString(),
        );
    }
}
