<?php

namespace Database\Factories;

use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EpisodeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Episode::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'new' => fake()->boolean(),
            'source_episode_id' => fake()->randomNumber(),
            'import_batch_no' => fake()->word(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'series_id' => Series::factory(),
            'season_id' => Season::factory(),
            'episode_num' => fake()->randomNumber(),
            'container_extension' => fake()->word(),
            'custom_sid' => fake()->word(),
            'added' => fake()->dateTime(),
            'season' => fake()->randomNumber(),
        ];
    }
}
