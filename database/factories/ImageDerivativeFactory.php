<?php

namespace Database\Factories;

use App\Models\ImageDerivative;
use App\Models\StoredFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageDerivative>
 */
class ImageDerivativeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $variant = fake()->randomElement(['thumb', 'medium']);
        $suffix = $variant === 'thumb' ? '_thumb.jpg' : '_medium.jpg';

        // Sökvägen är originalets plus variantens namn (issue 18 § Beslut 4):
        // ab/cd/<hash>_thumb.jpg. Raden är bara en rad i databasen — bytena
        // som ligger på disken skapas av GenerateImageDerivatives, inte av
        // fabriken.
        $hash = strtolower(fake()->sha256);

        return [
            'stored_file_id' => StoredFile::factory(),
            'variant' => $variant,
            'storage_path' => substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash.$suffix,
            'byte_size' => fake()->numberBetween(1, 512 * 1024),
        ];
    }
}
