<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use Illuminate\Support\Collection;

/**
 * Builds a "smart channel" from a selection of source channels.
 *
 * The custom channel has no URL of its own. The source channels are attached
 * as failovers, ranked by ChannelMergeScorer score. PlaylistUrlService falls
 * back to the first failover when a custom channel has an empty URL, so the
 * effective stream URL always tracks the highest-scoring source.
 *
 * Identity (title, logo, EPG mapping, group) is copied from the highest-scoring
 * source, which is also where the smart channel is parented.
 */
class SmartChannelCreator
{
    public function __construct(
        protected ChannelMergeScorer $scorer,
    ) {}

    /**
     * Score the supplied channels, create a custom smart channel from the top
     * scorer, and attach all sources as failovers in score order.
     *
     * Score breakdowns are persisted to channel_failovers.metadata so the
     * rationale stays inspectable later (e.g. via UI or DB query).
     *
     * @param  Collection<int, Channel>  $channels
     */
    public function create(Collection $channels, ?string $title = null, bool $disableSources = false): Channel
    {
        if ($channels->isEmpty()) {
            throw new \InvalidArgumentException('Cannot build a smart channel from an empty selection.');
        }

        if ($channels->contains(fn (Channel $channel) => (bool) $channel->is_smart_channel)) {
            throw new \InvalidArgumentException('Smart channels cannot be used as sources for another smart channel — pick raw provider channels instead.');
        }

        if ($channels->pluck('playlist_id')->unique()->count() > 1) {
            throw new \InvalidArgumentException('All sources for a smart channel must belong to the same playlist.');
        }

        $ranking = $this->rank($channels);

        /** @var Channel $top */
        $top = $ranking->first()['channel'];

        $resolvedTitle = $title !== null && $title !== ''
            ? $title
            : ($top->title_custom ?: $top->title ?: $top->name);

        $smartChannel = Channel::create([
            'user_id' => $top->user_id,
            'playlist_id' => $top->playlist_id,
            'group_id' => $top->group_id,
            'group' => $top->group,
            'is_custom' => true,
            'is_smart_channel' => true,
            'enabled' => true,
            'url' => null,
            'title' => $resolvedTitle,
            'name' => $resolvedTitle,
            'logo' => $top->logo,
            'logo_internal' => $top->logo_internal ?? $top->logo,
            'epg_channel_id' => $top->epg_channel_id,
            'channel' => $top->channel,
            'shift' => $top->shift ?? 0,
            'is_vod' => false,
        ]);

        $rankedAt = now()->toIso8601String();
        foreach ($ranking as $index => $row) {
            ChannelFailover::create([
                'user_id' => $smartChannel->user_id,
                'channel_id' => $smartChannel->id,
                'channel_failover_id' => $row['channel']->id,
                'sort' => $index,
                'metadata' => [
                    'score' => $row['score'],
                    'attribute_scores' => $row['breakdown'],
                    'priority_order' => array_keys($row['breakdown']),
                    'ranked_at' => $rankedAt,
                ],
            ]);
        }

        if ($disableSources) {
            Channel::whereIn('id', $channels->pluck('id')->all())->update(['enabled' => false]);
        }

        return $smartChannel->fresh();
    }

    /**
     * Score and rank the supplied channels, returning each with its score and
     * per-attribute breakdown. Same logic as create() uses internally — exposed
     * so callers (e.g. bulk-action modals) can preview the ranking before
     * committing to creating the smart channel.
     *
     * @param  Collection<int, Channel>  $channels
     * @return Collection<int, array{channel: Channel, score: int, breakdown: array<string, int>}>
     */
    public function rank(Collection $channels): Collection
    {
        return $channels
            ->map(fn (Channel $channel) => [
                'channel' => $channel,
                'score' => $this->scorer->score($channel),
                'breakdown' => $this->scorer->scoreBreakdown($channel),
            ])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Build a creator using a playlist's auto_merge_config (or a sensible
     * default of [resolution, fps, bitrate, codec] when no config is set).
     */
    public static function fromPlaylist(?Playlist $playlist): self
    {
        $config = $playlist?->auto_merge_config ?? [];

        $rawAttributes = $config['priority_attributes'] ?? null;
        $priorityOrder = empty($rawAttributes)
            ? ['resolution', 'fps', 'bitrate', 'codec']
            : ChannelMergeScorer::normalizePriorityOrder($rawAttributes);

        $groupPriorityCache = [];
        foreach ($config['group_priorities'] ?? [] as $group) {
            if (isset($group['group_id'], $group['weight'])) {
                $groupPriorityCache[(int) $group['group_id']] = (int) $group['weight'];
            }
        }

        return new self(
            new ChannelMergeScorer(
                priorityOrder: $priorityOrder,
                playlistPriority: $playlist ? [$playlist->id => 0] : [],
                groupPriorityCache: $groupPriorityCache,
                preferredCodec: $config['prefer_codec'] ?? null,
                priorityKeywords: $config['priority_keywords'] ?? [],
            )
        );
    }
}
