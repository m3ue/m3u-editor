<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        $name = $this->faker->word();
        $extension = $this->faker->randomElement(['png', 'jpg', 'jpeg', 'webp', 'gif']);

        return [
            'disk' => 'public',
            'path' => "assets/library/{$name}.{$extension}",
            'source' => 'upload',
            'name' => "{$name}.{$extension}",
            'extension' => $extension,
            'mime_type' => "image/{$extension}",
            'size_bytes' => $this->faker->numberBetween(1024, 1024 * 1024),
            'is_image' => true,
            'last_modified_at' => now(),
        ];
    }
}
