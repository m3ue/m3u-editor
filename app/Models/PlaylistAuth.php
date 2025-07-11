<?php

namespace App\Models;

use App\Pivots\PlaylistAuthPivot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(PlaylistAuthPivot::class, 'playlist_auth_id')
            ->where('authenticatable_type', '!=', null) // Ensure it's a morph relation
            ->whereHasMorph('authenticatable', [
                CustomPlaylist::class,
                MergedPlaylist::class,
                Playlist::class,
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
        if (!in_array(get_class($model), [Playlist::class, CustomPlaylist::class, MergedPlaylist::class])) {
            throw new \InvalidArgumentException('PlaylistAuth can only be assigned to Playlist, CustomPlaylist, or MergedPlaylist models');
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
                throw new ValidationException("A PlaylistAuth can only be assigned to one model at a time.");
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
