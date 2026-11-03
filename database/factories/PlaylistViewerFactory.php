<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistViewer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlaylistViewer>
 */
class PlaylistViewerFactory extends Factory
{
    protected $model = PlaylistViewer::class;

    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'name' => fake()->firstName(),
            'is_admin' => false,
            'viewerable_type' => (new Playlist)->getMorphClass(),
            'viewerable_id' => Playlist::factory(),
        ];
    }
}
