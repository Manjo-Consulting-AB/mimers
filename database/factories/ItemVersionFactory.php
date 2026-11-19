<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemVersion>
 */
class ItemVersionFactory extends Factory
{
    /**
     * En version med itemets betalare som standard: containerns ägare
     * (ADR-0088 Riktning 3). `field_values` och `tag_ids` är tomma men aldrig
     * NULL — kolumnerna är JSON och en version bär alltid en mängd, om än
     * tom.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            // Betalaren är containerns ägare (ADR-0088 Riktning 3), inte
            // itemets författare.
            'billed_account_id' => fn (array $attributes) => Item::withTrashed()
                ->find($attributes['item_id'])->container->account_id,
            'name' => fake()->words(2, true),
            'description' => null,
            'notes' => null,
            'field_values' => [],
            'category_id' => null,
            'cover_attachment_id' => null,
            'icon' => null,
            'icon_color' => null,
            'tag_ids' => [],
            'replaced_by_user_id' => null,
            'replaced_by_agent_id' => null,
            'replaced_at' => now(),
        ];
    }
}
