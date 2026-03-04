<?php

namespace Database\Factories;

use App\Models\CustomPlaylist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomPlaylistFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CustomPlaylist::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'uuid' => $this->faker->uuid(),
            'id_channel_by' => 'stream_id',
            'user_id' => User::factory(),
        ];
    }
}
