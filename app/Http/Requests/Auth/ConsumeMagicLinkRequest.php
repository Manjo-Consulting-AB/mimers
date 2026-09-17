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
 * `consume()` och `resolve()` kastar App\Support\Auth\MagicLinkInvalidException
 * respektive MagicLinkExpiredException oförändrat vidare — varje yta
 * översätter dem till sitt eget svar (se App\Http\Controllers\Auth\MagicLinkLoginController
 * och API-motsvarigheten), samma delnings-/översättningsmönster som
 * App\Http\Requests\Auth\LoginRequest::authenticate() och
 * App\Http\Controllers\Api\Auth\AuthenticatedTokenController.
 *
 * Issue 80 · "En magic link går förbi bekräftad tvåfaktor" lade till det
 * valfria fältet `code`, som LoginRequest redan hade det: på `/api` skickas
 * engångskoden med i samma request, eftersom en API-klient kan det. Webben
 * kan inte — en webbläsare kan inte skicka koden i samma request som klicket
 * på mejllänken — och frågar i stället i ett andra steg, se
 * App\Support\Auth\PendingMagicLinkLogin och
 * App\Support\Auth\ConsumeMagicLinkCodeRequest.
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
            // Issue 80: `code` är bara obligatoriskt för konton med bekräftad
            // tvåfaktor, vilket inte går att uttrycka statiskt här (kräver ett
            // uppslag på kontot) — samma form och samma skäl som
            // App\Http\Requests\Auth\LoginRequest::rules().
            'code' => ['sometimes', 'string'],
        ];
    }

    /**
     * @throws MagicLinkInvalidException
     * @throws MagicLinkExpiredException
     */
    public function consume(): User
    {
        return MagicLinkBroker::consume($this->email(), $this->token());
    }

    /**
     * Prövar token utan att förbruka det — se
     * App\Support\Auth\MagicLinkBroker::resolve() och issue 80 § Beslut 3.
     *
     * @throws MagicLinkInvalidException
     * @throws MagicLinkExpiredException
     */
    public function resolve(): User
    {
        return MagicLinkBroker::resolve($this->email(), $this->token());
    }

    /**
     * Den inskickade engångskoden, eller en tom sträng om ingen skickades —
     * App\Support\Auth\TwoFactorChallenge skiljer de två åt.
     */
    public function code(): string
    {
        return $this->string('code')->toString();
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }
}
