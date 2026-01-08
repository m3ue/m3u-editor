<?php

namespace Database\Factories;

use App\Models\PostProcess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PostProcessFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PostProcess::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'enabled' => fake()->boolean(),
            'user_id' => User::factory(),
            'event' => fake()->word(),
            'metadata' => '{}',
        ];
    }
}
