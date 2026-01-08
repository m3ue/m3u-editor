<?php

namespace Database\Factories;

use App\Models\StreamProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreamProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = StreamProfile::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'description' => fake()->text(),
            'args' => '{}',
            'sort' => fake()->randomNumber(),
        ];
    }
}
