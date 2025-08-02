<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\EpgSourceType;
use App\Services\EpgCacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Epg extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'synced' => 'datetime',
        'user_id' => 'integer',
        'uploads' => 'array',
        'status' => Status::class,
        'processing' => 'boolean',
        'is_cached' => 'boolean',
        'source_type' => EpgSourceType::class,
        'sd_token_expires_at' => 'datetime',
        'sd_last_sync' => 'datetime',
        'sd_station_ids' => 'array',
        'sd_errors' => 'array',
        'sd_days_to_import' => 'integer',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        // 'sd_password',
        'sd_token',
    ];

    public function getFolderPathAttribute(): string
    {
        return "epg/{$this->uuid}";
    }

    public function getFilePathAttribute(): string
    {
        return "epg/{$this->uuid}/epg.xml";
    }

    /**
     * Check if this EPG uses Schedules Direct
     */
    public function isSchedulesDirect(): bool
    {
        return $this->source_type === EpgSourceType::SCHEDULES_DIRECT;
    }

    /**
     * Check if Schedules Direct token is valid
     */
    public function hasValidSchedulesDirectToken(): bool
    {
        return $this->sd_token && 
               $this->sd_token_expires_at && 
               $this->sd_token_expires_at->isFuture();
    }

    /**
     * Check if Schedules Direct credentials are configured
     */
    public function hasSchedulesDirectCredentials(): bool
    {
        return !empty($this->sd_username) && !empty($this->sd_password);
    }

    /**
     * Check if Schedules Direct lineup is configured
     */
    public function hasSchedulesDirectLineup(): bool
    {
        return !empty($this->sd_lineup_id);
    }

    /**
     * Boot function for model
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($epg) {
            if (empty($epg->uuid)) {
                $epg->uuid = Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(EpgChannel::class);
    }

    public function epgMaps(): HasMany
    {
        return $this->hasMany(EpgMap::class);
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }
}
