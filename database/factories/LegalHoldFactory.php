<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\LegalHold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabriken sätter en GÄLLANDE spärr — raden utan `lifted_at` är den som
 * spärrar. En hävd spärr byggs i testet genom att häva den, med
 * App\Actions\LegalHold\LiftLegalHold eller kommandot, så att vägen dit
 * prövas i stället för att skrivas förbi.
 *
 * @extends Factory<LegalHold>
 */
class LegalHoldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'case_number' => fake()->numerify('AR-####-####'),
            'reason' => 'Anmälan enligt DSA artikel 16, under utredning.',
            'lifted_at' => null,
        ];
    }
}
