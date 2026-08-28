<?php

namespace App\Models;

use App\Events\DeviceDeregisteredEvent;
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
     * out remotely, so the Log out / Revoke actions are disabled for them.
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
     * Signs the M3U TV app out on this device (drops it back to the pairing
     * screen over the socket it is already subscribed to) and clears any linked
     * push registration so it stops receiving notifications. The device can
     * pair again immediately - use revokeAccess() to also block it.
     */
    public function logOut(): void
    {
        $event = DeviceDeregisteredEvent::forDevice($this);

        if ($event !== null) {
            event($event);
        }

        $this->forgetPushRegistrations();
    }

    /**
     * Signs the device out (see logOut()) and marks it revoked, so the app is
     * bounced straight back to the pairing screen on every reconnect attempt
     * until restoreAccess() is called. No-op if already revoked.
     */
    public function revokeAccess(): void
    {
        if ($this->isRevoked()) {
            return;
        }

        $this->logOut();

        $this->update(['revoked_at' => now()]);
    }

    /**
     * Lifts a revoke. The device (or a fresh install reusing the same
     * `device_id`) can pair again on its next attempt.
     */
    public function restoreAccess(): void
    {
        if (! $this->isRevoked()) {
            return;
        }

        $this->update(['revoked_at' => null]);
    }

    /**
     * Deletes the push token(s) for this physical device so it stops receiving
     * notifications the moment it is signed out.
     */
    protected function forgetPushRegistrations(): void
    {
        PushDeviceToken::query()
            ->where(function (Builder $query): void {
                $query->where('device_id', $this->device_id);

                // Legacy fallback: push tokens registered before the app sent
                // `device_id` (pre-1.1.2) have it NULL, so the precise match
                // misses them and the device keeps getting push despite the
                // "signed out" copy. Sweep those by the coarser identity we do
                // have. A sibling device on the same auth + platform that also
                // predates `device_id` would be caught too; it re-registers
                // (with a `device_id`) on its next launch.
                $query->orWhere(function (Builder $legacy): void {
                    $legacy->whereNull('device_id')
                        ->where('notifiable_type', $this->notifiable_type)
                        ->where('notifiable_id', $this->notifiable_id)
                        ->where('platform', $this->platform)
                        ->where('playlist_auth_id', $this->playlist_auth_id);
                });
            })
            ->delete();
    }

    /**
     * Revoked rows are kept indefinitely - they are a deliberate block-list an
     * admin can lift, so they must survive the stale-device sweep. Everything
     * else is pruned on the normal window (same as push_device_tokens).
     */
    public function prunable(): Builder
    {
        return static::whereNull('revoked_at')
            ->where('last_seen_at', '<', now()->subDays(config('services.push_relay.stale_days', 60)));
    }
}
