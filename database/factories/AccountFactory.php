<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
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
