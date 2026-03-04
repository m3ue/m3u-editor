<?php

namespace Database\Factories;

use App\Models\Network;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Network>
 */
class NetworkFactory extends Factory
{
    protected $model = Network::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' TV',
            'uuid' => Str::uuid()->toString(),
            'description' => fake()->optional()->sentence(),
            'channel_number' => fake()->optional()->numberBetween(1, 999),
            'enabled' => true,
            'schedule_type' => 'sequential',
            'loop_content' => true,
            'user_id' => User::factory(),
            'broadcast_enabled' => false,
            'output_format' => 'hls',
            'segment_duration' => 6,
            'hls_list_size' => 10,
            'transcode_mode' => \App\Enums\TranscodeMode::Direct->value,
            'audio_bitrate' => 192,
            'video_codec' => null,
            'audio_codec' => null,
            'transcode_preset' => null,
            'hwaccel' => null,
        ];
    }

    /**
     * Configure the network with broadcast enabled.
     */
    public function broadcasting(): static
    {
        return $this->state(fn (array $attributes) => [
            'broadcast_enabled' => true,
        ]);
    }

    /**
     * Configure the network as currently actively broadcasting.
     */
    public function activeBroadcast(): static
    {
        return $this->state(fn (array $attributes) => [
            'broadcast_enabled' => true,
            'broadcast_started_at' => now(),
            'broadcast_pid' => fake()->numberBetween(1000, 65535),
        ]);
    }
}
