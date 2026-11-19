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
            'created_by_user_id' => User::factory(),
            'created_by_account_id' => Account::factory(),
            // Betalaren är författaren (ADR-0081 § 3).
            'billed_account_id' => fn (array $attributes) => $attributes['created_by_account_id']
                // Författaren kan vara nollställd (ADR-0045 § Beslut 2): då är
                // det containerns ägare som betalar.
                ?? Container::withTrashed()->find($attributes['container_id'])->account_id,
        ];
    }
}
