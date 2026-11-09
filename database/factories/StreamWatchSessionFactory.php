<?php

namespace Database\Factories;

use App\Models\StreamWatchSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StreamWatchSession>
 */
class StreamWatchSessionFactory extends Factory
{
    protected $model = StreamWatchSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 120));

        return [
            'user_id' => User::factory(),
            'content_type' => 'live',
            'title' => fake()->words(3, true),
            'proxy_stream_id' => Str::random(16),
            'proxy_client_id' => (string) Str::uuid(),
            'username' => fake()->userName(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'TiviMate/5.1.6 (Android 12)',
            'started_at' => $startedAt,
            'last_seen_at' => $startedAt,
        ];
    }

    public function ended(int $minutes = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => $attributes['started_at']->copy()->addMinutes($minutes),
            'duration_seconds' => $minutes * 60,
            'bytes_served' => $minutes * 60 * 500_000,
            'end_reason' => 'disconnected',
        ]);
    }
}
