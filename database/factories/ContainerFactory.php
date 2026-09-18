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
     * `kind` är fritt sedan issue 84 · [[ADR-0036 Containerns art]]: fabriken
     * drar ett ord ur tomma luften, precis som en användare kan skriva vad som
     * helst. Ingen lista att välja ur — en lista i fabriken hade varit samma
     * domän i koden som `Container::KINDS` var, bara i testskalet. Den som
     * prövar en bestämd art sätter den uttryckligen.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(2, true),
            'kind' => fake()->word(),
        ];
    }
}
