<?php

namespace App\Models;

use App\Events\MediaRequestStatusEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaRequest extends Model
{
    protected $fillable = [
        'playlist_auth_id',
        'arr_integration_id',
        'title',
        'external_id',
        'request_type',
        'season_number',
        'episode_number',
        'payload',
        'status',
        'notes',
        'requested_at',
        'reviewed_at',
        'reviewed_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'playlist_auth_id' => 'integer',
        'arr_integration_id' => 'integer',
        'season_number' => 'integer',
        'episode_number' => 'integer',
        'payload' => 'array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'reviewed_by_user_id' => 'integer',
    ];

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function arrIntegration(): BelongsTo
    {
        return $this->belongsTo(ArrIntegration::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Pushes this request's current status to the owning playlist's TV app
     * channel over Reverb, so clients can update the requests screen live
     * instead of polling request_status/request_history. Silently no-ops
     * when the owning playlist can't be resolved (e.g. orphaned rows).
     */
    public function broadcastStatus(): void
    {
        $event = MediaRequestStatusEvent::fromRequest($this);

        if ($event) {
            broadcast($event);
        }
    }
}
