<?php

namespace App\Models;

use App\Enums\EpgMapCandidateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpgMapCandidate extends Model
{
    use HasFactory;
    use MassPrunable;

    protected $casts = [
        'id' => 'integer',
        'epg_map_id' => 'integer',
        'channel_id' => 'integer',
        'epg_channel_id' => 'integer',
        'top_confidence' => 'integer',
        'is_exact' => 'boolean',
        'automatic_match' => 'boolean',
        'alternatives' => 'array',
        'status' => EpgMapCandidateStatus::class,
        'applied_at' => 'datetime',
    ];

    public function epgMap(): BelongsTo
    {
        return $this->belongsTo(EpgMap::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', EpgMapCandidateStatus::Pending);
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', EpgMapCandidateStatus::Applied);
    }

    public function prunable(): Builder
    {
        return static::query()
            ->where(function (Builder $q): void {
                $q->whereIn('status', [EpgMapCandidateStatus::Applied, EpgMapCandidateStatus::Skipped])
                    ->where('updated_at', '<', now()->subDays(30));
            })
            ->orWhere('updated_at', '<', now()->subDays(90));
    }
}
