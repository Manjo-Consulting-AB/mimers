<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContainerAccess>
 */
class ContainerAccessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'grantee_type' => 'user',
            'grantee_id' => User::factory(),
            'level' => 'read',
            'kind' => 'member',
            'expires_at' => null,
            'granted_by_user_id' => User::factory(),
            'revoked_at' => null,
        ];
    }
}
