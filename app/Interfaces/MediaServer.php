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

    /**
     * Return the first available text-based subtitle stream for the item, or null if none
     * exists. Covers both embedded and external (sidecar file) subtitle streams — the media
     * server's own metadata is authoritative for external subtitles, which a raw ffprobe of
     * the video file itself can never see. Bitmap subtitle formats (PGS/VobSub) are skipped,
     * since ffmpeg's webvtt encoder only supports text-to-text conversion.
     *
     * When $seekSeconds > 0 the returned URL must be seeked server-side so the subtitle cues
     * are rebased to zero at that content-time — matching the video's own server-side seek so
     * both streams share one timeline origin. The returned 'server_seeked' flag tells the proxy
     * whether the subtitle input still needs a local -ss (false) or already arrives aligned
     * (true), so it never double-seeks.
     *
     * @return array{url: string, language: ?string, server_seeked: bool}|null
     */
    public function getSubtitleUrl(string $itemId, int $seekSeconds = 0): ?array;

    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string;

    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string;

    /**
     * Return the total byte size of the item's primary static stream alongside its runtime,
     * or null if unavailable. Used to compute an HTTP Range offset that lets ffmpeg seek
     * a server-side-seeked static stream without help from the media server.
     *
     * @return array{bytes: int, runtime_ticks: int|null, runtime_seconds: float|null}|null
     */
    public function getStreamByteSize(string $itemId): ?array;

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
