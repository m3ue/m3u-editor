<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\TvDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TvDevice>
 */
class TvDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => $this->faker->uuid(),
            'notifiable_type' => (new Playlist)->getMorphClass(),
            'notifiable_id' => Playlist::factory(),
            'device_name' => $this->faker->randomElement(["Shaun's iPhone", 'Living Room TV', 'Pixel 7', 'Studio Mac']),
            'platform' => $this->faker->randomElement(['android', 'androidtv', 'ios', 'tvos', 'macos', 'windows', 'linux']),
            'app_version' => '1.1.2',
            'last_ip' => $this->faker->ipv4(),
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function legacyVersion(): static
    {
        return $this->state(fn (): array => ['app_version' => '1.1.1']);
    }
}
