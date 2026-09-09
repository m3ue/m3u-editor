<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a "Migrate Provider" preview: a source (expired provider) Live channel, its
 * resolved match on the replacement playlist, and the reviewed EPG decision. Rows are
 * ephemeral - written when a preview is built and deleted once it is applied or rebuilt.
 *
 * @see \App\Filament\Resources\Playlists\Pages\MigrateProvider
 */
class ProviderMigrationPlanRow extends Model
{
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
