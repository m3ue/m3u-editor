<?php

namespace Database\Factories;

use App\Models\ArrCacheMovie;
use App\Models\ArrIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrCacheMovie>
 */
class ArrCacheMovieFactory extends Factory
{
    protected $model = ArrCacheMovie::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'arr_integration_id' => ArrIntegration::factory()->radarr(),
            'tmdb_id' => fake()->unique()->numberBetween(1, 1000000),
            'arr_movie_id' => fake()->unique()->numberBetween(1, 100000),
            'left_at' => null,
        ];
    }
}
