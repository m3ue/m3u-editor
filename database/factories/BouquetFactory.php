<?php

namespace Database\Factories;

use App\Models\Bouquet;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BouquetFactory extends Factory
{
    protected $model = Bouquet::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'group_selections' => null,
            'auto_include_new_live' => false,
            'auto_include_new_vod' => false,
        ];
    }
}
