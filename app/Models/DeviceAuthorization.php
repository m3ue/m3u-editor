<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAuthorization extends Model
{
    use HasFactory;
    use Prunable;

    protected $fillable = [
        'device_code',
        'user_code',
        'status',
        'playlist_auth_id',
        'playlist_auth_ids',
        'approved_by_user_id',
        'approved_ip',
        'requested_ip',
        'poll_attempts',
        'last_polled_at',
        'interval_seconds',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'playlist_auth_id' => 'integer',
        'playlist_auth_ids' => 'array',
        'approved_by_user_id' => 'integer',
        'poll_attempts' => 'integer',
        'interval_seconds' => 'integer',
        'last_polled_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * Prunable via the daily `model:prune` schedule (see routes/console.php):
     * expired-and-unconsumed codes, plus consumed rows that somehow survived
     * the post-approval delete in DeviceAuthorizationController (e.g. a
     * mid-request crash).
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now())
            ->orWhere('consumed_at', '<', now()->subHour());
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    /**
     * Every credential granted to the device, primary first, in the order the
     * admin set. Pairings approved before multiple credentials were allowed
     * only carry the single playlist_auth_id. Credentials deleted since the
     * approval are skipped.
     *
     * @return Collection<int, PlaylistAuth>
     */
    public function grantedPlaylistAuths(): Collection
    {
        $ids = collect($this->playlist_auth_ids ?: [$this->playlist_auth_id])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        $auths = PlaylistAuth::whereIn('id', $ids)->get()->keyBy('id');

        return new Collection($ids->map(fn (int $id): ?PlaylistAuth => $auths->get($id))->filter()->values()->all());
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Determine whether this pairing code has expired.
     */
    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Determine whether the credentials for this pairing have already been handed out.
     */
    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
