<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Container;
use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnershipTransfer>
 */
class OwnershipTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaulten sätter en mottagarväg (konto) så raden uppfyller
     * migrationens CHECK — en rad med varken `to_account_id` eller
     * `to_email` vore ogiltig. Testerna överrider per fall.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'container_id' => Container::factory(),
            'from_account_id' => Account::factory(),
            'to_account_id' => Account::factory(),
            'to_email' => null,
            'excluded_item_ids' => [],
            'retain_access_level' => null,
            'status' => OwnershipTransfer::STATUSES[0],
            'accepted_at' => null,
            'initiated_by_user_id' => User::factory(),
        ];
    }
}
