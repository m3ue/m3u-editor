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
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
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
     */
    public function getStreamUrlAttribute(): string
    {
        return route('network.stream', ['network' => $this->uuid, 'container' => 'ts']);
    }
}
