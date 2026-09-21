<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Container;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Container>
 */
class ContainerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `kind` är fritt sedan issue 84 · [[ADR-0036 Containerns art]]: fabriken
     * drar ett ord ur tomma luften, precis som en användare kan skriva vad som
     * helst. Ingen lista att välja ur — en lista i fabriken hade varit samma
     * domän i koden som `Container::KINDS` var, bara i testskalet. Den som
     * prövar en bestämd art sätter den uttryckligen.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(2, true),
            'kind' => fake()->word(),
            // Ingen beskrivning: fältet är frivilligt (issue 88 ·
            // [[ADR-0039 Containerns översikt]]), och `null` är vad en
            // container skapad utan en bär — en påhittad mening hade gjort
            // varje läsning av kolumnen i ett test till en läsning av
            // fabrikens ord. Den som vill pröva en beskrivning sätter den
            // uttryckligen.
            'description' => null,
            // Ingen egen valuta: containern följer kontot, och `null` är vad
            // en container skapad utan egen valuta bär (issue 85 ·
            // [[ADR-0037 Valutans arv]]). Den som vill pröva en egen valuta
            // sätter den uttryckligen.
            'currency' => null,
        ];
    }
}
