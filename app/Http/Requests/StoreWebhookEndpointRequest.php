<?php

namespace App\Http\Requests;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/accounts/{account}/webhooks — kroppen är `{"url",
 * "event_types"}`. `is_active` tas inte emot: en ny endpoint är aktiv, det är
 * databasens default (issue 37a § Beslut 1).
 *
 * URL:ens SÄKERHET (SSRF) valideras inte här — den prövas av
 * App\Support\Notification\UrlSafetyValidator i kontrollern, efter att
 * behörighet och plangrind passerat. Det är ett medvetet val: ett
 * `webhook.unsafe_url`-fel (422) ska berätta VARFÖR URL:en är oanvändbar,
 * medan ett valideringsfel här skulle svara validation.failed utan att skilja
 * http från en privat IP (issue 37a § Beslut 6).
 *
 * `event_types` prövas mot WebhookEndpoint::EVENT_TYPES — de kända
 * notistyperna, se modellens docblock och Frågor och antaganden i PR:en
 * (Notification::TYPES finns inte).
 *
 * Inga domänbeslut här: authorize() är alltid sant, behörigheten avgörs av
 * App\Policies\AccountPolicy::manageWebhooks() och plangrinden av
 * App\Support\Plan\Entitlements — båda i kontrollern, i den ordningen.
 */
class StoreWebhookEndpointRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:500'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['required', 'string', Rule::in(WebhookEndpoint::EVENT_TYPES)],
        ];
    }
}
