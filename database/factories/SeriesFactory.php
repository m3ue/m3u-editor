<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeriesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Series::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'new' => fake()->boolean(),
            'source_category_id' => fake()->randomNumber(),
            'import_batch_no' => fake()->word(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'category_id' => Category::factory(),
            'cover' => fake()->word(),
            'plot' => fake()->word(),
            'genre' => fake()->word(),
            'release_date' => fake()->date(),
            'cast' => fake()->word(),
            'director' => fake()->word(),
            'rating' => fake()->word(),
            'rating_5based' => fake()->randomNumber(),
            'backdrop_path' => '{}',
            'youtube_trailer' => fake()->word(),
            'enabled' => fake()->boolean(),
            'metadata' => '{}',
        ];
    }
}
