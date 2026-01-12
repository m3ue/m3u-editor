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
        // Broadcast settings
        'broadcast_enabled' => 'boolean',
        'segment_duration' => 'integer',
        'hls_list_size' => 'integer',
        'transcode_on_server' => 'boolean',
        'video_bitrate' => 'integer',
        'audio_bitrate' => 'integer',
        'broadcast_started_at' => 'datetime',
        'broadcast_pid' => 'integer',
    ];

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
     */
    public function needsScheduleRegeneration(): bool
    {
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

        return now()->diffInSeconds($current->start_time);
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

        return $current->end_time->diffInSeconds(now());
    }
}
