<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->webhook_secret)) {
                $model->webhook_secret = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Full webhook URL to paste into Radarr/Sonarr notification settings.
     */
    public function getWebhookUrlAttribute(): ?string
    {
        if (! $this->webhook_secret) {
            return null;
        }

        return url('/api/webhooks/arr/'.$this->webhook_secret);
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
