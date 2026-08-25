<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Container;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Container>
 */
class ContainerFactory extends Factory
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
            'name' => fake()->words(2, true),
            'kind' => fake()->randomElement(Container::KINDS),
        ];
    }
}
