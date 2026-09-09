<?php

namespace App\Models;

use App\Filament\Resources\Playlists\Pages\MigrateProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a "Migrate Provider" preview: a source (expired provider) Live channel, its
 * resolved match on the replacement playlist, and the reviewed EPG decision. Rows are
 * ephemeral - written when a preview is built and deleted once it is applied or rebuilt.
 * Anything left behind is swept on the next page visit and by the daily model:prune.
 *
 * @see MigrateProvider
 */
class ProviderMigrationPlanRow extends Model
{
    use Prunable;

    /**
     * Aggressively prune abandoned previews. The page also sweeps stale rows on every visit.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(6));
    }

    protected $casts = [
        'candidate_target_channel_ids' => 'array',
        'include' => 'boolean',
        'epg_confirmed' => 'boolean',
        'user_id' => 'integer',
        'source_playlist_id' => 'integer',
        'target_playlist_id' => 'integer',
        'source_channel_id' => 'integer',
        'suggested_target_channel_id' => 'integer',
        'matched_target_channel_id' => 'integer',
        'epg_channel_id' => 'integer',
    ];

    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'source_channel_id');
    }

    public function matchedTargetChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'matched_target_channel_id');
    }
}
