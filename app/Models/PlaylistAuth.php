<?php

namespace App\Models;

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
        'user_id' => 'integer',
        'max_connections' => 'integer',
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

    /**
     * Resolve the effective proxy override for this auth.
     *
     * Returns null if inheriting from playlist, true/false if overriding.
     * When forcing proxy on, checks that the user has proxy permission.
     */
    public function resolveEnableProxy(): ?bool
    {
        $value = $this->getRawOriginal('enable_proxy');

        if (is_null($value)) {
            return null;
        }

        if ($value && ! $this->user?->canUseProxy()) {
            return false;
        }

        return (bool) $value;
    }

    /**
     * Check if this auth has reached its concurrent connection limit.
     */
    public function isAtConnectionLimit(int $activeCount): bool
    {
        return $this->max_connections > 0 && $activeCount >= $this->max_connections;
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
