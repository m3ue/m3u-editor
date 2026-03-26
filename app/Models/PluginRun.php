<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PluginRun extends Model
{
    use HasFactory;

    protected $table = 'extension_plugin_runs';

    protected $fillable = [
        'extension_plugin_id',
        'user_id',
        'trigger',
        'invocation_type',
        'action',
        'hook',
        'dry_run',
        'status',
        'payload',
        'result',
        'run_state',
        'summary',
        'progress',
        'progress_message',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'cancel_requested',
        'cancel_requested_at',
        'cancelled_at',
        'stale_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'run_state' => 'array',
        'dry_run' => 'boolean',
        'progress' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'cancel_requested' => 'boolean',
        'cancel_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class, 'extension_plugin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PluginRunLog::class, 'extension_plugin_run_id')->latest();
    }

    public function isStale(int $minutes = 15): bool
    {
        if ($this->status !== 'running' || ! $this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->lt(now()->subMinutes($minutes));
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public function canBeViewedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $this->user_id !== null && $this->user_id === $user->id;
    }
}
