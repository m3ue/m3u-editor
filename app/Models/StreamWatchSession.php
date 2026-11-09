<?php

namespace App\Models;

use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One player connection the proxy served: who watched what, from which
 * client, and for how long (Proxy > Watch History). Opened and closed from
 * the proxy's client_connected / client_disconnected webhooks (see
 * StreamHistoryRecorder).
 */
class StreamWatchSession extends Model
{
    use HasFactory;
    use Prunable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_transcoded' => 'boolean',
            'is_timeshift' => 'boolean',
            'media_info' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'bytes_served' => 'integer',
            'season_number' => 'integer',
            'episode_number' => 'integer',
            'year' => 'integer',
            'tmdb_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(PlaylistViewer::class, 'playlist_viewer_id');
    }

    /**
     * The playlist the stream was requested through: a Playlist, Custom or
     * Merged Playlist, or Playlist Alias.
     */
    public function playlist(): MorphTo
    {
        return $this->morphTo();
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Seconds watched so far for an open session, or in total once closed.
     * Null when the proxy never reported the end, so the length is unknown.
     */
    public function watchedSeconds(): ?int
    {
        if ($this->duration_seconds !== null) {
            return $this->duration_seconds;
        }

        if ($this->ended_at !== null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds(now(), true);
    }

    /**
     * End the session at $endedAt (defaults to now), recording the duration
     * and, when known, how much the client was sent.
     */
    public function close(string $reason, ?int $bytesServed = null, mixed $endedAt = null): void
    {
        $endedAt ??= now();
        if ($endedAt->lt($this->started_at)) {
            $endedAt = $this->started_at;
        }

        $this->forceFill([
            'ended_at' => $endedAt,
            'duration_seconds' => (int) $this->started_at->diffInSeconds($endedAt, true),
            'bytes_served' => $bytesServed ?? $this->bytes_served,
            'end_reason' => $reason,
        ])->save();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * A friendly name for the player, from its user agent.
     */
    public function clientName(): ?string
    {
        $agent = (string) $this->user_agent;
        if ($agent === '') {
            return null;
        }

        $known = [
            '/tivimate/i' => 'TiviMate',
            '/smarters/i' => 'IPTV Smarters',
            '/kodi/i' => 'Kodi',
            '/vlc|libvlc/i' => 'VLC',
            '/exoplayer|androidx\.media3/i' => 'ExoPlayer',
            '/mpv|libmpv/i' => 'mpv',
            '/applecoremedia|avfoundation|apple ?tv|tvos/i' => 'Apple player',
            '/plex/i' => 'Plex',
            '/emby/i' => 'Emby',
            '/jellyfin/i' => 'Jellyfin',
            '/channels/i' => 'Channels DVR',
            '/lavf|ffmpeg/i' => 'FFmpeg',
            '/edg\//i' => 'Edge',
            '/chrome\//i' => 'Chrome',
            '/firefox\//i' => 'Firefox',
            '/safari\//i' => 'Safari',
        ];

        foreach ($known as $pattern => $name) {
            if (preg_match($pattern, $agent)) {
                return $name;
            }
        }

        // Otherwise the product token, e.g. "SomePlayer/2.1 (Linux)" -> "SomePlayer".
        return preg_match('/^([A-Za-z][\w.\- ]{0,40}?)\//', $agent, $match) ? trim($match[1]) : null;
    }

    /**
     * Sessions older than the retention set under Settings > Proxy are
     * removed by the daily model:prune run. A retention of 0 keeps them all.
     */
    public function prunable(): Builder
    {
        $days = (int) app(GeneralSettings::class)->proxy_watch_history_retention_days;

        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('started_at', '<', now()->subDays($days));
    }
}
