<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Models\Playlist;
use App\Services\Channels\ChannelMergeScorer;
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
     * Default priority attributes order (first = highest priority).
     *
     * @deprecated Use {@see ChannelMergeScorer::DEFAULT_PRIORITY_ORDER}. Kept here for legacy callers.
     */
    protected const DEFAULT_PRIORITY_ORDER = ChannelMergeScorer::DEFAULT_PRIORITY_ORDER;

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
        public ?array $regexPatterns = null,
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
            $playlistIds->prepend($this->playlistId);
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

            // Select master channel based on weighted priority or legacy criteria
            $master = $this->selectMasterChannel($group, $playlistPriority);
            if (! $master) {
                continue; // Skip if no valid master found
            }

            // Ensure master is enabled in case it was previously disabled,
            // but never silently re-enable a master that lives in a disabled group
            // when the user opted in to exclude_disabled_groups.
            if (! $master->enabled && ! in_array($master->group_id, $this->disabledGroupIds, true)) {
                $master->update(['enabled' => true]);
            }

            // Create failover relationships for remaining channels
            $failoverChannels = $group->where('id', '!=', $master->id);

            // Sort failovers using the same scoring system (descending score)
            $failoverChannels = $this->sortChannelsByScore($failoverChannels, $playlistPriority);

            // Create failover relationships using updateOrCreate for compatibility
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

                // Deactivate failover channel if requested
                if ($this->deactivateFailoverChannels && $failover->enabled) {
                    $failover->update(['enabled' => false]);
                    $deactivatedCount++;
                }

                $processed++;
            }
        }

        // Process playlist-level regex merge patterns (second pass)
        $regexResults = $this->processRegexMerges($playlistIds, $playlistPriority);
        $processed += $regexResults['processed'];
        $deactivatedCount += $regexResults['deactivated'];

        $this->sendCompletionNotification($processed, $deactivatedCount);
    }

    /**
     * Process regex-based merge matching using patterns configured on the primary playlist.
     * Each pattern groups all matching channels together, picking the highest-scoring as master.
     *
     * @param  array<int>  $playlistIds
     * @param  array<int, int>  $playlistPriority
     * @return array{processed: int, deactivated: int}
     */
    protected function processRegexMerges(array $playlistIds, array $playlistPriority): array
    {
        $patterns = $this->regexPatterns ?? [];

        if (empty($patterns)) {
            // If patterns not set directly, check if playlist has auto_merge_config with regex_patterns
            $playlist = Playlist::find($this->playlistId);
            if ($playlist) {
                $patterns = $playlist->auto_merge_config['regex_patterns'] ?? [];
            }
            if (empty($patterns)) {
                return ['processed' => 0, 'deactivated' => 0];
            }
        }

        // Validate all patterns up-front so the chunk loop never calls @preg_match
        $validPatterns = array_values(
            array_filter($patterns, fn ($p) => @preg_match($p, '') !== false)
        );

        if (empty($validPatterns)) {
            return ['processed' => 0, 'deactivated' => 0];
        }

        // Single DB pass: chunk channels and test every pattern in one sweep.
        // Memory is bounded by the chunk size regardless of catalogue size.
        $matchesByPattern = array_fill(0, count($validPatterns), []);

        Channel::where('user_id', $this->user->id)
            ->where('can_merge', true)
            ->whereIn('playlist_id', $playlistIds)
            ->when($this->groupId, fn ($q) => $q->where('group_id', $this->groupId))
            ->select(['id', 'user_id', 'playlist_id', 'group_id', 'title', 'title_custom',
                'name', 'name_custom', 'stream_id', 'stream_id_custom', 'sort',
                'catchup', 'enabled'])
            ->chunk(500, function ($chunk) use ($validPatterns, &$matchesByPattern) {
                foreach ($chunk as $channel) {
                    $title = $channel->title_custom ?: $channel->title;
                    $name = $channel->name_custom ?: $channel->name;

                    foreach ($validPatterns as $i => $pattern) {
                        if (preg_match($pattern, $title) === 1 || preg_match($pattern, $name) === 1) {
                            $matchesByPattern[$i][] = $channel;
                        }
                    }
                }
            });

        $processed = 0;
        $deactivatedCount = 0;

        foreach ($validPatterns as $i => $pattern) {
            $matches = collect($matchesByPattern[$i]);

            if ($matches->count() <= 1) {
                continue;
            }

            $sorted = $this->sortChannelsByScore($matches, $playlistPriority);
            $master = $sorted->first();
            $maxSort = ChannelFailover::where('channel_id', $master->id)->max('sort') ?? 0;
            $sortOrder = $maxSort + 1;

            foreach ($sorted->skip(1) as $failover) {
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
        }

        return ['processed' => $processed, 'deactivated' => $deactivatedCount];
    }

    /**
     * Initialize caches for group priorities and disabled groups.
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
     * Select the master channel from a group based on weighted priority scoring.
     */
    protected function selectMasterChannel(Collection $group, array $playlistPriority): ?Channel
    {
        // Filter out channels from disabled groups if enabled.
        // When the user opted in to exclude_disabled_groups, we must NOT fall back
        // to the unfiltered group: that would silently pick (and later re-enable)
        // a channel from a disabled group as master, which is exactly what the
        // option is supposed to prevent.
        $eligibleGroup = $this->filterDisabledGroups($group);

        if ($eligibleGroup->isEmpty()) {
            return null;
        }

        // Use weighted priority system if config provided
        if ($this->weightedConfig !== null) {
            return $this->selectMasterByWeightedScore($eligibleGroup, $playlistPriority);
        }

        // Legacy selection logic for backward compatibility
        return $this->selectMasterLegacy($eligibleGroup, $playlistPriority);
    }

    /**
     * Filter out channels from disabled groups.
     */
    protected function filterDisabledGroups(Collection $group): Collection
    {
        if (empty($this->disabledGroupIds)) {
            return $group;
        }

        return $group->filter(function ($channel) {
            return ! in_array($channel->group_id, $this->disabledGroupIds);
        });
    }

    /**
     * Select master channel using weighted scoring system.
     */
    protected function selectMasterByWeightedScore(Collection $group, array $playlistPriority): ?Channel
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
     * Build a fresh scorer using this job's weighted config and the supplied playlist priority lookup.
     */
    protected function buildScorer(array $playlistPriority): ChannelMergeScorer
    {
        return new ChannelMergeScorer(
            priorityOrder: $this->getPriorityOrder(),
            playlistPriority: $playlistPriority,
            groupPriorityCache: $this->groupPriorityCache,
            preferredCodec: $this->weightedConfig['prefer_codec'] ?? null,
            priorityKeywords: $this->weightedConfig['priority_keywords'] ?? [],
        );
    }

    /**
     * Calculate weighted score for a channel.
     */
    protected function calculateChannelScore(Channel $channel, array $playlistPriority): int
    {
        return $this->buildScorer($playlistPriority)->score($channel);
    }

    /**
     * Get normalized priority order from config.
     *
     * @return array<int, string>
     */
    protected function getPriorityOrder(): array
    {
        if ($this->normalizedPriorityOrder !== null) {
            return $this->normalizedPriorityOrder;
        }

        $this->normalizedPriorityOrder = ChannelMergeScorer::normalizePriorityOrder(
            $this->weightedConfig['priority_attributes'] ?? null
        );

        return $this->normalizedPriorityOrder;
    }

    /**
     * Sort channels by score (descending).
     */
    protected function sortChannelsByScore(Collection $channels, array $playlistPriority): Collection
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
                -(int) ChannelMergeScorer::getResolution($channel),
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
     * Legacy master selection for backward compatibility.
     */
    protected function selectMasterLegacy(Collection $group, array $playlistPriority): ?Channel
    {
        $selectionGroup = $group->when($this->preferCatchupAsPrimary, function ($group) {
            $catchupChannels = $group->filter(fn ($channel) => ! empty($channel->catchup));

            return $catchupChannels->isNotEmpty() ? $catchupChannels : $group;
        });

        if ($this->checkResolution) {
            $channelsWithResolution = $selectionGroup->map(function ($channel) {
                return [
                    'channel' => $channel,
                    'resolution' => ChannelMergeScorer::getResolution($channel),
                ];
            });

            $maxResolution = $channelsWithResolution->max('resolution');
            $highestResChannels = $channelsWithResolution->where('resolution', $maxResolution)->pluck('channel');

            if ($this->playlistId) {
                $preferredHighRes = $highestResChannels->where('playlist_id', $this->playlistId);
                if ($preferredHighRes->isNotEmpty()) {
                    return $preferredHighRes->sortBy('sort')->first();
                }
            }

            return $highestResChannels->sortBy('sort')->first();
        }

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

    protected function sendCompletionNotification(int $processed, int $deactivatedCount = 0): void
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
