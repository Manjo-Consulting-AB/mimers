<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för audit_log — produktionen går ALLTID genom
 * App\Actions\Audit\RecordAuditEvent och aldrig genom den här fabriken
 * (issue 40 § Beslut 6). Ett test som skapar en rad med fabriken bevisar
 * ingenting om anropsställena — de bevisas genom att anropa de två
 * händelserutinerna (accepten och återkallningen).
 *
 * Standardtillståndet är en containerlös konto-åtgärd utan handlande
 * användare och utan subject; tester som vill ha en container, en användare
 * eller ett subject sätter dem uttryckligen.
 *
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
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
            'container_id' => null,
            'action' => AuditLog::ACTION_CONTAINER_TRANSFERRED,
            'subject_type' => null,
            'subject_id' => null,
            'meta' => [],
        ];
    }
}
