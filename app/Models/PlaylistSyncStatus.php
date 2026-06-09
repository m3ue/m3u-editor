<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaylistSyncStatus extends Model
{
    use HasFactory;
    use MassPrunable;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'deleted_groups' => 'array',
        'added_groups' => 'array',
        'deleted_channels' => 'array',
        'added_channels' => 'array',
        'sync_stats' => 'array',
    ];

    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<', now()->subDays(30));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PlaylistSyncStatusLog::class);
    }

    public function removedGroups(): HasMany
    {
        return $this->logs()
            ->where([
                ['type', 'group'],
                ['status', 'removed'],
            ]);
    }

    public function addedGroups(): HasMany
    {
        return $this->logs()
            ->where([
                ['type', 'group'],
                ['status', 'added'],
            ]);
    }

    public function removedChannels(): HasMany
    {
        return $this->logs()
            ->where([
                ['type', 'channel'],
                ['status', 'removed'],
            ]);
    }

    public function addedChannels(): HasMany
    {
        return $this->logs()
            ->where([
                ['type', 'channel'],
                ['status', 'added'],
            ]);
    }
}
