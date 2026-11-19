<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemField>
 */
class ItemFieldFactory extends Factory
{
    /**
     * Ett containerfält som standard — `item_id` NULL, alltså ett fält som
     * gäller alla items i containern ([[ADR-0089 Egna fält på items]]
     * § Riktning 1). Ett eget fält skapas med `->for($item, 'item')`.
     *
     * Betalaren är containerns ägare, som för en rad skapad av ägaren
     * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'item_id' => null,
            'label' => fake()->words(2, true),
            'type' => 'text',
            'position' => 1,
            'billed_account_id' => fn (array $attributes) => Container::withTrashed()
                ->find($attributes['container_id'])->account_id,
        ];
    }

    /**
     * Ett fält som bara gäller ett item ([[ADR-0089 Egna fält på items]]
     * § Riktning 2). Containern följer itemet, och betalaren är containerns
     * ägare — samma regel som för `definition()`.
     */
    public function forItem(Item $item): static
    {
        return $this->state(fn (): array => [
            'container_id' => $item->container_id,
            'item_id' => $item->id,
            'billed_account_id' => $item->container->account_id,
        ]);
    }
}
