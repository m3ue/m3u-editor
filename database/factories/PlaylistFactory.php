<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaylistFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Playlist::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'uuid' => $this->faker->uuid(),
            'url' => $this->faker->url(),
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'failed']),
            'prefix' => $this->faker->word(),
            'channels' => $this->faker->randomNumber(),
            'synced' => $this->faker->dateTime(),
            'errors' => $this->faker->text(),
            'id_channel_by' => 'stream_id',
            'user_id' => User::factory(),
        ];
    }
}
