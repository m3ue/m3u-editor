<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrIntegration extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'guest_enabled' => 'boolean',
            'api_key' => 'encrypted',
            'last_test_at' => 'datetime',
            'quality_profile_id' => 'integer',
        ];
    }

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'api_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function isSonarr(): bool
    {
        return $this->type === 'sonarr';
    }

    public function isRadarr(): bool
    {
        return $this->type === 'radarr';
    }

    /**
     * Base URL with trailing slash stripped.
     */
    public function getBaseUrlAttribute(): string
    {
        return rtrim($this->url, '/');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeGuestEnabled($query)
    {
        return $query->where('guest_enabled', true);
    }
}
