<?php

namespace App\Models;

use App\Enums\DvrRecordingStatus;
use App\Pivots\PlaylistAuthPivot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlaylistAuth extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (PlaylistAuth $playlistAuth): void {
            TvNotification::where('playlist_auth_id', $playlistAuth->id)->delete();
            PushDeviceToken::where('playlist_auth_id', $playlistAuth->id)->delete();
        });
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'enabled' => 'boolean',
        'dvr_enabled' => 'boolean',
        'dvr_max_concurrent_recordings' => 'integer',
        'dvr_storage_quota_gb' => 'integer',
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'max_connections' => 'integer',
        'stop_oldest_on_limit' => 'boolean',
        'request_enabled' => 'boolean',
        'auto_approve_requests' => 'boolean',
        'library_publishing_enabled' => 'boolean',
        'aiostreams_enabled' => 'boolean',
        'proxy_enabled' => 'boolean',
        'proxy_stream_profile_ids' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function viewer(): HasOne
    {
        return $this->hasOne(PlaylistViewer::class);
    }

    public function dvrRecordings(): HasMany
    {
        return $this->hasMany(DvrRecording::class);
    }

    public function dvrRules(): HasMany
    {
        return $this->hasMany(DvrRecordingRule::class);
    }

    public function tvNotificationReads(): HasMany
    {
        return $this->hasMany(TvNotificationRead::class);
    }

    /**
     * Whether this auth has reached its per-guest concurrent recording cap.
     * Returns false when no cap is configured (null = unlimited).
     *
     * @param  int  $pendingInTick  Recordings already counted as started for this auth
     *                              earlier in the same scheduler tick but not yet
     *                              reflected in the database — see DvrSetting::isAtCapacity().
     */
    public function hasReachedConcurrentLimit(int $pendingInTick = 0): bool
    {
        if ($this->dvr_max_concurrent_recordings === null) {
            return false;
        }

        $active = $this->dvrRecordings()
            ->whereIn('status', [DvrRecordingStatus::Recording, DvrRecordingStatus::PostProcessing])
            ->count();

        return ($active + $pendingInTick) >= $this->dvr_max_concurrent_recordings;
    }

    /**
     * Whether this auth has exhausted its per-guest storage quota.
     * Returns false when no quota is configured (null = unlimited).
     */
    public function hasReachedStorageQuota(): bool
    {
        if ($this->dvr_storage_quota_gb === null) {
            return false;
        }

        $usedBytes = $this->dvrRecordings()
            ->whereNotNull('file_size_bytes')
            ->sum('file_size_bytes');

        $quotaBytes = $this->dvr_storage_quota_gb * 1024 * 1024 * 1024;

        return $usedBytes >= $quotaBytes;
    }

    /**
     * Total storage used by recordings attributed to this auth, in bytes.
     */
    public function getStorageUsedBytesAttribute(): int
    {
        return (int) $this->dvrRecordings()
            ->whereNotNull('file_size_bytes')
            ->sum('file_size_bytes');
    }

    /**
     * Whether this auth may apply the given stream profile when proxying.
     *
     * Profile access modes: 'all' (any owner profile), 'selected' (only the
     * IDs in proxy_stream_profile_ids), 'none' (direct proxy only).
     */
    public function allowsProxyStreamProfile(int $profileId): bool
    {
        if (! $this->proxy_enabled) {
            return false;
        }

        return match ($this->proxy_profile_access) {
            'none' => false,
            'selected' => in_array($profileId, array_map('intval', $this->proxy_stream_profile_ids ?? []), true),
            default => true, // 'all'
        };
    }

    /**
     * Determine whether this auth credential is expired.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(PlaylistAuthPivot::class, 'playlist_auth_id')
            ->where('authenticatable_type', '!=', null) // Ensure it's a morph relation
            ->whereHasMorph('authenticatable', [
                CustomPlaylist::class,
                MergedPlaylist::class,
                Playlist::class,
                PlaylistAlias::class,
            ]);
    }

    /**
     * Get the single assigned playlist (since we now enforce one-to-one)
     */
    public function assignedPlaylist(): HasOne
    {
        return $this->hasOne(PlaylistAuthPivot::class, 'playlist_auth_id');
    }

    /**
     * Entitled credentials for a broadcast fired on ($notifiableType, $notifiableId) include
     * both directly-assigned auths and auths assigned to a PlaylistAlias whose effective
     * playlist is the same model — a DVR/global notification is raised against the
     * underlying Playlist/CustomPlaylist, but an alias-assigned guest never subscribes to
     * that model's own channel, only to their alias's.
     */
    public function scopeEntitledToNotificationRecipient(Builder $query, string $notifiableType, int|string $notifiableId): Builder
    {
        $aliasColumn = match ($notifiableType) {
            Relation::getMorphAlias(Playlist::class) => 'playlist_id',
            Relation::getMorphAlias(CustomPlaylist::class) => 'custom_playlist_id',
            default => null,
        };

        $aliasIds = $aliasColumn !== null
            ? PlaylistAlias::query()->where($aliasColumn, $notifiableId)->pluck('id')
            : collect();

        return $query
            ->where('enabled', true)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereHas('assignedPlaylist', function (Builder $query) use ($notifiableType, $notifiableId, $aliasIds): void {
                $query->where(function (Builder $query) use ($notifiableType, $notifiableId): void {
                    $query->where('authenticatable_type', $notifiableType)
                        ->where('authenticatable_id', $notifiableId);
                });

                if ($aliasIds->isNotEmpty()) {
                    $query->orWhere(function (Builder $query) use ($aliasIds): void {
                        $query->where('authenticatable_type', Relation::getMorphAlias(PlaylistAlias::class))
                            ->whereIn('authenticatable_id', $aliasIds);
                    });
                }
            });
    }

    /**
     * Get the assigned playlist model directly (convenience method)
     * This is used by the Xtream API controllers
     */
    public function playlist()
    {
        $pivot = $this->assignedPlaylist;

        return $pivot ? $pivot->authenticatable : null;
    }

    /**
     * Assign this PlaylistAuth to a specific model
     * This will remove any existing assignment and create a new one
     */
    public function assignTo(Model $model): void
    {
        if (! in_array(get_class($model), [Playlist::class, CustomPlaylist::class, MergedPlaylist::class, PlaylistAlias::class])) {
            throw new InvalidArgumentException('PlaylistAuth can only be assigned to Playlist, CustomPlaylist, MergedPlaylist, or PlaylistAlias models');
        }

        // Remove any existing assignment
        $this->clearAssignment();

        // Create new assignment
        PlaylistAuthPivot::create([
            'playlist_auth_id' => $this->id,
            'authenticatable_type' => $model->getMorphClass(),
            'authenticatable_id' => $model->id,
        ]);
    }

    /**
     * Clear any existing assignment
     */
    public function clearAssignment(): void
    {
        PlaylistAuthPivot::where('playlist_auth_id', $this->id)->delete();
    }

    /**
     * Get the currently assigned model
     */
    public function getAssignedModel(): ?Model
    {
        $pivot = $this->assignedPlaylist;

        return $pivot ? $pivot->authenticatable : null;
    }

    /**
     * Check if this PlaylistAuth is assigned to any model
     */
    public function isAssigned(): bool
    {
        return $this->assignedPlaylist()->exists();
    }

    /**
     * Get the name of the currently assigned model
     */
    public function getAssignedModelNameAttribute(): ?string
    {
        $model = $this->getAssignedModel();

        return $model ? $model->name : '';
    }

    /**
     * @throws ValidationException
     */
    public function setRelation($relation, $value)
    {
        if ($relation === 'playlists') {
            if ($this->playlists()->exists()) {
                throw new ValidationException('A PlaylistAuth can only be assigned to one model at a time.');
            }
        }

        parent::setRelation($relation, $value);
    }

    /**
     * Boot method to add model event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Ensure we don't accidentally create multiple assignments
        static::creating(function ($model) {
            // This is handled by the unique constraint and assignTo method
        });
    }
}
