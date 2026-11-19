<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemFieldValue>
 */
class ItemFieldValueFactory extends Factory
{
    /**
     * En textrad som standard: `value_text` satt och de två andra tomma.
     * Fältet hör till samma container som itemet, annars vore raden en
     * värdekombination applikationen aldrig kan skapa (issue 418 prövar det
     * med `field.not_applicable`).
     *
     * Betalaren är containerns ägare, som för de andra räknade tabellerna
     * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'item_field_id' => fn (array $attributes) => ItemField::factory()->create([
                'container_id' => Item::withTrashed()->find($attributes['item_id'])->container_id,
            ])->id,
            'value_text' => fake()->word(),
            'value_number' => null,
            'value_date' => null,
            'billed_account_id' => fn (array $attributes) => Item::withTrashed()
                ->find($attributes['item_id'])->container->account_id,
        ];
    }
}
