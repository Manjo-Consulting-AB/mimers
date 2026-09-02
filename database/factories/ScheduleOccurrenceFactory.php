<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för schedule_occurrence — produktionen går ALLTID genom
 * App\Actions\Schedule\OpenNextOccurrence och aldrig genom den här fabriken
 * (issue 22 § Att se upp med). Ett test som skapar en andra ÖPPEN förekomst
 * med fabriken bevisar ingenting om invarianten "exakt en öppen förekomst per
 * aktivt schema" — den bevisas genom att anropa Actionen.
 *
 * Standardtillståndet är en öppen förekomst i framtiden; historikrader
 * (`status: completed`) byggs med state-metoden `completed()`.
 *
 * @extends Factory<ScheduleOccurrence>
 */
class ScheduleOccurrenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => Schedule::factory(),
            'due_at' => fake()->dateTimeBetween('+1 day', '+1 year')->format('Y-m-d'),
            // Standardtillståndet har lead_days = 0: synlig = förfall. Ett
            // test som vill ha ett glapp sätter båda kolumnerna explicit.
            'visible_from' => fn (array $attribut): string => $attribut['due_at'],
            'status' => 'open',
            'completed_at' => null,
            'completed_by_user_id' => null,
            'completed_by_account_id' => null,
            'completion_note' => null,
        ];
    }

    /**
     * En stängd förekomst — historikraden som listan bär som logg över
     * utförda jobb. Skrivs i produktion av avslutsflödet (issue 22b), i test
     * av den här fabriken.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => User::factory(),
            'completed_by_account_id' => Account::factory(),
        ]);
    }
}
