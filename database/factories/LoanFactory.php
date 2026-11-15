<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'borrower_name' => fake()->name(),
            'borrower_email' => null,
            'lent_at' => fake()->date(),
            'due_at' => null,
            'returned_at' => null,
            'note' => null,
            // Betalaren är containerns ägare (ADR-0081 § 3).
            'billed_account_id' => fn (array $attributes) => Container::withTrashed()
                ->find(Item::withTrashed()->find($attributes['item_id'])->container_id)->account_id,
        ];
    }
}
