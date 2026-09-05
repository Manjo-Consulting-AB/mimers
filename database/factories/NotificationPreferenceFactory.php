<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för notification_preference — raderna skapas aldrig av
 * produktionen (en saknad rad betyder förval, issue 31a § Beslut 2), så
 * fabriken finns för testerna som vill ha en AVVIKELSE.
 *
 * Standardtillståndet är en påslagen, icke-digest e-postrad för
 * `task.due` — tester som vill stänga av eller digest-markera sätter det
 * uttryckligen.
 *
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => Notification::TYPE_TASK_DUE,
            'channel' => NotificationDelivery::CHANNEL_EMAIL,
            'enabled' => true,
            'digest' => false,
        ];
    }
}
