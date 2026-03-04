<?php

namespace Database\Factories;

use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MediaServerIntegration>
 */
class MediaServerIntegrationFactory extends Factory
{
    protected $model = MediaServerIntegration::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' Media Server',
            'type' => fake()->randomElement(['jellyfin', 'emby', 'plex']),
            'host' => fake()->domainName(),
            'port' => fake()->randomElement([8096, 8920, 32400]),
            'ssl' => fake()->boolean(30),
            'api_key' => fake()->uuid(),
            'enabled' => true,
        ];
    }
}
