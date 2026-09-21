<?php

namespace App\Support\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Steg två i webbens magic link-inloggning — requesten som bär engångskoden
 * (eller återställningskoden) efter att mejllänken redan är förbrukad, se
 * issue 80 § Beslut 2 och App\Support\Auth\PendingMagicLinkLogin.
 *
 * **Ingen `token` och ingen `email`.** Vem försöket gäller läses ur
 * väntetillståndet i sessionen — aldrig ur requesten — så en manipulerad
 * kropp kan inte byta ut användaren. Klienten skickar därför bara `code`;
 * `email` sätts på requesten av
 * App\Support\Auth\BindsMagicLinkCodeThrottleToPendingLogin, av ett enda
 * skäl: `throttle:login` (App\Support\Auth\LoginRateLimiter och
 * App\Providers\AppServiceProvider::configureLoginRateLimiting()) nycklar
 * sin e-postgräns på det fältet. Middlewaredelen är hela poängen: kommer
 * fältet från kroppen är det klientstyrt, och den som har ett
 * väntetillstånd för offrets konto kan byta ut det mot en ny, orörd hink
 * för varje försök — se det middlewarets docblock.
 *
 * Klassen ligger här och inte bland de andra FormRequests: den hör till
 * magic link-flödet, och det är i den här katalogen flödets delar bor.
 *
 * Varför en egen FormRequest i stället för ConsumeMagicLinkRequest: de två
 * stegen tar olika kroppar (steg ett `email` + `token` ur mejllänken, steg
 * två `code`), och att göra `token` valfritt i den gemensamma klassen hade
 * tystat ett saknat token i steg ett i stället för att avvisa det.
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
