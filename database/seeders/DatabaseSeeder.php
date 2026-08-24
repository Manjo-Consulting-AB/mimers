<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $account = Account::factory()->create(['name' => 'Testkonto']);

        $user = User::factory()->create(['email' => 'test@example.com']);

        $account->users()->attach($user, ['role' => 'owner']);
    }
}
