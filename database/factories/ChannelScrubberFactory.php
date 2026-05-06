<?php

namespace Database\Factories;

use App\Models\ChannelScrubber;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelScrubber>
 */
class ChannelScrubberFactory extends Factory
{
    protected $model = ChannelScrubber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'name' => fake()->word(),
            'uuid' => (string) Str::uuid(),
            'status' => 'pending',
            'user_id' => $user,
            'playlist_id' => Playlist::factory()->for($user),
            'check_method' => 'http',
            'recurring' => false,
        ];
    }

    public function recurring(): self
    {
        return $this->state(fn () => ['recurring' => true]);
    }
}
