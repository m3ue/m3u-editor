<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PushDeviceToken extends Model
{
    use HasFactory, MassPrunable;

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'token',
        'platform',
        'playlist_auth_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Devices are "checked in" every time the mobile app registers/refreshes
     * its token (app launch/foreground, see TvApiController::registerPushToken).
     * A device that hasn't checked in for the configured window is treated as
     * uninstalled/abandoned rather than a still-valid user, so it's safe to prune.
     */
    public function prunable(): Builder
    {
        return static::where('last_seen_at', '<', now()->subDays(config('services.push_relay.stale_days', 60)));
    }
}
