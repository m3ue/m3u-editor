<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the channels behind EPG programmes for DVR scheduling and Browse Shows.
 *
 * epg_programmes.epg_channel_id is the raw XMLTV channel string (e.g. "BBCTwo.uk"),
 * which is only unique within a single EPG. Different EPGs routinely reuse the same
 * string, so every lookup is scoped by the programme's epg_id - otherwise a programme
 * can resolve to a channel (or display name) from an unrelated EPG.
 */
class EpgProgrammeChannelResolver
{
    /**
     * Find a programme by id, limited to EPGs owned by the given user so a
     * foreign programme id can't be used to create a rule.
     */
    public function findOwnedProgramme(int $programmeId, int $userId): ?EpgProgramme
    {
        return EpgProgramme::whereIn('epg_id', Epg::where('user_id', $userId)->select('id'))
            ->find($programmeId);
    }

    /**
     * Find the channel within the given owner scope that carries the programme.
     *
     * Prefers channels mapped to the programme's EPG channel, then falls back to
     * channels whose tvg-id (stream_id) matches the XMLTV channel string directly -
     * Browse Shows lists programmes for both, so recording must resolve both.
     * Enabled channels win over disabled duplicates.
     *
     * @param  Builder  $ownerChannelsSubquery  Subquery selecting channels.id, scoped to the owner
     * @param  list<string>  $columns
     */
    public function channelForProgramme(EpgProgramme $programme, Builder $ownerChannelsSubquery, array $columns = ['*']): ?Channel
    {
        if (! $programme->epg_channel_id) {
            return null;
        }

        $mapped = Channel::whereIn('id', clone $ownerChannelsSubquery)
            ->whereIn('epg_channel_id', EpgChannel::select('id')
                ->where('epg_id', $programme->epg_id)
                ->where('channel_id', $programme->epg_channel_id))
            ->orderByDesc('enabled')
            ->first($columns);

        if ($mapped) {
            return $mapped;
        }

        return Channel::whereIn('id', clone $ownerChannelsSubquery)
            ->where('stream_id', $programme->epg_channel_id)
            ->orderByDesc('enabled')
            ->first($columns);
    }

    /**
     * Limit a programme query to what the given channels carry: programmes on each
     * mapped channel's own EPG channel (matched on epg_id + XMLTV id), plus, for
     * channels with no EPG mapping, programmes whose XMLTV id equals the channel's
     * tvg-id (stream_id). A mapped channel's tvg-id is deliberately not matched as
     * well - it is usually the same XMLTV string, and would pull the same channel's
     * programmes back in from every other EPG that reuses it (#1827).
     *
     * @param  QueryBuilderContract  $channels  Query over the channels table with the caller's filters applied
     */
    public function scopeToChannels(Builder $programmes, QueryBuilderContract $channels): Builder
    {
        return $programmes->where(fn (Builder $scoped) => $scoped
            ->onEpgChannels((clone $channels)
                ->whereNotNull('channels.epg_channel_id')
                ->select('channels.epg_channel_id'))
            ->orWhereIn('epg_programmes.epg_channel_id', (clone $channels)
                ->whereNull('channels.epg_channel_id')
                ->whereNotNull('channels.stream_id')
                ->where('channels.stream_id', '!=', '')
                ->select('channels.stream_id')));
    }

    /**
     * Whether any of the given channels can be matched to guide data, either by an
     * EPG mapping or a tvg-id (stream_id).
     *
     * @param  QueryBuilderContract  $channels  Query over the channels table with the caller's filters applied
     */
    public function hasGuideChannels(QueryBuilderContract $channels): bool
    {
        return (clone $channels)
            ->where(fn (QueryBuilderContract $guide) => $guide
                ->whereNotNull('channels.epg_channel_id')
                ->orWhere(fn (QueryBuilderContract $tvg) => $tvg
                    ->whereNotNull('channels.stream_id')
                    ->where('channels.stream_id', '!=', '')))
            ->exists();
    }

    /**
     * EPG channel display names for the given programmes, keyed by nameKey().
     *
     * @param  Collection<int, EpgProgramme>  $programmes
     * @return array<string, string>
     */
    public function channelNames(Collection $programmes): array
    {
        $programmes = $programmes->filter(fn (EpgProgramme $p) => $p->epg_channel_id);

        if ($programmes->isEmpty()) {
            return [];
        }

        return EpgChannel::without('epg')
            ->whereIn('epg_id', $programmes->pluck('epg_id')->unique()->values()->all())
            ->whereIn('channel_id', $programmes->pluck('epg_channel_id')->unique()->values()->all())
            ->get(['epg_id', 'channel_id', 'name', 'display_name', 'name_custom', 'display_name_custom'])
            ->mapWithKeys(fn (EpgChannel $c) => [
                self::nameKey($c->epg_id, $c->channel_id) => $c->name_custom
                    ?: $c->display_name_custom
                    ?: $c->display_name
                    ?: $c->name,
            ])
            ->all();
    }

    /**
     * Look up a programme's channel name in a channelNames() map, falling back to
     * the raw XMLTV channel string.
     *
     * @param  array<string, string>  $channelNames
     */
    public static function nameFor(array $channelNames, EpgProgramme $programme): ?string
    {
        return $channelNames[self::nameKey($programme->epg_id, $programme->epg_channel_id)]
            ?? $programme->epg_channel_id;
    }

    private static function nameKey(?int $epgId, ?string $channelId): string
    {
        return $epgId.'|'.$channelId;
    }
}
