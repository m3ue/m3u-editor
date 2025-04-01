<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\PlaylistAuth;
use App\Models\User;

class PlaylistAuthFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PlaylistAuth::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'enabled' => fake()->boolean(),
            'user_id' => User::factory(),
            'username' => fake()->userName(),
            'password' => fake()->password(),
        ];
    }
}
