<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för notification_delivery — produktionen går ALLTID genom
 * App\Actions\Notification\CreateNotification och aldrig genom den här
 * fabriken (issue 30 § Beslut 7).
 *
 * Standardtillståndet är den rad CreateNotification skapar: en väntande
 * e-postleverans utan försök.
 *
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'status' => NotificationDelivery::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'sent_at' => null,
        ];
    }
}
