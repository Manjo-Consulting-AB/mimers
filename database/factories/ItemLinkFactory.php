<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemLink>
 */
class ItemLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Båda ändarna skapas i SAMMA container — item_link har ingen egen
     * container, containern härleds ur itemen (issue 14 § Beslut 3), som
     * aldrig byter container. Tester som skapar en specifik länk skickar in
     * egna `from_item_id`/`to_item_id`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $container = Container::factory()->create();

        return [
            'from_item_id' => Item::factory()->for($container, 'container'),
            'to_item_id' => Item::factory()->for($container, 'container'),
            'relation' => 'parent',
        ];
    }
}
