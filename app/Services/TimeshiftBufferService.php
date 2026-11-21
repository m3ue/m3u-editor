<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\EpgProgramme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Shared per-channel rolling HLS timeshift buffer on m3u-proxy.
 *
 * Design (plain-live-first):
 *  - Cold: the viewer plays plain live immediately; a buffer is kicked off
 *    against the SAME pooled proxy stream (one upstream connection).
 *  - Warm: ?timeshift=1 redirects to the timeshift HLS window (seekable).
 *  - Readiness is a cheap playlist probe (segments present), never a long wait.
 *  - An active DVR recording on the channel wins (full-length window).
 *  - With EPG, the served playlist is trimmed to the current programme start
 *    (max window still applies). Without EPG: plain rolling window.
 */
class TimeshiftBufferService
{
    public const TOUCH_PREFIX = 'timeshift_buffer:touch:';

    public const STARTED_PREFIX = 'timeshift_buffer:started:';

    public const CHANNEL_PREFIX = 'timeshift_buffer:channel:';

    public const READY_PREFIX = 'timeshift_buffer:ready:';

    public function __construct(protected M3uProxyService $proxy) {}

    public static function enabled(): bool
    {
        return (bool) config('timeshift.enabled', true);
    }

    public static function bufferIdFor(Channel $channel): string
    {
        return 'ts-'.($channel->uuid ?? (string) $channel->id);
    }

    public static function windowSeconds(): int
    {
        $window = (int) config('timeshift.window_seconds', 10800);
        $max = (int) config('timeshift.max_window_seconds', 10800);
        $segment = max(1, (int) config('timeshift.segment_seconds', 2));

        return max($segment, min($window, $max > 0 ? $max : $window));
    }

    public static function hlsListSize(): int
    {
        $segment = max(1, (int) config('timeshift.segment_seconds', 2));

        return (int) ceil(self::windowSeconds() / $segment);
    }

    public static function touchKey(string $bufferId): string
    {
        return self::TOUCH_PREFIX.$bufferId;
    }

    public static function touchTtlSeconds(): int
    {
        return max(30, (int) config('timeshift.idle_stop_seconds', 120) + 30);
    }

    /**
     * Redirect target for a timeshift-enabled live request.
     *
     * Returns null to mean "play plain live for now" (cold buffer, feature off,
     * cap hit) — never blocks waiting for segments. Warm buffers return the
     * signed editor playlist URL immediately.
     */
    public function resolvePlaybackUrl(Channel $channel, string $playlistUuid): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        // Active recording: full-length window, already seekable.
        $dvr = DvrRecording::where('channel_id', $channel->id)
            ->where('status', DvrRecordingStatus::Recording)
            ->whereNotNull('proxy_network_id')
            ->latest('created_at')
            ->first();
        if ($dvr) {
            return url(URL::signedRoute('dvr.recording.hls.signed', ['uuid' => $dvr->uuid], absolute: false));
        }

        $bufferId = self::bufferIdFor($channel);

        // Keep the buffer alive while the viewer is still on plain live.
        if (Cache::has(self::touchKey($bufferId))) {
            $this->touch($bufferId);
        }

        if ($this->isReady($bufferId)) {
            $this->touch($bufferId);

            return $this->signedPlaylistUrl($bufferId);
        }

        // Cold or still filling → plain live; kickoff happens on the live path
        // once the pooled stream URL exists (same upstream).
        return null;
    }

    /**
     * Start (or no-op) the rolling buffer against an existing player-facing
     * stream URL. Called after the live path has created/reused the pooled
     * proxy stream so the buffer and the viewer share one upstream connection.
     */
    public function kickOff(Channel $channel, string $playlistUuid, string $playerStreamUrl): void
    {
        if (! self::enabled()) {
            return;
        }

        // DVR on this channel: viewers use the recording window instead.
        $dvr = DvrRecording::where('channel_id', $channel->id)
            ->where('status', DvrRecordingStatus::Recording)
            ->whereNotNull('proxy_network_id')
            ->exists();
        if ($dvr) {
            return;
        }

        $bufferId = self::bufferIdFor($channel);
        $lock = Cache::lock('timeshift.ensure.'.$bufferId, 10);
        try {
            if (! $lock->get()) {
                return;
            }

            if ($this->broadcastRunning($bufferId)) {
                $this->touch($bufferId);

                return;
            }
            // Stopped/failed leftover: delete it so startBroadcast can create
            // a fresh one (proxy start on an existing id can fail or resume
            // a dead FFmpeg).
            if ($this->broadcastExists($bufferId)) {
                $this->stopAndDelete($bufferId);
                Cache::forget(self::READY_PREFIX.$bufferId);
            }
            if (! $this->canAdmit()) {
                return;
            }

            $streamUrl = $this->internalStreamUrl($playerStreamUrl);
            if ($streamUrl === null) {
                return;
            }
            if (! $this->startBroadcast($channel, $bufferId, $playlistUuid, $streamUrl)) {
                return;
            }

            $this->touch($bufferId);
            Cache::put(self::STARTED_PREFIX.$bufferId, now()->timestamp, self::touchTtlSeconds());
            Cache::put(self::CHANNEL_PREFIX.$bufferId, (int) $channel->id, self::touchTtlSeconds());
            Cache::forget(self::READY_PREFIX.$bufferId);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Whether the buffer is actively producing segments (proxy status
     * "running"). A stopped/failed broadcast still has a playlist with old
     * segments and no EXT-X-ENDLIST — serving that to ExoPlayer makes it
     * wait forever for updates (play 1s → freeze → Source error).
     */
    public function isReady(string $bufferId): bool
    {
        if (! $this->broadcastRunning($bufferId)) {
            return false;
        }

        $cached = Cache::get(self::READY_PREFIX.$bufferId);
        if ($cached === true) {
            return true;
        }
        if ($cached === false) {
            return false;
        }

        $playlist = $this->fetchPlaylistOnce($bufferId);
        $ready = $playlist !== null && $this->countSegments($playlist) >= 1;
        Cache::put(self::READY_PREFIX.$bufferId, $ready, 1);

        return $ready;
    }

    public function touch(string $bufferId): void
    {
        Cache::put(self::touchKey($bufferId), now()->timestamp, self::touchTtlSeconds());
        // Keep side metadata alive alongside the touch.
        $ttl = self::touchTtlSeconds();
        if ($ts = Cache::get(self::STARTED_PREFIX.$bufferId)) {
            Cache::put(self::STARTED_PREFIX.$bufferId, $ts, $ttl);
        }
        if ($ch = Cache::get(self::CHANNEL_PREFIX.$bufferId)) {
            Cache::put(self::CHANNEL_PREFIX.$bufferId, $ch, $ttl);
        }
    }

    public function lastTouchTimestamp(string $bufferId): ?int
    {
        $value = Cache::get(self::touchKey($bufferId));

        return $value === null ? null : (int) $value;
    }

    public function bufferStartedAt(string $bufferId): ?int
    {
        $value = Cache::get(self::STARTED_PREFIX.$bufferId);

        return $value === null ? null : (int) $value;
    }

    public function bufferChannelId(string $bufferId): ?int
    {
        $value = Cache::get(self::CHANNEL_PREFIX.$bufferId);

        return $value === null ? null : (int) $value;
    }

    public function signedPlaylistUrl(string $bufferId): string
    {
        return url(URL::signedRoute('timeshift.live.playlist.signed', ['uuid' => $bufferId], absolute: false));
    }

    /**
     * Current programme start for a channel (EPG), or null when no guide data.
     */
    public function currentProgrammeStart(int $channelId): ?int
    {
        try {
            $now = now();
            $programme = EpgProgramme::query()
                ->join('epg_channels', fn ($join) => $join
                    ->on('epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id')
                    ->on('epg_channels.epg_id', '=', 'epg_programmes.epg_id'))
                ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
                ->where('channels.id', $channelId)
                ->where('epg_programmes.start_time', '<=', $now)
                ->where('epg_programmes.end_time', '>=', $now)
                ->orderBy('epg_programmes.start_time', 'desc')
                ->value('epg_programmes.start_time');

            return $programme ? (int) $programme : null;
        } catch (\Throwable $e) {
            Log::debug('Timeshift: EPG lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Stop + delete buffers whose last touch is older than idle_stop_seconds.
     *
     * @return list<string> buffer ids reaped
     */
    public function reapIdle(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $idle = max(30, (int) config('timeshift.idle_stop_seconds', 120));
        $reaped = [];
        $broadcasts = $this->listTimeshiftBroadcasts();
        if ($broadcasts === null) {
            return [];
        }

        foreach ($broadcasts as $broadcast) {
            $id = (string) ($broadcast['id'] ?? $broadcast['network_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $last = $this->lastTouchTimestamp($id);
            $age = $last === null ? PHP_INT_MAX : (now()->timestamp - $last);
            if ($age < $idle) {
                continue;
            }
            if ($this->stopAndDelete($id)) {
                Cache::forget(self::touchKey($id));
                Cache::forget(self::STARTED_PREFIX.$id);
                Cache::forget(self::CHANNEL_PREFIX.$id);
                Cache::forget(self::READY_PREFIX.$id);
                $reaped[] = $id;
            }
        }

        return $reaped;
    }

    /**
     * Map a player-facing proxy URL to the internal /stream/{id} URL the
     * broadcast FFmpeg should pull (same pooled upstream as the viewer).
     */
    protected function internalStreamUrl(string $playerStreamUrl): ?string
    {
        if (preg_match('#/stream/([^/?]+)#', $playerStreamUrl, $m)) {
            return $this->proxy->getStreamProxyUrl($m[1]);
        }
        if (preg_match('#/hls/([^/?]+)/#', $playerStreamUrl, $m)) {
            return $this->proxy->getStreamProxyUrl($m[1]);
        }

        // Not a proxy URL (shouldn't happen when timeshift forces the proxy
        // path) — refuse rather than open a second provider connection.
        Log::debug('Timeshift: kickOff skipped, not a proxy stream URL', [
            'url' => substr($playerStreamUrl, 0, 120),
        ]);

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    protected function listTimeshiftBroadcasts(): ?array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders(array_filter(['X-API-Token' => $this->proxy->getApiToken()]))
                ->get(rtrim($this->proxy->getApiBaseUrl(), '/').'/broadcast');
            if (! $response->successful()) {
                return null;
            }
            $rows = $this->unwrapBroadcastRows($response->json());
            if ($rows === null) {
                return [];
            }

            return array_values(array_filter($rows, function ($row) {
                $meta = $row['metadata'] ?? $row['meta'] ?? [];

                return is_array($meta) && ($meta['type'] ?? null) === 'timeshift';
            }));
        } catch (\Throwable $e) {
            Log::warning('Timeshift: could not list broadcasts: '.$e->getMessage());

            return null;
        }
    }

    protected function broadcastExists(string $bufferId): bool
    {
        return $this->broadcastStatus($bufferId) !== null;
    }

    /**
     * Proxy status for a timeshift broadcast: "running" | "stopped" |
     * "failed" | null (not found / proxy unreachable).
     */
    protected function broadcastStatus(string $bufferId): ?string
    {
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(array_filter(['X-API-Token' => $this->proxy->getApiToken()]))
                ->get(rtrim($this->proxy->getApiBaseUrl(), '/').'/broadcast');
            if (! $response->successful()) {
                // Unknown: treat as running so we don't double-start on flaky proxy.
                return 'running';
            }
            $rows = $this->unwrapBroadcastRows($response->json());
            if ($rows === null) {
                return null;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (string) ($row['id'] ?? $row['network_id'] ?? '');
                if ($id === $bufferId) {
                    return (string) ($row['status'] ?? 'running');
                }
            }

            return null;
        } catch (\Throwable) {
            return 'running';
        }
    }

    protected function broadcastRunning(string $bufferId): bool
    {
        return $this->broadcastStatus($bufferId) === 'running';
    }

    /**
     * Proxy GET /broadcast returns either a bare list, {"data":[...]}, or
     * {"broadcasts":[...],"count":N}. Normalize to a list of rows.
     *
     * @return list<mixed>|null
     */
    protected function unwrapBroadcastRows(mixed $json): ?array
    {
        if (is_array($json) && isset($json['broadcasts']) && is_array($json['broadcasts'])) {
            $json = $json['broadcasts'];
        } elseif (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            $json = $json['data'];
        }
        if (! is_array($json)) {
            return null;
        }
        // Associative single-object response → wrap as one row.
        if (! array_is_list($json)) {
            return [$json];
        }

        return array_values($json);
    }

    protected function canAdmit(): bool
    {
        $max = (int) config('timeshift.max_concurrent', 20);
        if ($max <= 0) {
            return true;
        }
        $broadcasts = $this->listTimeshiftBroadcasts();
        if ($broadcasts === null) {
            return true;
        }

        return count($broadcasts) < $max;
    }

    protected function startBroadcast(Channel $channel, string $bufferId, string $playlistUuid, string $streamUrl): bool
    {
        $segment = max(1, (int) config('timeshift.segment_seconds', 2));
        $payload = [
            'stream_url' => $streamUrl,
            'duration_seconds' => 0,
            'hls_list_size' => self::hlsListSize(),
            'segment_duration' => $segment,
            'transcode' => (bool) config('timeshift.transcode', false),
            'output_dir' => config('proxy.broadcast_temp_dir'),
            'metadata' => [
                'type' => 'timeshift',
                'channel_id' => (string) $channel->id,
                'playlist_uuid' => $playlistUuid,
            ],
        ];

        try {
            $endpoint = rtrim($this->proxy->getApiBaseUrl(), '/').'/broadcast/'.rawurlencode($bufferId).'/start';
            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders(array_filter(['X-API-Token' => $this->proxy->getApiToken()]))
                ->post($endpoint, $payload);
            if (! $response->successful()) {
                Log::warning('Timeshift: proxy start failed', [
                    'buffer_id' => $bufferId,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                return false;
            }

            Log::debug('Timeshift buffer started', [
                'buffer_id' => $bufferId,
                'channel_id' => $channel->id,
                'stream_url' => $streamUrl,
                'hls_list_size' => $payload['hls_list_size'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Timeshift: start threw: '.$e->getMessage());

            return false;
        }
    }

    protected function fetchPlaylistOnce(string $bufferId): ?string
    {
        try {
            $base = $this->proxy->getApiBaseUrl();
            if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
                $base = 'http://'.$base;
            }
            $url = rtrim($base, '/').'/broadcast/'.rawurlencode($bufferId).'/live.m3u8';

            $response = Http::timeout(2)
                ->accept('application/vnd.apple.mpegurl,text/plain,*/*')
                ->withHeaders(array_filter(['X-API-Token' => $this->proxy->getApiToken()]))
                ->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function countSegments(string $playlist): int
    {
        return preg_match_all('/^live\d+\.ts$/m', $playlist);
    }

    protected function stopAndDelete(string $bufferId): bool
    {
        try {
            $base = $this->proxy->getApiBaseUrl();
            if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
                $base = 'http://'.$base;
            }
            $base = rtrim($base, '/');
            $headers = array_filter(['X-API-Token' => $this->proxy->getApiToken()]);
            Http::timeout(10)->withHeaders($headers)->post($base.'/broadcast/'.rawurlencode($bufferId).'/stop');
            $response = Http::timeout(10)->withHeaders($headers)->delete($base.'/broadcast/'.rawurlencode($bufferId));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Timeshift: stop/delete failed: '.$e->getMessage());

            return false;
        }
    }
}
