<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 *
 * `currency` sätts uttryckligen till samma värde som kolumnens default
 * (issue 85 · [[ADR-0037 Valutans arv]]): ett test som prövar valutans arv
 * ska inte tyst byta betydelse den dag förvalet ändras, och den som vill ha
 * ett annat värde ser det i den här raden i stället för i migrationen.
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'personal',
            'name' => fake()->name(),
            'locale' => 'sv_SE',
            'timezone' => 'Europe/Stockholm',
            'unit_system' => 'metric',
            'currency' => 'SEK',
            'status' => 'active',
            'read_only_reason' => null,
        ];
    }

    /**
     * Ett organisationskonto — samma tabell, se [[ADR-0002 Konto äger container]].
     */
    public function organisation(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'organisation',
            'name' => fake()->company(),
        ]);
    }
}
