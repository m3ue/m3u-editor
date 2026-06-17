<?php

namespace Database\Factories;

use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrIntegration>
 */
class ArrIntegrationFactory extends Factory
{
    protected $model = ArrIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'name' => fake()->company().' Arr',
            'type' => fake()->randomElement(['sonarr', 'radarr']),
            'url' => 'http://'.fake()->ipv4().':8989',
            'api_key' => fake()->uuid(),
            'enabled' => true,
            'guest_enabled' => false,
        ];
    }

    public function sonarr(): static
    {
        return $this->state(fn (): array => [
            'type' => 'sonarr',
            'name' => 'Sonarr',
        ]);
    }

    public function radarr(): static
    {
        return $this->state(fn (): array => [
            'type' => 'radarr',
            'name' => 'Radarr',
        ]);
    }

    public function guestEnabled(): static
    {
        return $this->state(fn (): array => [
            'guest_enabled' => true,
        ]);
    }
}
