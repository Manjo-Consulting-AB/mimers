<?php

namespace Database\Factories;

use App\Models\PrivacyRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för privacy_request — produktionen går ALLTID genom
 * App\Actions\Privacy\OpenPrivacyRequest, som sätter referens, status,
 * prioritet, roll och frist. Ett test som skapar ett ärende med fabriken
 * bevisar ingenting om intaget; de bevisas genom att köra kommandot eller
 * actionen.
 *
 * Fabriken finns för proven som behöver ett ärende att läsa, koppla eller
 * radera, och där vägen dit inte är det som prövas. Standardtillståndet är
 * ett mottaget ärende utan konto- och personkoppling — kopplingen görs först
 * när identiteten prövats (ADR-0095 § 2), och ett test som vill ha den
 * sätter `account_id` och `user_id` uttryckligen.
 *
 * @extends Factory<PrivacyRequest>
 */
class PrivacyRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $received = now()->startOfDay();

        return [
            'reference' => PrivacyRequest::newReference(),
            'type' => 'access',
            'status' => PrivacyRequest::STATUS_RECEIVED,
            'priority' => PrivacyRequest::PRIORITY_NORMAL,
            'requester_name' => fake()->name(),
            'requester_email' => fake()->safeEmail(),
            'on_behalf_of' => null,
            'account_id' => null,
            'user_id' => null,
            'role' => PrivacyRequest::ROLE_UNDETERMINED,
            'assignee' => null,
            'description' => fake()->sentence(),
            'received_at' => $received,
            'deadline_at' => $received->copy()->addMonthNoOverflow(),
            'extended_months' => 0,
            'restriction_scope' => null,
            'restricted_at' => null,
            'restriction_lifted_at' => null,
            'outcome_reason' => null,
            'closed_at' => null,
        ];
    }
}
