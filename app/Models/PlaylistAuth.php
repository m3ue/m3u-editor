<?php

namespace App\Models;

use App\Enums\DvrRecordingStatus;
use App\Pivots\PlaylistAuthPivot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlaylistAuth extends Model
{
    use HasFactory;

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

    /**
     * Whether this auth has reached its per-guest concurrent recording cap.
     * Returns false when no cap is configured (null = unlimited).
     */
    public function hasReachedConcurrentLimit(): bool
    {
        if ($this->dvr_max_concurrent_recordings === null) {
            return false;
        }

        $active = $this->dvrRecordings()
            ->whereIn('status', [DvrRecordingStatus::Recording, DvrRecordingStatus::PostProcessing])
            ->count();

        return $active >= $this->dvr_max_concurrent_recordings;
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
            'authenticatable_type' => get_class($model),
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
