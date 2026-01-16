<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaylistProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PlaylistProfile::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'username' => $this->faker->userName(),
            'password' => $this->faker->password(8, 16),
            'max_streams' => $this->faker->numberBetween(1, 10),
            'priority' => $this->faker->numberBetween(0, 100),
            'enabled' => true,
            'is_primary' => false,
        ];
    }

    /**
     * Indicate that this profile is the primary profile.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
            'priority' => 0,
        ]);
    }

    /**
     * Indicate that this profile is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Set a specific priority for the profile.
     */
    public function withPriority(int $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }

    /**
     * Set max streams for the profile.
     */
    public function withMaxStreams(int $maxStreams): static
    {
        return $this->state(fn (array $attributes) => [
            'max_streams' => $maxStreams,
        ]);
    }

    /**
     * Configure for a specific playlist.
     */
    public function forPlaylist(Playlist $playlist): static
    {
        return $this->state(fn (array $attributes) => [
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
        ]);
    }

    /**
     * Set provider info with mock data.
     */
    public function withProviderInfo(int $activeConnections = 0, int $maxConnections = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_info' => [
                'user_info' => [
                    'active_cons' => $activeConnections,
                    'max_connections' => $maxConnections,
                    'status' => 'Active',
                ],
            ],
            'provider_info_updated_at' => now(),
        ]);
    }
}
