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
     * Return a text-based subtitle stream for the item, or null if none exists. Covers both
     * embedded and external (sidecar file) subtitle streams — the media server's own metadata
     * is authoritative for external subtitles, which a raw ffprobe of the video file itself can
     * never see. Bitmap subtitle formats (PGS/VobSub) are skipped, since ffmpeg's webvtt encoder
     * only supports text-to-text conversion.
     *
     * When $preferredLanguage is given, prefers a stream matching that language (exact match on
     * the stream's own language tag) and only falls back to the first available text stream when
     * nothing matches — without this, subtitle "selection" was purely accidental (whichever text
     * stream happened to be listed first), regardless of what the operator actually configured.
     *
     * When $seekSeconds > 0 the returned URL must be seeked server-side so the subtitle cues
     * are rebased to zero at that content-time — matching the video's own server-side seek so
     * both streams share one timeline origin. The returned 'server_seeked' flag tells the proxy
     * whether the subtitle input still needs a local -ss (false) or already arrives aligned
     * (true), so it never double-seeks.
     *
     * @return array{url: string, language: ?string, server_seeked: bool}|null
     */
    public function getSubtitleUrl(string $itemId, int $seekSeconds = 0, ?string $preferredLanguage = null): ?array;

    /**
     * List this item's real audio/subtitle streams, for building a per-item track
     * preference picker (NetworkContentRelationManager's "Track Preferences" action).
     * Unlike the Network-level default (a single ISO code applied across arbitrarily
     * many titles), this is scoped to one known item, so it can offer the operator its
     * actual tracks instead of a generic language list. Returns empty arrays when the
     * integration has no stream-listing API (Local/WebDAV).
     *
     * 'index' is a composite "{type_relative_position}:{native_id}" string, e.g.
     * "1:395784" — the type-relative position (0-indexed among streams of that same
     * type, e.g. "the 2nd audio stream") is what NetworkBroadcastService forwards to
     * the proxy for FFmpeg's `-map 0:a:{N}?` in Direct/Local mode; native_id (the
     * media server's own stream identifier — Plex's database-wide stream ID, Emby's
     * absolute container index) is what's forwarded to the media server's own
     * PreferredAudioTrack/PreferredSubtitleTrack resolution in Server mode. Neither
     * value alone works for both: FFmpeg has no notion of Plex's opaque ID, and the
     * media server's own resolver doesn't need (and Plex's doesn't have) a
     * type-relative position.
     *
     * @return array{
     *     audio: list<array{index: string, label: string, language: ?string}>,
     *     subtitle: list<array{index: string, label: string, language: ?string}>,
     * }
     */
    public function getAvailableTracks(string $itemId): array;

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
