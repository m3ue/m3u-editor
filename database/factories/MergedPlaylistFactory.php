<?php

namespace Database\Factories;

use App\Models\MergedPlaylist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MergedPlaylistFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = MergedPlaylist::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'uuid' => $this->faker->uuid(),
            'user_id' => User::factory(),
        ];
    }
}
