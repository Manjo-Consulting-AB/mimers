<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Container;
use App\Models\Export;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Testverktyg för export — produktionen går ALLTID genom
 * App\Http\Controllers\Api\ExportController och aldrig genom den här
 * fabriken (issue 41 § Beslut 1).
 *
 * Standardtillståndet är den rad kontrollern skapar: en väntande beställning
 * utan artefakt.
 *
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'account_id' => null,
            'requested_by_user_id' => User::factory(),
            'kind' => Export::KIND_ZIP,
            'item_id' => null,
            'options' => null,
            'status' => Export::STATUS_PENDING,
            'storage_path' => null,
            'byte_size' => null,
            'failure_reason' => null,
            'expires_at' => null,
        ];
    }

    /**
     * En kontoexport (issue 1701): `kind = account`, ingen container.
     */
    public function account(?Account $account = null): static
    {
        return $this->state(fn (): array => [
            'kind' => Export::KIND_ACCOUNT,
            'container_id' => null,
            'account_id' => $account ?? Account::factory(),
        ]);
    }
}
