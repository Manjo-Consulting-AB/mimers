<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för webhook_delivery — produktionen går ALLTID genom
 * App\Actions\Notification\CreateNotification och aldrig genom den här
 * fabriken (issue 37b § Beslut 3).
 *
 * Standardtillståndet är den rad utfläkningen skapar: en väntande leverans
 * utan försök, mogen direkt.
 *
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'notification_id' => Notification::factory(),
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'response_status' => null,
            'last_error' => null,
            'next_attempt_at' => now(),
            'delivered_at' => null,
        ];
    }
}
