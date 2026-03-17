<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Services\TitleNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Default priority attributes order (first = highest priority)
     */
    protected const DEFAULT_PRIORITY_ORDER = [
        'playlist_priority',
        'group_priority',
        'catchup_support',
        'resolution',
        'codec',
        'keyword_match',
    ];

    /**
     * Cached group priorities for performance
     */
    protected array $groupPriorityCache = [];

    /**
     * Cached disabled group IDs
     */
    protected array $disabledGroupIds = [];

    /**
     * Cache normalized priority order for scoring.
     *
     * @var array<int, string>|null
     */
    protected ?array $normalizedPriorityOrder = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public Collection $playlists,
        public int $playlistId,
        public bool $checkResolution = false,
        public bool $deactivateFailoverChannels = false,
        public bool $forceCompleteRemerge = false,
        public bool $preferCatchupAsPrimary = false,
        public ?int $groupId = null,
        public ?array $weightedConfig = null,
        public ?bool $newChannelsOnly = null,
        public bool $mergeByTitle = false,
        public float $titleSimilarityThreshold = 85.0,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;
        $deactivatedCount = 0;

        // Build unified playlist IDs array and create priority lookup
        $playlistIds = $this->playlists->map(function ($item) {
            return is_array($item) ? $item['playlist_failover_id'] : $item;
        })->values();

        if ($this->playlistId) {
            $playlistIds->prepend($this->playlistId); // Add preferred playlist at the beginning
        }

        $playlistIds = $playlistIds->unique()->values()->toArray();

        // Create playlist priority lookup for efficient sorting
        $playlistPriority = $playlistIds ? array_flip($playlistIds) : [];

        // Initialize caches for weighted priority system
        $this->initializeCaches($playlistIds);

        // Get existing failover channel IDs to exclude them from being masters
        $existingFailoverChannelIds = ChannelFailover::where('user_id', $this->user->id)
            ->whereHas('channelFailover', function ($query) use ($playlistIds) {
                $query->whereIn('playlist_id', $playlistIds);
            })
            ->pluck('channel_failover_id')
            ->toArray();

        // Get all channels with stream IDs in a single efficient query
        // Exclude channels that are already configured as failovers (unless we're re-merging everything)
        $shouldExcludeExistingFailovers = ! empty($existingFailoverChannelIds) && ! $this->forceCompleteRemerge;

        $allChannels = Channel::where([
            ['user_id', $this->user->id],
            ['can_merge', true],
        ])->whereIn('playlist_id', $playlistIds)
            ->where(function ($query) {
                $query->where('stream_id_custom', '!=', '')
                    ->orWhere('stream_id', '!=', '');
            })
            ->when($this->groupId, function ($query) {
                // Filter by group_id if specified
                $query->where('group_id', $this->groupId);
            })
            ->when($shouldExcludeExistingFailovers, function ($query) use ($existingFailoverChannelIds) {
                // Only exclude existing failovers if we're not forcing a complete re-merge
                $query->whereNotIn('id', $existingFailoverChannelIds);
            })
            ->when($this->newChannelsOnly, function ($query) {
                // Filter to only include new channels when newChannelsOnly is provided
                $query->where('new', true);
            })->cursor();

        // Group channels by stream ID using LazyCollection
        $groupedChannels = $allChannels->groupBy(function ($channel) {
            $streamId = $channel->stream_id_custom ?: $channel->stream_id;

            return strtolower(trim($streamId));
        });

        // Process each group of channels with the same stream ID
        foreach ($groupedChannels as $streamId => $group) {
            if ($group->count() <= 1) {
                continue; // Skip single channels
            }

            [$mergedCount, $deactivated] = $this->processChannelGroup($group, $playlistPriority);
            $processed += $mergedCount;
            $deactivatedCount += $deactivated;
        }

        // Title-based merge for VOD channels that weren't matched by stream_id
        if ($this->mergeByTitle) {
            [$titleMerged, $titleDeactivated] = $this->mergeVodByTitle($playlistIds, $playlistPriority, $shouldExcludeExistingFailovers ? $existingFailoverChannelIds : []);
            $processed += $titleMerged;
            $deactivatedCount += $titleDeactivated;
        }

        $this->sendCompletionNotification($processed, $deactivatedCount);
    }

    /**
     * Process a group of channels: select master and create failover relationships.
     *
     * @return array{0: int, 1: int} [processed count, deactivated count]
     */
    protected function processChannelGroup($group, array $playlistPriority): array
    {
        $processed = 0;
        $deactivatedCount = 0;

        $master = $this->selectMasterChannel($group, $playlistPriority);
        if (! $master) {
            return [0, 0];
        }

        if (! $master->enabled) {
            $master->update(['enabled' => true]);
        }

        $failoverChannels = $group->where('id', '!=', $master->id);
        $failoverChannels = $this->sortChannelsByScore($failoverChannels, $playlistPriority);

        $sortOrder = 1;
        foreach ($failoverChannels as $failover) {
            ChannelFailover::updateOrCreate(
                [
                    'channel_id' => $master->id,
                    'channel_failover_id' => $failover->id,
                ],
                [
                    'user_id' => $this->user->id,
                    'sort' => $sortOrder++,
                ]
            );

            if ($this->deactivateFailoverChannels && $failover->enabled) {
                $failover->update(['enabled' => false]);
                $deactivatedCount++;
            }

            $processed++;
        }

        return [$processed, $deactivatedCount];
    }

    /**
     * Merge VOD channels by title similarity when stream_ids don't match.
     *
     * Finds VOD channels that are not yet part of a failover group and
     * groups them by normalized title similarity.
     *
     * @return array{0: int, 1: int} [processed count, deactivated count]
     */
    protected function mergeVodByTitle(array $playlistIds, array $playlistPriority, array $excludeFailoverIds): array
    {
        $processed = 0;
        $deactivatedCount = 0;

        $normalizer = app(TitleNormalizer::class);

        // Get VOD channels that already have failover relationships (as master or failover)
        $alreadyMergedIds = ChannelFailover::where('user_id', $this->user->id)
            ->pluck('channel_id')
            ->merge(
                ChannelFailover::where('user_id', $this->user->id)->pluck('channel_failover_id')
            )
            ->unique()
            ->toArray();

        // Get unmerged VOD channels across the selected playlists
        $vodChannels = Channel::where([
            ['user_id', $this->user->id],
            ['can_merge', true],
            ['is_vod', true],
        ])
            ->whereIn('playlist_id', $playlistIds)
            ->when(! $this->forceCompleteRemerge && ! empty($alreadyMergedIds), function ($query) use ($alreadyMergedIds) {
                $query->whereNotIn('id', $alreadyMergedIds);
            })
            ->when(! $this->forceCompleteRemerge && ! empty($excludeFailoverIds), function ($query) use ($excludeFailoverIds) {
                $query->whereNotIn('id', $excludeFailoverIds);
            })
            ->when($this->groupId, function ($query) {
                $query->where('group_id', $this->groupId);
            })
            ->when($this->newChannelsOnly, function ($query) {
                $query->where('new', true);
            })
            ->get();

        if ($vodChannels->count() < 2) {
            return [0, 0];
        }

        // Build items array for the normalizer
        $items = $vodChannels->map(fn (Channel $ch) => [
            'id' => $ch->id,
            'title' => $ch->title_custom ?? $ch->title ?? $ch->name_custom ?? $ch->name ?? '',
        ])->filter(fn ($item) => $item['title'] !== '')->values()->toArray();

        $groups = $normalizer->groupBySimilarity($items, $this->titleSimilarityThreshold);

        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }

            // Collect the actual Channel models for this group
            $groupIds = collect($group)->pluck('id')->toArray();
            $channelGroup = $vodChannels->whereIn('id', $groupIds);

            if ($channelGroup->count() <= 1) {
                continue;
            }

            [$mergedCount, $deactivated] = $this->processChannelGroup($channelGroup, $playlistPriority);
            $processed += $mergedCount;
            $deactivatedCount += $deactivated;
        }

        return [$processed, $deactivatedCount];
    }

    /**
     * Initialize caches for group priorities and disabled groups
     */
    protected function initializeCaches(array $playlistIds): void
    {
        // Cache group priorities from config
        $configGroupPriorities = $this->weightedConfig['group_priorities'] ?? [];
        foreach ($configGroupPriorities as $priority) {
            $this->groupPriorityCache[(int) $priority['group_id']] = (int) $priority['weight'];
        }

        // Cache disabled group IDs if exclude_disabled_groups is enabled
        if ($this->weightedConfig['exclude_disabled_groups'] ?? false) {
            $this->disabledGroupIds = Group::whereIn('playlist_id', $playlistIds)
                ->where('enabled', false)
                ->pluck('id')
                ->toArray();
        }
    }

    /**
     * Select the master channel from a group based on weighted priority scoring
     */
    protected function selectMasterChannel($group, array $playlistPriority)
    {
        // Filter out channels from disabled groups if enabled
        $eligibleGroup = $this->filterDisabledGroups($group);

        if ($eligibleGroup->isEmpty()) {
            // Fallback to original group if all were filtered
            $eligibleGroup = $group;
        }

        // Use weighted priority system if config provided
        if ($this->weightedConfig !== null) {
            return $this->selectMasterByWeightedScore($eligibleGroup, $playlistPriority);
        }

        // Legacy selection logic for backward compatibility
        return $this->selectMasterLegacy($eligibleGroup, $playlistPriority);
    }

    /**
     * Filter out channels from disabled groups
     */
    protected function filterDisabledGroups($group)
    {
        if (empty($this->disabledGroupIds)) {
            return $group;
        }

        return $group->filter(function ($channel) {
            return ! in_array($channel->group_id, $this->disabledGroupIds);
        });
    }

    /**
     * Select master channel using weighted scoring system
     */
    protected function selectMasterByWeightedScore($group, array $playlistPriority)
    {
        // Enforce prefer catch-up as primary if enabled
        if ($this->preferCatchupAsPrimary) {
            $catchupChannels = $group->filter(fn ($channel) => ! empty($channel->catchup));
            if ($catchupChannels->isNotEmpty()) {
                $group = $catchupChannels;
            }
        }

        $scoredChannels = $group->map(function ($channel) use ($playlistPriority) {
            return [
                'channel' => $channel,
                'score' => $this->calculateChannelScore($channel, $playlistPriority),
            ];
        });

        // Get highest score
        $maxScore = $scoredChannels->max('score');

        // Get all channels with the highest score
        $topChannels = $scoredChannels->where('score', $maxScore)->pluck('channel');

        // If preferred playlist is set, try to use it among top scorers
        if ($this->playlistId) {
            $preferredTop = $topChannels->where('playlist_id', $this->playlistId);
            if ($preferredTop->isNotEmpty()) {
                return $preferredTop->sortBy('sort')->first();
            }
        }

        // Return first top scorer (sorted by sort order for consistency)
        return $topChannels->sortBy('sort')->first();
    }

    /**
     * Calculate weighted score for a channel
     */
    protected function calculateChannelScore($channel, array $playlistPriority): int
    {
        $score = 0;
        $priorityOrder = $this->getPriorityOrder();

        // Base multiplier decreases for each priority level
        $multiplier = count($priorityOrder) * 1000;

        foreach ($priorityOrder as $attribute) {
            $attributeScore = match ($attribute) {
                'playlist_priority' => $this->getPlaylistPriorityScore($channel, $playlistPriority),
                'group_priority' => $this->getGroupPriorityScore($channel),
                'catchup_support' => $this->getCatchupScore($channel),
                'resolution' => $this->getResolutionScore($channel),
                'codec' => $this->getCodecScore($channel),
                'keyword_match' => $this->getKeywordScore($channel),
                default => 0,
            };

            $score += $attributeScore * $multiplier;
            $multiplier = max(1, $multiplier - 1000);
        }

        return $score;
    }

    /**
     * Get normalized priority order from config.
     *
     * Supports both string array format:
     * ['playlist_priority', 'resolution']
     * and object array format:
     * [['attribute' => 'playlist_priority'], ['attribute' => 'resolution']]
     *
     * @return array<int, string>
     */
    protected function getPriorityOrder(): array
    {
        if ($this->normalizedPriorityOrder !== null) {
            return $this->normalizedPriorityOrder;
        }

        $allowed = array_flip(self::DEFAULT_PRIORITY_ORDER);
        $raw = $this->weightedConfig['priority_attributes'] ?? self::DEFAULT_PRIORITY_ORDER;

        if (! is_array($raw) || empty($raw)) {
            $this->normalizedPriorityOrder = self::DEFAULT_PRIORITY_ORDER;

            return $this->normalizedPriorityOrder;
        }

        $normalized = [];
        foreach ($raw as $item) {
            $attribute = is_array($item) ? ($item['attribute'] ?? null) : $item;
            if (! is_string($attribute)) {
                continue;
            }

            $attribute = trim($attribute);
            if ($attribute === '' || ! isset($allowed[$attribute])) {
                continue;
            }

            $normalized[] = $attribute;
        }

        $normalized = array_values(array_unique($normalized));
        $this->normalizedPriorityOrder = ! empty($normalized) ? $normalized : self::DEFAULT_PRIORITY_ORDER;

        return $this->normalizedPriorityOrder;
    }

    /**
     * Get playlist priority score (higher = better)
     */
    protected function getPlaylistPriorityScore($channel, array $playlistPriority): int
    {
        // Invert priority so lower index = higher score
        $priority = $playlistPriority[$channel->playlist_id] ?? 999;

        return max(0, 100 - $priority);
    }

    /**
     * Get group priority score from config (higher = better)
     */
    protected function getGroupPriorityScore($channel): int
    {
        return $this->groupPriorityCache[$channel->group_id] ?? 0;
    }

    /**
     * Get catchup support score
     */
    protected function getCatchupScore($channel): int
    {
        // Higher score if channel has catchup/replay
        return ! empty($channel->catchup) ? 100 : 0;
    }

    /**
     * Get resolution score (normalized 0-100)
     */
    protected function getResolutionScore($channel): int
    {
        $resolution = $this->getResolution($channel);

        // Normalize: 4K (3840x2160 = 8294400) = 100, 1080p = ~25, 720p = ~11
        return min(100, (int) ($resolution / 82944));
    }

    /**
     * Get codec preference score
     */
    protected function getCodecScore($channel): int
    {
        $preferredCodec = $this->weightedConfig['prefer_codec'] ?? null;
        if (! $preferredCodec) {
            return 0;
        }

        $channelCodec = $this->getCodec($channel);
        if (! $channelCodec) {
            return 0;
        }

        $preferredCodec = strtolower($preferredCodec);
        $channelCodec = strtolower($channelCodec);

        // Check for HEVC/H265 preference
        if ($preferredCodec === 'hevc' || $preferredCodec === 'h265') {
            return (str_contains($channelCodec, 'hevc') || str_contains($channelCodec, 'h265') || str_contains($channelCodec, '265')) ? 100 : 0;
        }

        // Check for H264/AVC preference
        if ($preferredCodec === 'h264' || $preferredCodec === 'avc') {
            return (str_contains($channelCodec, 'h264') || str_contains($channelCodec, 'avc') || str_contains($channelCodec, '264')) ? 100 : 0;
        }

        return 0;
    }

    /**
     * Get keyword match score
     */
    protected function getKeywordScore($channel): int
    {
        $keywords = $this->weightedConfig['priority_keywords'] ?? [];
        if (empty($keywords)) {
            return 0;
        }

        $channelName = strtolower($channel->title ?? $channel->name ?? '');
        $matchCount = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($channelName, strtolower($keyword))) {
                $matchCount++;
            }
        }

        // More matches = higher score (cap at 100)
        return min(100, $matchCount * 25);
    }

    /**
     * Get codec from channel stream stats
     */
    protected function getCodec($channel): ?string
    {
        $streamStats = $channel->stream_stats ?? [];
        foreach ($streamStats as $stream) {
            if (isset($stream['stream']['codec_type']) && $stream['stream']['codec_type'] === 'video') {
                return $stream['stream']['codec_name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Sort channels by score (descending)
     */
    protected function sortChannelsByScore($channels, array $playlistPriority)
    {
        if ($this->weightedConfig !== null) {
            return $channels->sortBy(fn ($channel) => [
                -$this->calculateChannelScore($channel, $playlistPriority),
                $channel->sort ?? 999999,
            ]);
        }

        // Legacy sorting
        if ($this->checkResolution) {
            return $channels->sortBy(fn ($channel) => [
                $this->preferCatchupAsPrimary && empty($channel->catchup) ? 1 : 0,
                -(int) $this->getResolution($channel),
                (int) ($playlistPriority[$channel->playlist_id] ?? 999),
                $channel->sort ?? 999999,
            ]);
        }

        return $channels->sortBy(fn ($channel) => [
            $this->preferCatchupAsPrimary && empty($channel->catchup) ? 1 : 0,
            (int) ($playlistPriority[$channel->playlist_id] ?? 999),
            $channel->sort ?? 999999,
        ]);
    }

    /**
     * Legacy master selection for backward compatibility
     */
    protected function selectMasterLegacy($group, array $playlistPriority)
    {
        $selectionGroup = $group->when($this->preferCatchupAsPrimary, function ($group) {
            $catchupChannels = $group->filter(fn ($channel) => ! empty($channel->catchup));

            return $catchupChannels->isNotEmpty() ? $catchupChannels : $group;
        });

        if ($this->checkResolution) {
            // Resolution-based selection: Find channel(s) with highest resolution
            $channelsWithResolution = $selectionGroup->map(function ($channel) {
                return [
                    'channel' => $channel,
                    'resolution' => $this->getResolution($channel),
                ];
            });

            $maxResolution = $channelsWithResolution->max('resolution');
            $highestResChannels = $channelsWithResolution->where('resolution', $maxResolution)->pluck('channel');

            // If preferred playlist is set, prioritize it among highest resolution channels
            if ($this->playlistId) {
                $preferredHighRes = $highestResChannels->where('playlist_id', $this->playlistId);
                if ($preferredHighRes->isNotEmpty()) {
                    return $preferredHighRes->sortBy('sort')->first();
                }
            }

            return $highestResChannels->sortBy('sort')->first();
        } else {
            // Simple selection without resolution check
            if ($this->playlistId) {
                $preferredChannels = $selectionGroup->where('playlist_id', $this->playlistId);
                if ($preferredChannels->isNotEmpty()) {
                    return $preferredChannels->sortBy('sort')->first();
                }
            }

            return $selectionGroup->sortBy(fn ($channel) => [
                (int) ($playlistPriority[$channel->playlist_id] ?? 999),
                $channel->sort ?? 999999,
            ])->first();
        }
    }

    /**
     * Get resolution from channel stream stats
     */
    protected function getResolution($channel): int
    {
        $streamStats = $channel->stream_stats ?? [];
        foreach ($streamStats as $stream) {
            if (isset($stream['stream']['codec_type']) && $stream['stream']['codec_type'] === 'video') {
                return ($stream['stream']['width'] ?? 0) * ($stream['stream']['height'] ?? 0);
            }
        }

        return 0;
    }

    protected function sendCompletionNotification($processed, $deactivatedCount = 0)
    {
        if ($processed > 0) {
            $body = "Merged {$processed} channels successfully.";
            if ($deactivatedCount > 0) {
                $body .= " {$deactivatedCount} failover channels were deactivated.";
            }
        } else {
            $body = 'No channels were merged.';
        }

        Notification::make()
            ->title('Channel merge complete')
            ->body($body)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
