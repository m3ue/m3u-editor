<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaServerIntegration extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'port' => 'integer',
        'enabled' => 'boolean',
        'ssl' => 'boolean',
        'import_movies' => 'boolean',
        'import_series' => 'boolean',
        'last_synced_at' => 'datetime',
        'sync_stats' => 'array',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * Get the user that owns this integration.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the playlist associated with this integration.
     * Content synced from the media server is stored in this playlist.
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the base URL for the media server.
     */
    public function getBaseUrlAttribute(): string
    {
        $protocol = $this->ssl ? 'https' : 'http';
        return "{$protocol}://{$this->host}:{$this->port}";
    }

    /**
     * Check if this is an Emby server.
     */
    public function isEmby(): bool
    {
        return $this->type === 'emby';
    }

    /**
     * Check if this is a Jellyfin server.
     */
    public function isJellyfin(): bool
    {
        return $this->type === 'jellyfin';
    }

    /**
     * Scope to only enabled integrations.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
