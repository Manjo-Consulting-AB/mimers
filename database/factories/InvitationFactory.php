<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `token_hash` är en SHA-256-hex av en färsk slump, precis som
     * App\Support\Auth\MagicLinkBroker producerar den — klartexten kastas
     * här likaväl som i produktionsvägen, och hashen är unik per rad
     * (kolumnen har ett unikt index).
     *
     * `status` läses ur Invitation::STATUSES så listan bara underhålls på
     * ett ställe, se modellens docblock.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
            'level' => 'read',
            'token_hash' => hash('sha256', Str::random(64)),
            'status' => Invitation::STATUSES[0],
            'expires_at' => now()->addDays(Invitation::TTL_DAYS),
            'invited_by_user_id' => User::factory(),
        ];
    }
}
