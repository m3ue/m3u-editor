<?php

namespace App\Services\WatchHistory;

use Carbon\CarbonInterface;

/**
 * A movie or episode as Trakt and SIMKL identify it: the movie's (or the
 * episode's show's) external ids, plus season and episode number for an
 * episode. Shared between what the server sends and what it reads back.
 */
final readonly class WatchHistoryItem
{
    /**
     * @param  'movie'|'episode'  $type
     * @param  array{tmdb?: int, imdb?: string, tvdb?: int}  $ids  The movie's ids, or the show's for an episode
     * @param  float|null  $progress  0 to 100
     */
    public function __construct(
        public string $type,
        public array $ids,
        public ?string $title = null,
        public ?int $year = null,
        public ?int $season = null,
        public ?int $episode = null,
        public ?float $progress = null,
        public ?CarbonInterface $watchedAt = null,
    ) {}

    public function withProgress(float $progress): self
    {
        return new self($this->type, $this->ids, $this->title, $this->year, $this->season, $this->episode, $progress, $this->watchedAt);
    }

    public function isEpisode(): bool
    {
        return $this->type === 'episode';
    }

    /**
     * Keep only the external ids both services match on, typed the way they
     * expect them. SIMKL returns tmdb/tvdb ids as strings.
     *
     * @param  array<string, mixed>  $ids
     * @return array{tmdb?: int, imdb?: string, tvdb?: int}
     */
    public static function normalizeIds(array $ids): array
    {
        $normalized = [];

        $tmdb = (int) ($ids['tmdb'] ?? 0);
        if ($tmdb > 0) {
            $normalized['tmdb'] = $tmdb;
        }

        $imdb = (string) ($ids['imdb'] ?? '');
        if (str_starts_with($imdb, 'tt')) {
            $normalized['imdb'] = $imdb;
        }

        $tvdb = (int) ($ids['tvdb'] ?? 0);
        if ($tvdb > 0) {
            $normalized['tvdb'] = $tvdb;
        }

        return $normalized;
    }

    /**
     * The `movie` or `show` + `episode` part of a scrobble body, which both
     * services take in the same shape.
     *
     * @return array<string, mixed>
     */
    public function toScrobblePayload(): array
    {
        $media = array_filter([
            'title' => $this->title,
            'year' => $this->year,
            'ids' => $this->ids,
        ], fn ($value): bool => $value !== null && $value !== []);

        if ($this->isEpisode()) {
            return [
                'show' => $media,
                'episode' => ['season' => $this->season, 'number' => $this->episode],
            ];
        }

        return ['movie' => $media];
    }

    /**
     * A key that's equal for the same title however it was identified, so
     * a local row and a remote entry can be compared.
     *
     * @return list<string>
     */
    public function matchKeys(): array
    {
        $suffix = $this->isEpisode() ? ":{$this->season}:{$this->episode}" : '';

        return collect($this->ids)
            ->map(fn (int|string $id, string $source): string => "{$this->type}:{$source}:{$id}{$suffix}")
            ->values()
            ->all();
    }
}
