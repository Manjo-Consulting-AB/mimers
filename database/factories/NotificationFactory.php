<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för notification — produktionen går ALLTID genom
 * App\Actions\Notification\CreateNotification och aldrig genom den här
 * fabriken (issue 30 § Beslut 7). Ett test som skapar en rad med fabriken
 * bevisar ingenting om idempotensen — den bevisas genom att anropa Actionen.
 *
 * Standardtillståndet är en kontonivå-notis utan mottagare och utan subject;
 * tester som vill ha en användare, container eller ett subject sätter dem
 * uttryckligen. `dedupe_key` är null — en fabriksrad ska aldrig råka
 * dedupliceras mot en annan.
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
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
            'type' => Notification::TYPE_TASK_DUE,
            'subject_type' => null,
            'subject_id' => null,
            'payload' => [],
            'dedupe_key' => null,
            'available_at' => now(),
        ];
    }
}
