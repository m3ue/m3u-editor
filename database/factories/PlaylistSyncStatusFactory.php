<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Playlist;
use App\Models\PlaylistSyncStatus;
use App\Models\User;

class PlaylistSyncStatusFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PlaylistSyncStatus::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'deleted_groups' => fake()->text(),
            'added_groups' => fake()->text(),
            'deleted_channels' => fake()->text(),
            'added_channels' => fake()->text(),
        ];
    }
}
