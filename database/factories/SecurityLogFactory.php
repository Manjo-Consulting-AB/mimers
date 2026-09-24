<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SecurityLog;
use App\Support\Security\IpGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för security_log — produktionen går ALLTID genom
 * App\Actions\Security\RecordSecurityEvent och aldrig genom den här
 * fabriken. Ett test som skapar en rad med fabriken bevisar ingenting om
 * anropsställena; de bevisas genom att utföra handlingen.
 *
 * Standardtillståndet är en lyckad inloggning med konto, användare och
 * pseudonym, utan enhetsnamn: tester som vill ha en enhet sätter den
 * uttryckligen, och en rad utan enhet är det issue 117 renderar som *okänd
 * enhet*.
 *
 * @extends Factory<SecurityLog>
 */
class SecurityLogFactory extends Factory
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
            'user_id' => null,
            'action' => SecurityLog::ACTION_LOGIN,
            'ip_group' => IpGroup::from(fake()->ipv4()),
            'device_name' => null,
            'meta' => [],
        ];
    }
}
