<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use Illuminate\Support\Collection;

/**
 * Builds a "virtual primary" channel from a selection of source channels.
 *
 * The custom channel has no URL of its own. The source channels are attached
 * as failovers, ranked by ChannelMergeScorer score. PlaylistUrlService falls
 * back to the first failover when a custom channel has an empty URL, so the
 * effective stream URL always tracks the highest-scoring source.
 *
 * Identity (title, logo, EPG mapping, group) is copied from the highest-scoring
 * source, which is also where the virtual primary is parented.
 */
class VirtualPrimaryCreator
{
    public function __construct(
        protected ChannelMergeScorer $scorer,
    ) {}

    /**
     * Score the supplied channels, create a virtual-primary custom channel from
     * the top scorer, and attach all sources as failovers in score order.
     *
     * @param  Collection<int, Channel>  $channels
     */
    public function create(Collection $channels, ?string $title = null, bool $disableSources = false): Channel
    {
        if ($channels->isEmpty()) {
            throw new \InvalidArgumentException('Cannot build a virtual primary from an empty selection.');
        }

        $scored = $channels->map(fn (Channel $channel) => [
            'channel' => $channel,
            'score' => $this->scorer->score($channel),
        ])->sortByDesc('score')->values();

        /** @var Channel $top */
        $top = $scored->first()['channel'];

        $resolvedTitle = $title !== null && $title !== ''
            ? $title
            : ($top->title_custom ?: $top->title ?: $top->name);

        $virtualPrimary = Channel::create([
            'user_id' => $top->user_id,
            'playlist_id' => $top->playlist_id,
            'group_id' => $top->group_id,
            'group' => $top->group,
            'is_custom' => true,
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

        foreach ($scored as $index => $row) {
            ChannelFailover::create([
                'user_id' => $virtualPrimary->user_id,
                'channel_id' => $virtualPrimary->id,
                'channel_failover_id' => $row['channel']->id,
                'sort' => $index,
            ]);
        }

        if ($disableSources) {
            Channel::whereIn('id', $channels->pluck('id')->all())->update(['enabled' => false]);
        }

        return $virtualPrimary->fresh();
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
