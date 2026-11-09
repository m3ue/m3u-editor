<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\Episode;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\StreamWatchSession;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps Proxy > Watch History: a StreamWatchSession per player connection
 * to a live channel, movie, or episode served through m3u-proxy.
 *
 * Built only from the proxy's webhooks, which open and close sessions as
 * clients connect and disconnect. Polling the proxy's client list was too
 * coarse (HLS clients come and go between segment requests), so the feature
 * requires working webhooks: Settings > Proxy only lets it be turned on
 * once M3uProxyService::checkWebhook() passes. closeAbandoned() ends the
 * occasional session whose disconnect never arrived (e.g. a proxy restart).
 *
 * Sessions are tied to the Playlist Auth the client signed in with: the
 * proxy records each client's Xtream username, which is per client even
 * when several clients share one pooled stream.
 */
class StreamHistoryRecorder
{
    /**
     * A session still open this long after it started lost its disconnect.
     */
    public const ABANDONED_AFTER_HOURS = 24;

    public function isEnabled(): bool
    {
        return config('proxy.proxy_integration_enabled', true)
            && app(GeneralSettings::class)->proxy_track_watch_history;
    }

    /**
     * Handle one m3u-proxy webhook. Never throws: a history failure must not
     * fail the webhook the proxy also uses for connection accounting.
     *
     * @param  array<string, mixed>  $data
     */
    public function handleWebhook(?string $eventType, ?string $streamId, array $data): void
    {
        if (! $streamId || ! $this->isEnabled()) {
            return;
        }

        try {
            match ($eventType) {
                'client_connected' => $this->open(
                    $streamId,
                    (string) ($data['client_id'] ?? ''),
                    $data,
                    (array) ($data['metadata'] ?? []),
                    lookUpProgrammeLater: true,
                ),
                'client_disconnected' => $this->closeClient(
                    $streamId,
                    (string) ($data['client_id'] ?? ''),
                    isset($data['bytes_served']) ? (int) $data['bytes_served'] : null,
                    'disconnected',
                ),
                'stream_stopped' => $this->closeStream($streamId, 'stream_stopped'),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::warning('Watch history: failed to record proxy webhook', [
                'event_type' => $eventType,
                'stream_id' => $streamId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * End sessions the proxy never reported closed. Their real length is
     * unknown, so it's left empty rather than counted as a day of viewing.
     */
    public function closeAbandoned(): int
    {
        $closed = 0;

        StreamWatchSession::query()
            ->open()
            ->where('started_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))
            ->lazyById()
            ->each(function (StreamWatchSession $session) use (&$closed): void {
                $session->forceFill([
                    'ended_at' => now(),
                    'duration_seconds' => null,
                    'end_reason' => 'no_disconnect',
                ])->save();
                $closed++;
            });

        return $closed;
    }

    /**
     * Open a session for this client on this stream, or refresh the open one.
     *
     * @param  array<string, mixed>  $client  client_id, username, ip_address, user_agent, bytes_served
     * @param  array<string, mixed>  $metadata  The stream's metadata, as set by M3uProxyService
     */
    public function open(
        string $streamId,
        string $clientId,
        array $client,
        array $metadata,
        bool $lookUpProgrammeLater = false,
    ): ?StreamWatchSession {
        if ($clientId === '') {
            return null;
        }

        $bytesServed = isset($client['bytes_served']) ? (int) $client['bytes_served'] : null;
        $existing = StreamWatchSession::query()
            ->open()
            ->where('proxy_client_id', $clientId)
            ->where('proxy_stream_id', $streamId)
            ->first();

        if ($existing) {
            $existing->forceFill(array_filter([
                'last_seen_at' => now(),
                'bytes_served' => $bytesServed,
            ], fn ($value): bool => $value !== null))->save();

            return $existing;
        }

        $content = $this->resolveContent($metadata);
        if (! $content) {
            return null;
        }

        $playlist = filled($metadata['playlist_uuid'] ?? null)
            ? app(PlaylistService::class)->resolvePlaylistByUuid($metadata['playlist_uuid'])
            : null;
        $userId = $playlist?->user_id ?? $content['user_id'];
        $username = filled($client['username'] ?? null) ? (string) $client['username'] : null;
        $playlistAuth = $this->resolvePlaylistAuth($userId, $username, $metadata['playlist_auth_id'] ?? null);

        $session = StreamWatchSession::create([
            ...Arr::except($content, ['user_id', 'probe_media_info']),
            'user_id' => $userId,
            'playlist_auth_id' => $playlistAuth?->id,
            'playlist_viewer_id' => $playlistAuth ? $this->resolveViewerId($playlistAuth, $playlist) : null,
            'playlist_type' => $playlist?->getMorphClass(),
            'playlist_id' => $playlist?->getKey(),
            'proxy_stream_id' => $streamId,
            'proxy_client_id' => $clientId,
            'username' => $username,
            'ip_address' => $client['ip_address'] ?? null,
            'user_agent' => $client['user_agent'] ?? null,
            'is_transcoded' => filter_var($metadata['transcoding'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_timeshift' => filter_var($metadata['timeshift'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'stream_profile_id' => is_numeric($metadata['profile_id'] ?? null) ? (int) $metadata['profile_id'] : null,
            'provider_profile_id' => is_numeric($metadata['provider_profile_id'] ?? null) ? (int) $metadata['provider_profile_id'] : null,
            'media_info' => array_filter($content['probe_media_info'], fn ($value): bool => $value !== null && $value !== '') ?: null,
            'metadata' => $metadata ?: null,
            'started_at' => now(),
            'last_seen_at' => now(),
            'bytes_served' => $bytesServed,
        ]);

        if ($session->content_type === 'live') {
            if ($lookUpProgrammeLater) {
                // Reading the guide touches disk; keep it off the webhook's response.
                dispatch(fn () => app(self::class)->recordProgramme($session->id))->afterResponse();
            } else {
                $this->recordProgramme($session->id);
            }
        }

        return $session;
    }

    /**
     * Close the client's open session on this stream. Falls back to any open
     * session for the client, since a normalized live stream reports its
     * clients under a related stream id.
     */
    public function closeClient(string $streamId, string $clientId, ?int $bytesServed, string $reason): void
    {
        if ($clientId === '') {
            return;
        }

        $session = StreamWatchSession::query()->open()
            ->where('proxy_client_id', $clientId)
            ->where('proxy_stream_id', $streamId)
            ->first()
            ?? StreamWatchSession::query()->open()
                ->where('proxy_client_id', $clientId)
                ->latest('started_at')
                ->first();

        $session?->close($reason, $bytesServed);
    }

    public function closeStream(string $streamId, string $reason): void
    {
        StreamWatchSession::query()->open()
            ->where('proxy_stream_id', $streamId)
            ->get()
            ->each(fn (StreamWatchSession $session) => $session->close($reason));
    }

    /**
     * Note what was on a live channel when the session started, from the
     * channel's cached guide.
     */
    public function recordProgramme(int $sessionId): void
    {
        $session = StreamWatchSession::with('channel')->find($sessionId);
        $epgChannel = $session?->channel?->epgChannel;
        // EpgChannel::epg() selects only id and name; the guide cache needs the uuid.
        $epg = $epgChannel?->epg_id ? Epg::find($epgChannel->epg_id) : null;
        if (! $session || ! $epg || ! $epgChannel->channel_id) {
            return;
        }

        try {
            $date = $session->started_at->format('Y-m-d');
            $programmes = Cache::remember(
                "watch-history:epg:{$epg->uuid}:{$epgChannel->channel_id}:{$date}",
                300,
                fn () => app(EpgCacheService::class)->getCachedProgrammesRange($epg, $date, $date, [$epgChannel->channel_id]),
            );
        } catch (Throwable) {
            return;
        }

        $at = $session->started_at;
        foreach ($programmes[$epgChannel->channel_id] ?? [] as $programme) {
            if (! isset($programme['start'], $programme['stop'], $programme['title'])) {
                continue;
            }
            if ($at->gte(Carbon::parse($programme['start'])) && $at->lt(Carbon::parse($programme['stop']))) {
                $session->forceFill(['programme_title' => mb_substr((string) $programme['title'], 0, 512)])->save();

                return;
            }
        }
    }

    /**
     * What the stream is: the channel the viewer asked for (not a failover
     * standing in for it), or the episode, with the details shown in history.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function resolveContent(array $metadata): ?array
    {
        $type = $metadata['type'] ?? null;

        if ($type === 'channel') {
            $id = $metadata['requested_channel_id'] ?? $metadata['original_channel_id'] ?? $metadata['channel_id'] ?? $metadata['id'] ?? null;
            $channel = is_numeric($id) ? Channel::find((int) $id) : null;
            if (! $channel) {
                return null;
            }

            $isVod = (bool) $channel->is_vod;
            $stats = $channel->getEmbyStreamStats();

            return [
                'user_id' => $channel->user_id,
                'content_type' => $isVod ? 'vod' : 'live',
                'channel_id' => $channel->id,
                'title' => mb_substr((string) ($isVod
                    ? ($channel->title_custom ?: $channel->title ?: $channel->name)
                    : ($channel->name_custom ?: $channel->name ?: $channel->title)), 0, 512),
                'year' => $isVod && $channel->year ? (int) $channel->year : null,
                'tmdb_id' => $isVod ? $channel->getTmdbId() : null,
                'imdb_id' => $isVod ? $this->imdbId($channel->getImdbId()) : null,
                'thumbnail_url' => LogoService::getChannelLogoUrl($channel),
                'probe_media_info' => [
                    'resolution' => $stats['resolution'] ?? null,
                    'video_codec' => $stats['video_codec'] ?? null,
                    'audio_codec' => $stats['audio_codec'] ?? null,
                    'audio_channels' => $stats['audio_channels'] ?? null,
                ],
            ];
        }

        if ($type === 'episode') {
            $id = $metadata['episode_id'] ?? $metadata['id'] ?? null;
            $episode = is_numeric($id) ? Episode::with('series')->find((int) $id) : null;
            if (! $episode) {
                return null;
            }

            $series = $episode->series;
            $ids = $series?->getMovieDbIds() ?? [];

            return [
                'user_id' => $episode->user_id,
                'content_type' => 'episode',
                'episode_id' => $episode->id,
                'series_id' => $episode->series_id,
                'title' => mb_substr((string) ($series?->name ?? $episode->title), 0, 512),
                'episode_title' => $episode->title ? mb_substr((string) $episode->title, 0, 512) : null,
                'season_number' => $episode->season,
                'episode_number' => $episode->episode_num,
                'tmdb_id' => $episode->tmdb_id,
                'imdb_id' => $this->imdbId($ids['imdb'] ?? null),
                'thumbnail_url' => $series?->cover ? LogoService::getSeriesLogoUrl($series) : LogoService::getEpisodeLogoUrl($episode),
                'probe_media_info' => [],
            ];
        }

        // Network broadcasts, DVR recordings and other streams aren't watch history.
        return null;
    }

    /**
     * The client's own Xtream username wins: on a pooled stream the stream's
     * playlist_auth_id is whoever started it, not this client.
     */
    private function resolvePlaylistAuth(int $userId, ?string $username, mixed $metadataAuthId): ?PlaylistAuth
    {
        if ($username !== null) {
            return PlaylistAuth::where('user_id', $userId)->where('username', $username)->first();
        }

        if (is_numeric($metadataAuthId)) {
            return PlaylistAuth::where('user_id', $userId)->find((int) $metadataAuthId);
        }

        return null;
    }

    /**
     * The auth's viewer for this playlist, or its only viewer. Not created
     * here: a viewer appears once the login uses watch progress.
     */
    private function resolveViewerId(PlaylistAuth $playlistAuth, ?Model $playlist): ?int
    {
        $viewers = PlaylistViewer::where('playlist_auth_id', $playlistAuth->id)->get(['id', 'viewerable_type', 'viewerable_id']);

        $match = $playlist
            ? $viewers->first(fn (PlaylistViewer $viewer): bool => $viewer->viewerable_type === $playlist->getMorphClass()
                && (int) $viewer->viewerable_id === (int) $playlist->getKey())
            : null;

        return $match?->id ?? ($viewers->count() === 1 ? $viewers->first()->id : null);
    }

    private function imdbId(mixed $value): ?string
    {
        $value = (string) $value;

        return str_starts_with($value, 'tt') && strlen($value) <= 20 ? $value : null;
    }
}
