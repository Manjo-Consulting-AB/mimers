<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\UsageCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageCounter>
 */
class UsageCounterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'storage_bytes' => 0,
            'container_count' => 0,
        ];
    }
}
