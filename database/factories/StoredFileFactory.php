<?php

namespace Database\Factories;

use App\Models\StoredFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoredFile>
 */
class StoredFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hash = strtolower(fake()->sha256);

        return [
            'content_hash' => $hash,
            'byte_size' => fake()->numberBetween(1, 1024 * 1024),
            'mime_type' => 'application/pdf',
            // Sökvägen byggs ur hashen (issue 16a § Beslut 6) — de två första
            // och de två därpå följande hex-tecknen som katalognivåer.
            'storage_path' => substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash,
            'reference_count' => 1,
            'scan_status' => 'skipped',
        ];
    }
}
