<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Notification;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `secret` är en färsk Str::random(64) precis som
     * App\Http\Controllers\Api\WebhookEndpointController producerar den — men
     * till skillnad från CalendarFeedFactory slängs klartexten INTE här: den
     * krypteras av modellens `encrypted`-cast när raden sparas (issue 37a §
     * Beslut 1 och 2), så testet kan läsa tillbaka den genom modellen.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'url' => 'https://example.com/notiser',
            'secret' => Str::random(64),
            'event_types' => [Notification::TYPE_TASK_DUE],
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }
}
