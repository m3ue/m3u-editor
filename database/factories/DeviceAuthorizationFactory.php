<?php

namespace Database\Factories;

use App\Models\DeviceAuthorization;
use App\Services\DeviceCodeGeneratorService;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceAuthorizationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DeviceAuthorization::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'device_code' => DeviceCodeGeneratorService::generateDeviceCode(),
            'user_code' => DeviceCodeGeneratorService::generateUserCode(),
            'status' => 'pending',
            'playlist_auth_id' => null,
            'approved_by_user_id' => null,
            'approved_ip' => null,
            'requested_ip' => fake()->ipv4(),
            'poll_attempts' => 0,
            'last_polled_at' => null,
            'interval_seconds' => 5,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
