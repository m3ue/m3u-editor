<?php

namespace App\Models;

use App\Services\EpgCacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AedProfile extends Model
{
    use HasFactory;

    /**
     * EPG cache targets resolved before a delete, since the foreign keys pointing
     * at this profile are nulled by the delete itself.
     *
     * @var array<string, array<int>>
     */
    protected array $pendingEpgCacheTargets = [];

    protected static function booted(): void
    {
        // AED programmes are built into the cached playlist EPG files, so drop the
        // affected files when the profile changes or goes away. A new profile isn't
        // assigned to anything yet.
        static::updated(function (AedProfile $profile): void {
            EpgCacheService::clearForAedProfile($profile->id);
        });

        static::deleting(function (AedProfile $profile): void {
            $profile->pendingEpgCacheTargets = EpgCacheService::aedProfileCacheTargets($profile->id);
        });

        static::deleted(function (AedProfile $profile): void {
            EpgCacheService::bulkDeleteCacheFiles($profile->pendingEpgCacheTargets);
        });
    }

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'override' => 'boolean',
        'event_duration_minutes' => 'integer',
        'dummy_epg_days' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
