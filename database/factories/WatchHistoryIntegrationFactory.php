<?php

namespace Database\Factories;

use App\Enums\WatchHistoryProvider;
use App\Models\User;
use App\Models\WatchHistoryIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchHistoryIntegration>
 */
class WatchHistoryIntegrationFactory extends Factory
{
    protected $model = WatchHistoryIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'provider' => WatchHistoryProvider::Trakt,
            'enabled' => true,
            'push_progress' => true,
            'account_name' => fake()->userName(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_expires_at' => now()->addDays(7),
        ];
    }

    public function simkl(): static
    {
        return $this->state(['provider' => WatchHistoryProvider::Simkl]);
    }

    public function disconnected(): static
    {
        return $this->state([
            'account_name' => null,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }
}
