<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable set of AIOStreams auto-selection rules (preferred resolutions and
 * audio languages, exclusions, size limits, ...), applied by
 * AioStreamsQualityParser::rank() when picking a title's streams.
 *
 * Assigned per integration (a default for movies and one for series) and
 * optionally per title (channels/series `aio_match_profile_id`), see
 * AioStreamsCandidateService::profileFor().
 *
 * @property array<string, mixed>|null $rules
 */
class AioStreamsMatchProfile extends Model
{
    use HasFactory;

    protected $casts = [
        'rules' => 'array',
    ];

    protected static function booted(): void
    {
        // The per-title override columns carry no foreign key (see the
        // add_aio_match_profile_columns migration), so clear them here. Scoped
        // to the owner, whose rows are the only ones that can reference it.
        static::deleting(function (self $profile): void {
            Channel::withoutGlobalScopes()
                ->where('user_id', $profile->user_id)
                ->where('aio_match_profile_id', $profile->id)
                ->update(['aio_match_profile_id' => null]);

            Series::query()
                ->where('user_id', $profile->user_id)
                ->where('aio_match_profile_id', $profile->id)
                ->update(['aio_match_profile_id' => null]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        return $this->rules ?? [];
    }
}
