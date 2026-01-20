<?php

namespace App\Interfaces;

use App\Models\MediaServerIntegration;
use Illuminate\Support\Collection;

interface MediaServer
{
    public static function make(MediaServerIntegration $integration): self;

    public function testConnection(): array;

    public function fetchMovies(): Collection;

    public function fetchSeries(): Collection;

    public function fetchSeasons(string $seriesId): Collection;

    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection;

    public function getStreamUrl(string $itemId, string $container = 'ts'): string;

    public function getDirectStreamUrl(string $itemId, string $container = 'ts'): string;

    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string;

    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string;

    public function extractGenres(array $item): array;

    public function getContainerExtension(array $item): string;

    public function ticksToSeconds(?int $ticks): ?int;
}
