<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OccurrenceDependency>
 */
class OccurrenceDependencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Båda ändarna skapas i SAMMA container — occurrence_dependency har
     * ingen egen container, containern härleds ur förekomstens schemas item
     * (issue 23b § Beslut 1), och ett beroende får korsa items men aldrig
     * containers (Beslut 7). Tester som skapar ett specifikt beroende
     * skickar in egna `occurrence_id`/`depends_on_occurrence_id`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $container = Container::factory()->create();

        return [
            'occurrence_id' => ScheduleOccurrence::factory()->for(
                Schedule::factory()->for(Item::factory()->for($container, 'container'), 'item'),
                'schedule',
            ),
            'depends_on_occurrence_id' => ScheduleOccurrence::factory()->for(
                Schedule::factory()->for(Item::factory()->for($container, 'container'), 'item'),
                'schedule',
            ),
        ];
    }
}
