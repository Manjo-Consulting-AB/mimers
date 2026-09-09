<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostEntry>
 */
class CostEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `container_id` härleds ur itemet (denormaliseras från det i
     * produktionen, issue 45a § Beslut 2) — closure:n läser itemets container
     * när definitionen expanderas, så en fristående create() ger en
     * sammanhängande rad.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'container_id' => fn (array $attributes) => Item::query()
                ->find($attributes['item_id'])->container_id,
            'incurred_on' => fake()->date(),
            'amount' => fake()->numberBetween(100, 100000),
            'currency' => 'EUR',
            'description' => fake()->sentence(3),
            'supplier' => null,
            'created_by_user_id' => User::factory(),
            'created_by_account_id' => Account::factory(),
        ];
    }
}
