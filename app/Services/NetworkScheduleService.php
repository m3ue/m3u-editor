<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service to handle network schedule generation
 */
class NetworkScheduleService
{
    /**
     * Default schedule generation window in days.
     */
    protected const DEFAULT_SCHEDULE_WINDOW_DAYS = 7;

    /**
     * Minimum content duration in seconds (fallback for missing duration).
     */
    protected int $minimumDurationSeconds = 1800; // 30 minutes

    /**
     * Generate the programme schedule for a network.
     *
     * @param  bool  $forceReset  When true, clears all existing programmes and starts fresh from content index 0.
     *                            When false (default), continues from the current position in the content sequence.
     */
    public function generateSchedule(Network $network, ?Carbon $startFrom = null, bool $forceReset = false): int
    {
        // Manual schedules are managed by the Schedule Builder UI, not auto-generated
        if ($network->schedule_type === 'manual') {
            Log::info("Skipping auto-generation for manual schedule network {$network->name}");

            return 0;
        }

        $startFrom = $startFrom ?? Carbon::now();
        $scheduleWindowDays = $network->schedule_window_days ?? self::DEFAULT_SCHEDULE_WINDOW_DAYS;
        $endAt = $startFrom->copy()->addDays($scheduleWindowDays);

        Log::info("Generating schedule for network {$network->name}", [
            'network_id' => $network->id,
            'start_from' => $startFrom->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
            'force_reset' => $forceReset,
        ]);

        // Get all content for this network
        $allNetworkContent = $network->networkContent()->with('contentable')->get();
        $contentItems = $this->getOrderedContent($network, $forceReset);

        if ($contentItems->isEmpty() && $allNetworkContent->filter(fn ($nc) => $nc->isPinned())->isEmpty()) {
            Log::warning("No content found for network {$network->name}");

            return 0;
        }

        // Force reset: wipe everything and start from index 0, ignoring continuity
        if ($forceReset) {
            $startingContentIndex = 0;
            $currentlyAiring = null;
        } else {
            // Determine the starting content index BEFORE deleting anything.
            // Look for the most recent programme to determine where we should continue from.
            $startingContentIndex = $this->determineStartingContentIndex($network, $contentItems, $startFrom);

            // Check if there's a currently airing programme - if so, we should skip to its end
            $currentlyAiring = $network->programmes()
                ->where('start_time', '<=', $startFrom)
                ->where('end_time', '>', $startFrom)
                ->first();
        }

        // Gather pinned items for pre-placement
        $pinnedNetworkContent = $allNetworkContent->filter(fn ($nc) => $nc->isPinned());

        DB::transaction(function () use ($network, $startFrom, $endAt, $contentItems, $startingContentIndex, $currentlyAiring, $pinnedNetworkContent, $forceReset) {
            if ($forceReset) {
                // Wipe all programmes — past and future — and start completely fresh
                $network->programmes()->delete();
            } else {
                // Clear future programmes (keep past for history)
                $network->programmes()
                    ->where('start_time', '>', $startFrom)
                    ->delete();
            }

            // Pre-place pinned content at their anchored day+time slots across the window
            $this->prePlacePinnedOccurrences($network, $pinnedNetworkContent, $startFrom, $endAt);

            // Pre-load pinned programmes once — the rotation loop below reads them
            // on every iteration to avoid overrunning a pin slot, so a per-iteration
            // query would mean ~336 DB hits on a 7-day/30-min schedule.
            $pinnedProgrammes = $network->programmes()
                ->whereNotNull('pinned_start_time')
                ->where('start_time', '>', $startFrom)
                ->orderBy('start_time')
                ->get();

            // If there's a currently airing programme, start from its end time
            // This prevents creating overlapping programmes
            if ($currentlyAiring) {
                $currentTime = $currentlyAiring->end_time->copy();

                // Find the content index for the item AFTER the currently airing one
                $contentIndex = $startingContentIndex;
                foreach ($contentItems as $idx => $item) {
                    if ($item && get_class($item) === $currentlyAiring->contentable_type && $item->id === $currentlyAiring->contentable_id) {
                        $contentIndex = $idx + 1;
                        if ($contentIndex >= $contentItems->count()) {
                            $contentIndex = $network->loop_content ? 0 : $contentItems->count();
                        }
                        break;
                    }
                }

                Log::debug('Schedule regeneration: skipping currently airing programme', [
                    'network_id' => $network->id,
                    'current_programme_id' => $currentlyAiring->id,
                    'current_programme_title' => $currentlyAiring->title,
                    'current_end_time' => $currentlyAiring->end_time->toDateTimeString(),
                    'next_content_index' => $contentIndex,
                ]);
            } else {
                $currentTime = $startFrom->copy();
                $contentIndex = $startingContentIndex;
            }

            $contentCount = $contentItems->count();

            // Network has pinned content only — no rotation items. Pinned
            // occurrences have already been pre-placed above; skip the
            // rotation loop to avoid an undefined-array-key on $contentItems[0].
            if ($contentCount === 0) {
                $network->update(['schedule_generated_at' => Carbon::now()]);

                return;
            }

            while ($currentTime->lt($endAt)) {
                // If a programme already exists that starts exactly at this time, skip creating it
                $existingProgramme = $network->programmes()->where('start_time', $currentTime)->first();
                if ($existingProgramme) {
                    // Advance to the end of the existing programme
                    $currentTime = $existingProgramme->end_time->copy();

                    // Try to advance the content index to the item after the existing programme's contentable if possible
                    $foundIndex = null;
                    foreach ($contentItems as $idx => $item) {
                        if ($item && get_class($item) === $existingProgramme->contentable_type && $item->id === $existingProgramme->contentable_id) {
                            $foundIndex = $idx;
                            break;
                        }
                    }

                    if ($foundIndex !== null) {
                        $contentIndex = $foundIndex + 1;
                        if ($contentIndex >= $contentCount) {
                            if ($network->loop_content) {
                                $contentIndex = 0;
                            } else {
                                break;
                            }
                        }
                    }

                    continue; // Skip creation for this time slot
                }

                $content = $contentItems[$contentIndex];
                $duration = $this->getContentDuration($content);

                // If placing this item would overrun a pinned programme, skip to the pin start instead.
                // $pinnedProgrammes is pre-loaded once above — search it in-memory here.
                $nextPinned = $pinnedProgrammes->first(fn (NetworkProgramme $p) => $p->start_time->gt($currentTime));

                if ($nextPinned && $currentTime->copy()->addSeconds($duration)->gt($nextPinned->start_time)) {
                    $currentTime = $nextPinned->start_time->copy();

                    continue;
                }

                // Create programme entry
                NetworkProgramme::create([
                    'network_id' => $network->id,
                    'title' => $this->getContentTitle($content),
                    'description' => $this->getContentDescription($content),
                    'image' => $this->getContentImage($content),
                    'start_time' => $currentTime->copy(),
                    'end_time' => $currentTime->copy()->addSeconds($duration),
                    'duration_seconds' => $duration,
                    'contentable_type' => get_class($content),
                    'contentable_id' => $content->id,
                ]);

                $currentTime->addSeconds($duration);

                // Move to next content (loop if needed)
                $contentIndex++;
                if ($network->loop_content && $contentIndex >= $contentCount) {
                    $contentIndex = 0;
                } elseif (! $network->loop_content && $contentIndex >= $contentCount) {
                    break;
                }
            }

            // Update schedule generation timestamp
            $network->update(['schedule_generated_at' => Carbon::now()]);
        });

        $generatedCount = $network->programmes()->where('start_time', '>=', $startFrom)->count();

        Log::info("Schedule generated for network {$network->name}", [
            'programme_count' => $generatedCount,
        ]);

        return $generatedCount;
    }

    /**
     * Determine the starting content index for schedule regeneration.
     *
     * This method looks at the most recent programme (currently airing or just finished)
     * to determine which content item should come next. This prevents the schedule from
     * resetting to the first content item when regeneration happens mid-broadcast.
     */
    protected function determineStartingContentIndex(Network $network, Collection $contentItems, Carbon $startFrom): int
    {
        $contentCount = $contentItems->count();

        if ($contentCount === 0) {
            return 0;
        }

        // First, check if there's a currently airing programme
        $currentProgramme = $network->programmes()
            ->where('start_time', '<=', $startFrom)
            ->where('end_time', '>', $startFrom)
            ->first();

        if ($currentProgramme) {
            // There's a programme currently airing - find its content index
            // We'll continue from this programme's content, so the next will be +1
            $foundIndex = $this->findContentIndex($contentItems, $currentProgramme);

            if ($foundIndex !== null) {
                Log::debug('Found currently airing programme for content index', [
                    'network_id' => $network->id,
                    'programme_id' => $currentProgramme->id,
                    'programme_title' => $currentProgramme->title,
                    'content_index' => $foundIndex,
                ]);

                // Since this programme is still airing, we start from this index
                // (the existing programme will be skipped in the main loop)
                return $foundIndex;
            }
        }

        // No current programme - check for the most recently ended programme
        // This handles the case where we're between programmes
        $lastProgramme = $network->programmes()
            ->where('end_time', '<=', $startFrom)
            ->orderBy('end_time', 'desc')
            ->first();

        if ($lastProgramme) {
            $foundIndex = $this->findContentIndex($contentItems, $lastProgramme);

            if ($foundIndex !== null) {
                // The last programme just finished, so start with the NEXT content item
                $nextIndex = $foundIndex + 1;

                if ($nextIndex >= $contentCount) {
                    if ($network->loop_content) {
                        $nextIndex = 0;
                    } else {
                        // No more content and not looping
                        return 0;
                    }
                }

                Log::debug('Continuing from most recently ended programme', [
                    'network_id' => $network->id,
                    'last_programme_id' => $lastProgramme->id,
                    'last_programme_title' => $lastProgramme->title,
                    'last_content_index' => $foundIndex,
                    'next_content_index' => $nextIndex,
                ]);

                return $nextIndex;
            }
        }

        // No programme history found - start from the beginning
        Log::debug('No programme history found, starting from beginning', [
            'network_id' => $network->id,
        ]);

        return 0;
    }

    /**
     * Find the index of a programme's content within the content items collection.
     */
    protected function findContentIndex(Collection $contentItems, NetworkProgramme $programme): ?int
    {
        foreach ($contentItems as $idx => $item) {
            if ($item && get_class($item) === $programme->contentable_type && $item->id === $programme->contentable_id) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Get ordered content based on network schedule type.
     * Pinned items are excluded from the rotation — they're pre-placed at their anchored times.
     */
    protected function getOrderedContent(Network $network, bool $forceReset = false): Collection
    {
        $networkContent = $network->networkContent()->with('contentable')->get()
            ->filter(fn ($nc) => ! $nc->isPinned());

        if ($networkContent->isEmpty()) {
            return collect();
        }

        return match ($network->schedule_type) {
            'shuffle' => $this->shuffleContent($networkContent, $network, $forceReset),
            'sequential' => $networkContent->sortBy('sort_order')->pluck('contentable')->filter(),
            'manual' => collect(), // Manual schedules are managed via Schedule Builder
            default => $networkContent->sortBy('sort_order')->pluck('contentable')->filter(),
        };
    }

    /**
     * Pre-place pinned content at their anchored day-of-week + time slots across the schedule window.
     *
     * Pinned items always land at their target time. If two pins collide at the same time,
     * the one with the lower sort_order wins; subsequent conflicting pins are skipped.
     *
     * @param  Collection<int, NetworkContent>  $pinnedItems
     */
    protected function prePlacePinnedOccurrences(
        Network $network,
        Collection $pinnedItems,
        Carbon $startFrom,
        Carbon $endAt
    ): void {
        if ($pinnedItems->isEmpty()) {
            return;
        }

        $dayMap = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
            'friday' => 5, 'saturday' => 6, 'sunday' => 0,
        ];

        // Sort pinned items by sort_order so earlier ones win on collision
        $sorted = $pinnedItems->sortBy('sort_order');

        // Collect occupied intervals to detect collisions: [start => end]
        $occupied = [];

        $day = $startFrom->copy()->startOfDay();

        while ($day->lt($endAt)) {
            foreach ($sorted as $nc) {
                $targetDow = $dayMap[strtolower($nc->pin_day_of_week)] ?? null;

                if ($targetDow === null || (int) $day->format('w') !== $targetDow) {
                    continue;
                }

                [$hour, $minute] = array_map('intval', explode(':', $nc->pin_time_of_day));
                $occurrenceStart = $day->copy()->setTime($hour, $minute, 0);

                // Skip occurrences before the schedule window start
                if ($occurrenceStart->lte($startFrom)) {
                    continue;
                }

                // Skip occurrences after the window end
                if ($occurrenceStart->gte($endAt)) {
                    continue;
                }

                $content = $nc->contentable;
                if (! $content) {
                    continue;
                }

                $duration = $this->getContentDuration($content);
                $occurrenceEnd = $occurrenceStart->copy()->addSeconds($duration);

                // Collision check: skip if this slot overlaps an already-placed pin
                $collision = false;
                foreach ($occupied as [$oStart, $oEnd]) {
                    if ($occurrenceStart->lt($oEnd) && $occurrenceEnd->gt($oStart)) {
                        $collision = true;
                        Log::warning('Pinned content collision skipped', [
                            'network_id' => $network->id,
                            'content_id' => $content->id,
                            'pin_start' => $occurrenceStart->toDateTimeString(),
                        ]);
                        break;
                    }
                }

                if ($collision) {
                    continue;
                }

                $occupied[] = [$occurrenceStart, $occurrenceEnd];

                NetworkProgramme::create([
                    'network_id' => $network->id,
                    'title' => $this->getContentTitle($content),
                    'description' => $this->getContentDescription($content),
                    'image' => $this->getContentImage($content),
                    'start_time' => $occurrenceStart->copy(),
                    'end_time' => $occurrenceEnd,
                    'duration_seconds' => $duration,
                    'contentable_type' => get_class($content),
                    'contentable_id' => $content->id,
                    'pinned_start_time' => $occurrenceStart->copy(),
                ]);
            }

            $day->addDay();
        }
    }

    /**
     * Shuffle content with weighting support and week-based seeding.
     *
     * Normal mode: seeds by network ID + week number so the same week always
     * produces the same shuffle order (stable programme guide, no mid-week jumps).
     *
     * Force-reset mode: seeds by network ID + current Unix timestamp so each
     * hard reset produces a genuinely different order.
     */
    protected function shuffleContent(Collection $networkContent, Network $network, bool $forceReset = false): Collection
    {
        // Deterministic base order before grouping, so both chain grouping and
        // the seeded shuffle below stay stable/reproducible.
        $ordered = $networkContent
            ->filter(fn (NetworkContent $item) => $item->contentable)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();

        // Build shuffle "units" — a chain collapses into one unit carrying its
        // ordered sub-list of contentables; an unchained item is a unit of one.
        // Units are what gets shuffled, so a chain always stays consecutive.
        $seenChains = [];
        $units = collect();

        foreach ($ordered as $item) {
            if ($item->chain_id !== null) {
                if (isset($seenChains[$item->chain_id])) {
                    continue; // already folded into a unit
                }
                $seenChains[$item->chain_id] = true;

                $members = $ordered->where('chain_id', $item->chain_id)->values();
                $lead = $members->first(); // lowest sort_order = dynamically-resolved lead

                $units->push([
                    'weight' => max(1, (int) $lead->weight),
                    'items' => $members->pluck('contentable')->all(),
                ]);
            } else {
                $units->push([
                    'weight' => max(1, (int) $item->weight),
                    'items' => [$item->contentable],
                ]);
            }
        }

        // Weight-expand at unit granularity — preserves today's per-item
        // weighting for unchained items; a chain now scatters as one block.
        $weighted = collect();
        foreach ($units as $unit) {
            for ($i = 0; $i < $unit['weight']; $i++) {
                $weighted->push($unit['items']);
            }
        }

        if ($forceReset) {
            // Use current time as seed so every hard reset produces a unique shuffle
            $seed = crc32($network->id.'-reset-'.now()->timestamp);
        } else {
            // Week-based seed: stable within the same week, changes each week
            $weekNumber = (int) now()->format('oW'); // Year + week number (e.g., 202603)
            $seed = crc32($network->id.'-'.$weekNumber);
        }

        $shuffledUnits = $this->seededShuffle($weighted, $seed);

        // Flatten chosen units back into the flat Collection<Channel|Episode>
        // that getOrderedContent() expects — chain members land consecutively.
        return $shuffledUnits->flatMap(fn (array $items) => $items)->values();
    }

    /**
     * Shuffle a collection using a seeded random number generator.
     */
    protected function seededShuffle(Collection $collection, int $seed): Collection
    {
        $items = $collection->values()->all();
        mt_srand($seed);

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        // Reset random seed to avoid affecting other random operations
        mt_srand();

        return collect($items);
    }

    /**
     * Get the duration of a content item in seconds.
     */
    protected function getContentDuration(Episode|Channel $content): int
    {
        if ($content instanceof Episode) {
            // First try duration_secs (already in seconds)
            if (isset($content->info['duration_secs']) && is_numeric($content->info['duration_secs'])) {
                $duration = (int) $content->info['duration_secs'];

                return $duration > 0 ? $duration : $this->minimumDurationSeconds;
            }

            // Parse duration field (may be HH:MM:SS format)
            $duration = $this->parseDuration($content->info['duration'] ?? null);

            return $duration > 0 ? $duration : $this->minimumDurationSeconds;
        }

        if ($content instanceof Channel) {
            // First check info directly on channel
            if (isset($content->info['duration_secs']) && is_numeric($content->info['duration_secs'])) {
                $duration = (int) $content->info['duration_secs'];

                return $duration > 0 ? $duration : $this->minimumDurationSeconds;
            }

            $duration = $this->parseDuration($content->info['duration'] ?? null);
            if ($duration > 0) {
                return $duration;
            }

            // Fallback to movie_data structure
            $secs = $content->movie_data['info']['duration_secs'] ?? null;
            if ($secs && is_numeric($secs)) {
                return (int) $secs > 0 ? (int) $secs : $this->minimumDurationSeconds;
            }

            $duration = $this->parseDuration($content->movie_data['info']['duration'] ?? null);

            return $duration > 0 ? $duration : $this->minimumDurationSeconds;
        }

        return $this->minimumDurationSeconds;
    }

    /**
     * Parse duration from various formats to seconds.
     */
    protected function parseDuration(mixed $duration): int
    {
        if ($duration === null) {
            return 0;
        }

        if (is_numeric($duration)) {
            return (int) $duration;
        }

        if (is_string($duration)) {
            // Handle HH:MM:SS or MM:SS format
            if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $matches)) {
                return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
            }
            if (preg_match('/^(\d+):(\d+)$/', $duration, $matches)) {
                return ((int) $matches[1] * 60) + (int) $matches[2];
            }
        }

        return 0;
    }

    /**
     * Get the title for a content item.
     */
    protected function getContentTitle(Episode|Channel $content): string
    {
        if ($content instanceof Episode) {
            $series = $content->series;
            $seasonNum = $content->season ?? 1;
            $episodeNum = $content->episode_num ?? 1;

            return $series
                ? "{$series->name} S{$seasonNum}E{$episodeNum}"
                : $content->title;
        }

        // For VOD channels, prefer the metadata title (o_name/name from info) over
        // the channel's display name, which may be a playlist group/category name.
        if ($content instanceof Channel && $content->is_vod) {
            $metaTitle = $content->info['o_name']
                ?? $content->info['name']
                ?? $content->movie_data['info']['o_name']
                ?? $content->movie_data['info']['name']
                ?? null;

            if ($metaTitle) {
                return $metaTitle;
            }
        }

        return $content->name ?? $content->title ?? 'Unknown';
    }

    /**
     * Get the description for a content item.
     */
    protected function getContentDescription(Episode|Channel $content): ?string
    {
        if ($content instanceof Episode) {
            return $content->info['plot'] ?? $content->plot ?? null;
        }

        if ($content instanceof Channel) {
            // info['plot'] is where Emby/Jellyfin VOD metadata lands;
            // movie_data is the older/alternative structure — check both.
            return $content->info['plot']
                ?? $content->info['description']
                ?? $content->movie_data['info']['plot']
                ?? $content->movie_data['info']['description']
                ?? null;
        }

        return null;
    }

    /**
     * Get the image URL for a content item.
     * For VOD channels, prefers the media-server poster over the generic channel logo.
     */
    protected function getContentImage(Episode|Channel $content): ?string
    {
        if ($content instanceof Episode) {
            // Try multiple sources for episode images
            $imageUrl = $content->cover
                ?? $content->info['movie_image'] ?? null
                ?? $content->info['cover_big'] ?? null
                ?? $content->info['stream_icon'] ?? null;

            // If still empty, try series cover as fallback
            if (empty($imageUrl) && $content->series) {
                $imageUrl = $content->series->cover ?? null;
            }

            return $imageUrl;
        }

        if ($content instanceof Channel) {
            if ($content->is_vod) {
                // For VOD, prefer the movie poster from metadata over the generic channel logo.
                return $content->info['cover_big']
                    ?? $content->info['movie_image']
                    ?? $content->movie_data['info']['cover_big']
                    ?? $content->movie_data['info']['movie_image']
                    ?? $content->logo
                    ?? $content->logo_internal
                    ?? null;
            }

            // For live channels, logo is the correct channel icon.
            return $content->logo
                ?? $content->logo_internal
                ?? $content->movie_data['info']['cover_big']
                ?? $content->movie_data['info']['movie_image']
                ?? $content->info['cover_big']
                ?? $content->info['movie_image']
                ?? null;
        }

        return null;
    }

    /**
     * Get the currently airing programme for a network.
     */
    public function getCurrentProgramme(Network $network): ?NetworkProgramme
    {
        $now = Carbon::now();

        return $network->programmes()
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->first();
    }

    /**
     * Get upcoming programmes for a network.
     */
    public function getUpcomingProgrammes(Network $network, int $limit = 10): Collection
    {
        $now = Carbon::now();

        return $network->programmes()
            ->where('start_time', '>', $now)
            ->orderBy('start_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Regenerate schedules for all networks that need it.
     */
    public function regenerateStaleSchedules(): void
    {
        $networks = Network::where('enabled', true)
            ->where('schedule_type', '!=', 'manual')
            ->get();

        foreach ($networks as $network) {
            if ($network->needsScheduleRegeneration()) {
                $this->generateSchedule($network);
            }
        }
    }
}
