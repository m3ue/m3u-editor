<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TvDevice extends Model
{
    use HasFactory, MassPrunable;

    /**
     * Oldest M3U TV release that ships the `device.deregister` socket handler.
     * Devices reporting an older (or unknown) version can't be forced to log
     * out remotely, so the Revoke action is disabled for them.
     */
    public const MIN_DEREGISTER_VERSION = '1.1.2';

    protected $fillable = [
        'device_id',
        'notifiable_type',
        'notifiable_id',
        'playlist_auth_id',
        'device_name',
        'platform',
        'app_version',
        'last_ip',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    /**
     * The mobile push registration for this same physical device, if any
     * (mobile builds send a matching `device_id` to push/subscribe).
     */
    public function pushToken(): HasOne
    {
        return $this->hasOne(PushDeviceToken::class, 'device_id', 'device_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether this device's reported app version understands the remote
     * `device.deregister` event. Unknown version -> treated as too old.
     */
    public function supportsRemoteDeregister(): bool
    {
        return $this->app_version !== null
            && version_compare($this->app_version, self::MIN_DEREGISTER_VERSION, '>=');
    }

    /**
     * Tombstoned rows linger for a few days so the admin can see the device was
     * told to log out; everything else is pruned on the normal stale-device
     * window (same as push_device_tokens).
     */
    public function prunable(): Builder
    {
        return static::where('revoked_at', '<', now()->subDays(3))
            ->orWhere('last_seen_at', '<', now()->subDays(config('services.push_relay.stale_days', 60)));
    }
}
