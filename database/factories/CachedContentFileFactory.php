<?php

namespace Database\Factories;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CachedContentFile>
 */
class CachedContentFileFactory extends Factory
{
    protected $model = CachedContentFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'content_type' => 'movie',
            'tmdb_id' => (string) $this->faker->numberBetween(100, 99999),
            'tvdb_id' => null,
            'season_number' => null,
            'episode_number' => null,
            'quality' => '1080p',
            'disk' => null,
            'file_path' => null,
            'file_size_bytes' => null,
            'status' => CachedContentFileStatus::Pending,
            'failure_count' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CachedContentFileStatus::Completed,
            'disk' => 'local',
            'file_path' => 'cache/'.CachedContentFile::fingerprintFor($attributes).'.mp4',
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

    public function forMovie(string $tmdbId, ?string $quality = '1080p'): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'movie',
            'tmdb_id' => $tmdbId,
            'quality' => $quality,
        ]);
    }

    public function forEpisode(string $tmdbId, int $season, int $episode, ?string $quality = '1080p'): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'episode',
            'tmdb_id' => $tmdbId,
            'season_number' => $season,
            'episode_number' => $episode,
            'quality' => $quality,
        ]);
    }
}
