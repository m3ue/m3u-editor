<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaSourceMatch>
 */
class MediaSourceMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'media_server_integration_id' => MediaServerIntegration::factory(),
            'channel_id' => Channel::factory(),
            'episode_id' => null,
            'media_channel_id' => null,
            'media_episode_id' => null,
            'match_key' => 'tmdb:'.$this->faker->randomNumber(),
        ];
    }

    /**
     * A series-episode match instead of a VOD movie match.
     */
    public function forEpisode(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_id' => null,
            'episode_id' => Episode::factory(),
        ]);
    }
}
