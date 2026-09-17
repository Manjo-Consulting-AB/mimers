<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Steg två i webbens magic link-inloggning — requesten som bär engångskoden
 * (eller återställningskoden) efter att mejllänken redan är förbrukad, se
 * issue 80 § Beslut 2 och App\Support\Auth\PendingMagicLinkLogin.
 *
 * **Ingen `token` och ingen `email` ur sessionen.** Vem försöket gäller
 * läses ur väntetillståndet i sessionen — aldrig ur requesten — så en
 * manipulerad kropp kan inte byta ut användaren. `email` finns i reglerna
 * ändå, av ett enda skäl: `throttle:login` (App\Support\Auth\LoginRateLimiter
 * och App\Providers\AppServiceProvider::configureLoginRateLimiting()) nycklar
 * sin e-postgräns på just det fältet. Utan det hade nyckeln blivit tom och
 * alla användares kodförsök delat en enda budget på fem i minuten — en
 * takgräns som slår mot fel person. Värdet används alltså till att räkna,
 * aldrig till att identifiera; sidan fyller i det ur en prop.
 *
 * Varför en egen FormRequest i stället för ConsumeMagicLinkRequest: de två
 * stegen tar olika kroppar (steg ett `email` + `token` ur mejllänken, steg
 * två `email` + `code`), och att göra `token` valfritt i den gemensamma
 * klassen hade tystat ett saknat token i steg ett i stället för att avvisa
 * det.
 */
class ConsumeMagicLinkCodeRequest extends FormRequest
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
            // Bara takgränsens nyckel, se klassens docblock — aldrig
            // identiteten. Formen valideras för att en skräpkropp ska
            // avvisas som ett fältfel och inte tyst ge en delad nyckel.
            'email' => ['required', 'string', 'email'],
            // Samma form som App\Http\Requests\Auth\LoginRequest::rules():
            // `code` är inte `required`, eftersom ett tomt värde är precis
            // vad TwoFactorChallenge översätter till `auth.totp_required`
            // — ett tomt fält ska ge samma svar här som där.
            'code' => ['sometimes', 'string'],
        ];
    }

    /**
     * Den inskickade koden, eller en tom sträng om ingen skickades.
     */
    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
