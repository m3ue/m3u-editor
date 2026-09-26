<?php

namespace Database\Factories;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CachedContentFile>
 */
class CachedContentFileFactory extends Factory
{
    protected $model = CachedContentFile::class;

    /**
     * Defaults to a Pending movie row whose `cacheable` is a fresh VOD
     * channel on the same user + playlist.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'playlist_id' => fn (array $attributes) => Playlist::factory()->create(array_filter(['user_id' => $attributes['user_id']]))->id,
            'cacheable_type' => (new Channel)->getMorphClass(),
            'cacheable_id' => fn (array $attributes) => Channel::factory()->create(array_filter([
                'user_id' => $attributes['user_id'],
                'playlist_id' => $attributes['playlist_id'],
                'tmdb_id' => $attributes['tmdb_id'] ?? null,
            ]) + ['is_vod' => true])->id,
            'content_type' => 'movie',
            'tmdb_id' => (string) $this->faker->numberBetween(100, 99999),
            'tvdb_id' => null,
            'season_number' => null,
            'episode_number' => null,
            'quality' => null,
            'title' => $this->faker->sentence(3),
            'disk' => null,
            'file_path' => null,
            'file_size_bytes' => null,
            'status' => CachedContentFileStatus::Pending,
            'failure_count' => 0,
        ];
    }

    /**
     * Attach the row to an existing Channel or Episode, copying its owner,
     * playlist and identity.
     */
    public function forItem(Channel|Episode $item): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $item->user_id,
            'playlist_id' => $item->playlist_id,
            'cacheable_type' => $item->getMorphClass(),
            'cacheable_id' => $item->getKey(),
            'content_type' => $item instanceof Channel ? 'movie' : 'episode',
            'tmdb_id' => $item instanceof Episode ? ($item->series?->tmdb_id ?? $item->tmdb_id) : $item->tmdb_id,
            'tvdb_id' => $item instanceof Episode ? $item->series?->tvdb_id : $item->tvdb_id,
            'season_number' => $item instanceof Episode ? $item->season : null,
            'episode_number' => $item instanceof Episode ? $item->episode_num : null,
            'content_fingerprint' => $item->cacheFingerprint(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CachedContentFileStatus::Completed,
            'disk' => CachedContentFile::DISK,
            'file_path' => 'factory/'.($attributes['uuid'] ?? $this->faker->uuid()).'.mp4',
            'file_size_bytes' => $this->faker->numberBetween(1_000_000, 5_000_000_000),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CachedContentFileStatus::Failed,
            'failure_count' => $this->faker->numberBetween(1, 5),
            'last_failed_at' => now(),
        ]);
    }

    public function downloading(int $downloaded = 524_288_000, ?int $expected = 2_147_483_648): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CachedContentFileStatus::Downloading,
            'bytes_downloaded' => $downloaded,
            'bytes_expected' => $expected,
            'last_progress_at' => now(),
        ]);
    }

    public function forMovie(string $tmdbId, ?string $quality = null): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'movie',
            'tmdb_id' => $tmdbId,
            'quality' => $quality,
        ]);
    }

    /**
     * An episode row whose `cacheable` is a fresh Episode (and Series) on the
     * same user + playlist.
     */
    public function forEpisode(string $tmdbId, int $season, int $episode, ?string $quality = null): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'episode',
            'tmdb_id' => $tmdbId,
            'season_number' => $season,
            'episode_number' => $episode,
            'quality' => $quality,
            'cacheable_type' => (new Episode)->getMorphClass(),
            'cacheable_id' => function (array $attributes) use ($tmdbId, $season, $episode) {
                $owner = array_filter([
                    'user_id' => $attributes['user_id'],
                    'playlist_id' => $attributes['playlist_id'],
                ]);
                $series = Series::factory()->create($owner + ['tmdb_id' => $tmdbId]);

                return Episode::factory()->create($owner + [
                    'series_id' => $series->id,
                    'season' => $season,
                    'episode_num' => $episode,
                ])->id;
            },
        ]);
    }
}
