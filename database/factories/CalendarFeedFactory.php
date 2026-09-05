<?php

namespace Database\Factories;

use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarFeed>
 */
class CalendarFeedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `token_hash` är en SHA-256-hex av en färsk slump, precis som
     * App\Http\Controllers\Api\CalendarFeedController producerar den —
     * klartexten kastas här likaväl som i produktionsvägen, och hashen är
     * unik per rad (kolumnen har ett unikt index). Samma upplägg som
     * InvitationFactory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'revoked_at' => null,
            'last_fetched_at' => null,
        ];
    }
}
