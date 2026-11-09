<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was last sent to one Trakt/SIMKL account for one progress row, so a
 * title is marked watched once per viewing instead of on every save.
 */
class WatchHistorySyncState extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'progress' => 'float',
            'pushed_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WatchHistoryIntegration::class, 'watch_history_integration_id');
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(ViewerWatchProgress::class, 'viewer_watch_progress_id');
    }
}
