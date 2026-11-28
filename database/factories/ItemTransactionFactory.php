<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemTransaction>
 */
class ItemTransactionFactory extends Factory
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
            'counterparty' => null,
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
