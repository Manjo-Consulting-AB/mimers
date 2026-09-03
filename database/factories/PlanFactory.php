<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaulten är ett fristående testplan med free-gränserna — inte koderna
     * `free`/`pro` som migrationen skapar, de är unika och får inte krocka
     * med raderna som redan ligger i tabellen (issue 25 § Beslut 2).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('plan-????????'),
            'name' => fake()->words(2, true),
            'price_amount' => 0,
            'price_currency' => 'EUR',
            'billing_period' => 'year',
            'limits' => [
                'containers' => 1,
                'storage_bytes' => 1024 * 1024 * 1024,
                'max_file_bytes' => 10 * 1024 * 1024,
                'shared_users_per_container' => 1,
                'webhooks' => false,
                'pdf_binder' => false,
                'ownership_transfer' => false,
                'loan_reminders' => false,
                'cost_reports' => false,
            ],
            'is_public' => true,
        ];
    }
}
