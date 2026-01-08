<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeasonFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Season::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'new' => fake()->boolean(),
            'source_season_id' => fake()->randomNumber(),
            'import_batch_no' => fake()->word(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'category_id' => Category::factory(),
            'series_id' => Series::factory(),
            'season_number' => fake()->randomNumber(),
            'episode_count' => fake()->randomNumber(),
            'cover' => fake()->word(),
            'cover_big' => fake()->word(),
        ];
    }
}
