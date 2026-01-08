<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Category::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'name_internal' => fake()->word(),
            'source_category_id' => fake()->randomNumber(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
        ];
    }
}
