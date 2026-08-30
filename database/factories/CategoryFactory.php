<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Container;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'parent_id' => null,
            'name' => fake()->words(2, true),
            'position' => 1,
        ];
    }
}
