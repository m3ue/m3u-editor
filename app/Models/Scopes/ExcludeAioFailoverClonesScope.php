<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Excludes the lightweight sibling Channel/Episode rows that
 * ResolveAioStreamsChannel/ResolveAioStreamsEpisode create purely to give
 * ChannelFailover/EpisodeFailover something real to point at for each
 * failover candidate. These rows duplicate the primary row's identity
 * (same title/season/episode_num/is_vod) and must never appear anywhere
 * content is listed for actual playback or browsing (Xtream API, M3U
 * export, VOD/series browse, the admin editor) — only the failover
 * mechanism itself needs to see them, and it opts back in explicitly via
 * withoutGlobalScope(self::class).
 */
class ExcludeAioFailoverClonesScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('is_aio_failover_clone'), false);
    }
}
