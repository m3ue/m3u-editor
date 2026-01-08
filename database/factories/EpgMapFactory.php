<?php

namespace Database\Factories;

use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EpgMapFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EpgMap::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'uuid' => fake()->uuid(),
            'errors' => fake()->text(),
            'status' => fake()->randomElement(['pending', 'processing', 'completed', 'failed']),
            'processing' => fake()->boolean(),
            'progress' => fake()->randomFloat(0, 0, 9999999999.),
            'sync_time' => fake()->dateTime(),
            'user_id' => User::factory(),
            'epg_id' => Epg::factory(),
        ];
    }
}
