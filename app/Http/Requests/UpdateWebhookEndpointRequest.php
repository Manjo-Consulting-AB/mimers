<?php

namespace App\Http\Requests;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/accounts/{account}/webhooks/{webhook} — ändrar `url`,
 * `event_types` och/eller `is_active`, alla valfria (issue 37a § Beslut 3).
 * `secret` och `consecutive_failures` går inte att sätta här: hemligheten
 * byts genom att skapa en ny endpoint, och räknaren ägs av 37b (undantaget:
 * en återaktivering nollställer den, i kontrollern).
 *
 * Samma två avgränsningar som StoreWebhookEndpointRequest: URL:ens SSRF-
 * säkerhet prövas av UrlSafetyValidator i kontrollern, och `event_types`
 * mot WebhookEndpoint::EVENT_TYPES.
 */
class UpdateWebhookEndpointRequest extends FormRequest
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
            'url' => ['sometimes', 'string', 'max:500'],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['required', 'string', Rule::in(WebhookEndpoint::EVENT_TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
