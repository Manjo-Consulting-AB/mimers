<?php

namespace Database\Factories;

use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för email_suppression. Skälet som standard är hard_bounce —
 * den vanligaste orsaken till att en adress hamnar i tabellen; tester som vill
 * ha ett annat av de två övriga skälen sätter det uttryckligen.
 *
 * @extends Factory<EmailSuppression>
 */
class EmailSuppressionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'reason' => EmailSuppression::REASON_HARD_BOUNCE,
        ];
    }
}
