<?php

namespace Database\Factories;

use App\Models\PushDeviceToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushDeviceToken>
 */
class PushDeviceTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => $this->faker->uuid(),
            'platform' => $this->faker->randomElement(['ios', 'android']),
            'last_seen_at' => now(),
        ];
    }
}
