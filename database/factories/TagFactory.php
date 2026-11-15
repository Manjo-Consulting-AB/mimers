<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
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
            'name' => fake()->unique()->word(),
            'color' => null,
            // Betalaren är containerns ägare, som för en rad skapad av ägaren
            // (ADR-0081 § 3).
            'billed_account_id' => fn (array $attributes) => Container::withTrashed()
                ->find($attributes['container_id'])->account_id,
        ];
    }
}
