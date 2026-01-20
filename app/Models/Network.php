<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Network extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'channel_number' => 'integer',
        'enabled' => 'boolean',
        'loop_content' => 'boolean',
        'user_id' => 'integer',
        'media_server_integration_id' => 'integer',
        'schedule_generated_at' => 'datetime',
        'schedule_window_days' => 'integer',
        'auto_regenerate_schedule' => 'boolean',
        // Broadcast settings
        'broadcast_enabled' => 'boolean',
        'broadcast_requested' => 'boolean',
        'segment_duration' => 'integer',
        'hls_list_size' => 'integer',
        'transcode_on_server' => 'boolean',
        'video_bitrate' => 'integer',
        'audio_bitrate' => 'integer',
        'broadcast_started_at' => 'datetime',
        'broadcast_pid' => 'integer',
        'broadcast_programme_id' => 'integer',
        'broadcast_initial_offset_seconds' => 'integer',
        'broadcast_scheduled_start' => 'datetime',
        'broadcast_schedule_enabled' => 'boolean',
    ];

    /**
     * If a broadcast reference exists (programme + initial offset), compute the
     * effective seek position for 'now' taking into account time elapsed since
     * the broadcast was originally started. Returns null if no persisted
     * reference exists.
     */
    public function getPersistedBroadcastSeekForNow(): ?int
    {
        if (! $this->broadcast_programme_id || ! $this->broadcast_started_at || $this->broadcast_initial_offset_seconds === null) {
            return null;
        }

        $programme = $this->programmes()->where('id', $this->broadcast_programme_id)->first();

        // If the original programme still exists and is currently airing, continue from persisted offset + elapsed
        if ($programme && now()->between($programme->start_time, $programme->end_time)) {
            $elapsed = $this->broadcast_started_at->diffInSeconds(now());

            return (int) max(0, $this->broadcast_initial_offset_seconds + $elapsed);
        }

        // Otherwise, fall back to current programme's seek position
        return $this->getCurrentSeekPosition();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Network $network) {
            if (empty($network->uuid)) {
                $network->uuid = Str::uuid()->toString();
            }
        });

        // Sync network channels when network is updated
        static::updated(function (Network $network) {
            app(\App\Services\NetworkChannelSyncService::class)->refreshNetworkChannel($network);
        });

        // Remove network channels when network is deleted
        static::deleting(function (Network $network) {
            // Ensure any running broadcast is stopped and HLS files are removed
            try {
                app(\App\Services\NetworkBroadcastService::class)->stop($network);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to stop network broadcast during deletion', [
                    'network_id' => $network->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Remove HLS storage directory to free disk space
            $hlsPath = $network->getHlsStoragePath();
            if (\Illuminate\Support\Facades\File::isDirectory($hlsPath)) {
                \Illuminate\Support\Facades\File::deleteDirectory($hlsPath);
            }

            \App\Models\Channel::where('network_id', $network->id)->delete();
        });
    }

    /**
     * Resolve route binding - use UUID for public routes, ID for admin.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // If it looks like a UUID, find by uuid
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return $this->where('uuid', $value)->firstOrFail();
        }

        // Otherwise use default (id)
        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * Get the user that owns this network.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the media server integration this network is associated with.
     */
    public function mediaServerIntegration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class);
    }

    /**
     * Get the playlist this network outputs to (for M3U generation).
     */
    public function networkPlaylist(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Playlist::class, 'network_playlist_id');
    }

    /**
     * Get the content items assigned to this network.
     */
    public function networkContent(): HasMany
    {
        return $this->hasMany(NetworkContent::class)->orderBy('sort_order');
    }

    /**
     * Get the generated programme schedule.
     */
    public function programmes(): HasMany
    {
        return $this->hasMany(NetworkProgramme::class)->orderBy('start_time');
    }

    /**
     * Check if the schedule needs to be regenerated.
     * Returns false if auto_regenerate_schedule is disabled.
     */
    public function needsScheduleRegeneration(): bool
    {
        // If auto-regeneration is disabled, never auto-regenerate
        if ($this->auto_regenerate_schedule === false) {
            return false;
        }

        if (! $this->schedule_generated_at) {
            return true;
        }

        // Regenerate if last programme ends within 24 hours
        $lastProgramme = $this->programmes()->latest('end_time')->first();
        if (! $lastProgramme) {
            return true;
        }

        return $lastProgramme->end_time->diffInHours(now()) < 24;
    }

    /**
     * Get the EPG URL for this network.
     */
    public function getEpgUrlAttribute(): string
    {
        return route('network.epg', ['network' => $this->uuid]);
    }

    /**
     * Get the stream URL for this network.
     * Returns HLS playlist URL if broadcasting, otherwise legacy stream endpoint.
     */
    public function getStreamUrlAttribute(): string
    {
        if ($this->broadcast_enabled && $this->isBroadcasting()) {
            return route('network.hls.playlist', ['network' => $this->uuid]);
        }

        return route('network.stream', ['network' => $this->uuid, 'container' => 'ts']);
    }

    /**
     * Get the HLS playlist URL for this network.
     */
    public function getHlsUrlAttribute(): string
    {
        return route('network.hls.playlist', ['network' => $this->uuid]);
    }

    /**
     * Check if this network is currently broadcasting.
     */
    public function isBroadcasting(): bool
    {
        return $this->broadcast_started_at !== null && $this->broadcast_pid !== null;
    }

    /**
     * Check if this network is waiting for its scheduled start time.
     */
    public function isWaitingForScheduledStart(): bool
    {
        return $this->broadcast_enabled
            && $this->broadcast_schedule_enabled
            && $this->broadcast_scheduled_start !== null
            && now()->lt($this->broadcast_scheduled_start);
    }

    /**
     * Get the storage path for HLS segments.
     */
    public function getHlsStoragePath(): string
    {
        return storage_path("app/networks/{$this->uuid}");
    }

    /**
     * Get the current programme that should be playing now.
     */
    public function getCurrentProgramme(): ?NetworkProgramme
    {
        return $this->programmes()
            ->where('start_time', '<=', now())
            ->where('end_time', '>', now())
            ->first();
    }

    /**
     * Get the next programme after the current one.
     */
    public function getNextProgramme(): ?NetworkProgramme
    {
        return $this->programmes()
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->first();
    }

    /**
     * Calculate the seek position into the current programme.
     */
    public function getCurrentSeekPosition(): int
    {
        $current = $this->getCurrentProgramme();
        if (! $current) {
            return 0;
        }

        return (int) $current->start_time->diffInSeconds(now(), false);
    }

    /**
     * Get the remaining duration of the current programme in seconds.
     */
    public function getCurrentRemainingDuration(): int
    {
        $current = $this->getCurrentProgramme();
        if (! $current) {
            return 0;
        }

        return (int) now()->diffInSeconds($current->end_time, false);
    }

    /**
     * Count HLS segment files for this network.
     */
    public function getHlsSegmentCountAttribute(): int
    {
        $path = $this->getHlsStoragePath();

        if (! is_dir($path)) {
            return 0;
        }

        $files = glob($path.'/*.ts');

        return $files === false ? 0 : count($files);
    }

    /**
     * Total bytes used by HLS files for this network.
     */
    public function getHlsStorageBytesAttribute(): int
    {
        $path = $this->getHlsStoragePath();

        if (! is_dir($path)) {
            return 0;
        }

        $total = 0;
        foreach (glob($path.'/*') as $file) {
            if (is_file($file)) {
                $total += filesize($file) ?: 0;
            }
        }

        return (int) $total;
    }
}
