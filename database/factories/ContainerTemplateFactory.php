<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ContainerTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContainerTemplate>
 *
 * Mallen ärver betalaren från ägarkontot — samma regel som för en rad som
 * ägaren själv skriver ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3).
 * Den som vill pröva ett annat innehåll skickar `content` uttryckligen.
 */
class ContainerTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(2, true),
            'content' => [
                'categories' => [],
                'fields' => [],
            ],
            'billed_account_id' => fn (array $attributes) => $attributes['account_id'],
        ];
    }

    /**
     * En mall som ägs av och belastar det angivna kontot.
     */
    public function forAccount(Account $account): static
    {
        return $this->state(fn (): array => [
            'account_id' => $account->id,
            'billed_account_id' => $account->id,
        ]);
    }
}
