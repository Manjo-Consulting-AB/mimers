<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'stored_file_id' => StoredFile::factory(),
            'filename' => fake()->words(2, true).'.pdf',
            'kind' => 'document',
            'uploaded_by_user_id' => User::factory(),
            'billed_account_id' => Account::factory(),
        ];
    }
}
