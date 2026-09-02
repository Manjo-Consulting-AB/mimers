<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
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
            'title' => fake()->words(2, true),
            'notes' => null,
            'recurrence_type' => 'interval',
            'interval_unit' => 'month',
            'interval_count' => 12,
            'anchor_date' => fake()->date(),
            'lead_days' => 0,
            'is_active' => true,
        ];
    }
}
