<?php

namespace App\Interfaces;

use App\Models\MediaServerIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

interface MediaServer
{
    public static function make(MediaServerIntegration $integration): self;

    public function testConnection(): array;

    /**
     * Fetch available libraries from the media server.
     * Returns only movies and TV shows libraries.
     *
     * @return Collection<int, array{id: string, name: string, type: string, item_count: int}>
     */
    public function fetchLibraries(): Collection;

    public function fetchMovies(): Collection;

    public function fetchSeries(): Collection;

    public function fetchSeriesDetails(string $seriesId): ?array;

    public function fetchSeasons(string $seriesId): Collection;

    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection;

    public function getStreamUrl(string $itemId, string $container = 'ts'): string;

    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string;

    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string;

    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string;

    public function extractGenres(array $item): array;

    public function getContainerExtension(array $item): string;

    public function ticksToSeconds(?int $ticks): ?int;

    /**
     * Trigger a library refresh/scan on the media server.
     *
     * @return array{success: bool, message: string}
     */
    public function refreshLibrary(): array;
}
