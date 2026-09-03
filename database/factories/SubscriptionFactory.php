<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `current_period_end` sätts en bit in i framtiden — kontrollerna i 27
     * ska aldrig se en utgången period för ett aktivt testkonto.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'current_period_end' => now()->addYear(),
            'grace_until' => null,
            'external_ref' => null,
        ];
    }

    /**
     * En prenumeration vars betalning uteblivit. Planen behålls, se
     * Account::currentPlan() och issue 25 § Beslut 6.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'past_due',
        ]);
    }

    /**
     * En uppsagd prenumeration. Kontot faller tillbaka på free, se
     * Account::currentPlan() och issue 25 § Beslut 6.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
