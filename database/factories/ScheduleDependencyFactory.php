<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleDependency>
 */
class ScheduleDependencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Båda ändarna skapas i SAMMA container — schedule_dependency har ingen
     * egen container, containern härleds ur schemats item (issue 23 § Beslut
     * 1), och ett beroende får korsa items men aldrig containers (Beslut 5).
     * Tester som skapar ett specifikt beroende skickar in egna
     * `schedule_id`/`depends_on_schedule_id`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $container = Container::factory()->create();

        return [
            'schedule_id' => Schedule::factory()->for(Item::factory()->for($container, 'container'), 'item'),
            'depends_on_schedule_id' => Schedule::factory()->for(Item::factory()->for($container, 'container'), 'item'),
        ];
    }
}
