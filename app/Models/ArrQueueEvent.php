<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrQueueEvent extends Model
{
    use HasFactory;
    use MassPrunable;

    public function prunable(): Builder
    {
        // CompletedSnapshots are transient display aids — prune after 48 h.
        // Real webhook events (Grab, Download, Import, etc.) are kept for 30 days.
        return static::query()->where(function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->where('event_type', 'CompletedSnapshot')
                    ->where('last_event_at', '<', now()->subHours(48));
            })->orWhere(function (Builder $inner) {
                $inner->where('event_type', '!=', 'CompletedSnapshot')
                    ->where('last_event_at', '<', now()->subDays(30));
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_event_at' => 'datetime',
            'size' => 'integer',
            'progress' => 'integer',
        ];
    }

    public function arrIntegration(): BelongsTo
    {
        return $this->belongsTo(ArrIntegration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
