<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        // ...
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function customPlaylists(): HasMany
    {
        return $this->hasMany(CustomPlaylist::class);
    }

    public function mergedPlaylists(): HasMany
    {
        return $this->hasMany(MergedPlaylist::class);
    }

    public function playlistAliases(): HasMany
    {
        return $this->hasMany(PlaylistAlias::class);
    }

    /**
     * Get template variables for FFmpeg profile rendering.
     * The 'args' field can store either:
     * 1. A full FFmpeg argument template string (e.g., "-c:v libx264 -preset faster...")
     * 2. JSON-encoded key-value pairs for predefined profile overrides
     *
     * This method attempts to parse as JSON first, falls back to empty array.
     *
     * @param  array  $additionalVars  Optional additional variables to merge
     * @return array Template variables as associative array
     */
    public function getTemplateVariables(array $additionalVars = []): array
    {
        $variables = [];

        // Try to parse args as JSON (for template variable overrides)
        // if ($this->format === 'm3u8') {
        //     $variables['output_format'] = 'hls';
        // } else {
        //     $variables['output_format'] = $this->format;
        // }

        // Merge with additional variables (additional vars take precedence)
        return array_merge($variables, $additionalVars);
    }

    /**
     * Get the profile identifier for API usage.
     * Returns either custom args template or predefined profile name.
     *
     * @return string Profile template or name for m3u-proxy API
     */
    public function getProfileIdentifier(): string
    {
        return $this->args;
    }
}
