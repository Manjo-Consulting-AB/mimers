<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
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
            'category_id' => null,
            'name' => fake()->words(2, true),
            'description' => null,
            'manufacturer' => null,
            'model' => null,
            'serial_number' => null,
            'purchased_at' => null,
            'warranty_until' => null,
            'position_note' => null,
            'created_by_user_id' => User::factory(),
            'created_by_account_id' => Account::factory(),
        ];
    }
}
