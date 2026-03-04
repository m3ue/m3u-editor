<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
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
     */
    public function generateSchedule(Network $network, ?Carbon $startFrom = null): int
    {
        $startFrom = $startFrom ?? Carbon::now();
        $scheduleWindowDays = $network->schedule_window_days ?? self::DEFAULT_SCHEDULE_WINDOW_DAYS;
        $endAt = $startFrom->copy()->addDays($scheduleWindowDays);

        Log::info("Generating schedule for network {$network->name}", [
            'network_id' => $network->id,
            'start_from' => $startFrom->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
        ]);

        // Get all content for this network
        $contentItems = $this->getOrderedContent($network);

        if ($contentItems->isEmpty()) {
            Log::warning("No content found for network {$network->name}");

            return 0;
        }

        // Determine the starting content index BEFORE deleting anything.
        // Look for the most recent programme to determine where we should continue from.
        $startingContentIndex = $this->determineStartingContentIndex($network, $contentItems, $startFrom);

        // Check if there's a currently airing programme - if so, we should skip to its end
        $currentlyAiring = $network->programmes()
            ->where('start_time', '<=', $startFrom)
            ->where('end_time', '>', $startFrom)
            ->first();

        DB::transaction(function () use ($network, $startFrom, $endAt, $contentItems, $startingContentIndex, $currentlyAiring) {
            // Clear future programmes (keep past for history) — exclude programmes that start exactly at the regeneration boundary
            $network->programmes()
                ->where('start_time', '>', $startFrom)
                ->delete();

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
     */
    protected function getOrderedContent(Network $network): Collection
    {
        $networkContent = $network->networkContent()->with('contentable')->get();

        if ($networkContent->isEmpty()) {
            return collect();
        }

        return match ($network->schedule_type) {
            'shuffle' => $this->shuffleContent($networkContent, $network),
            'sequential' => $networkContent->sortBy('sort_order')->pluck('contentable')->filter(),
            default => $networkContent->sortBy('sort_order')->pluck('contentable')->filter(),
        };
    }

    /**
     * Shuffle content with weighting support and week-based seeding.
     *
     * Uses network ID + week number as seed to ensure:
     * - Same week regeneration produces consistent schedule
     * - Different weeks produce different shuffles (offset)
     * - Different networks have unique shuffle patterns
     */
    protected function shuffleContent(Collection $networkContent, Network $network): Collection
    {
        // Build weighted list
        $weighted = collect();
        foreach ($networkContent as $item) {
            if (! $item->contentable) {
                continue;
            }
            for ($i = 0; $i < $item->weight; $i++) {
                $weighted->push($item->contentable);
            }
        }

        // Create a seed based on network ID and current week number
        // This ensures different weeks get different shuffles while
        // regenerating the same week produces consistent results
        $weekNumber = (int) now()->format('oW'); // Year + week number (e.g., 202603)
        $seed = crc32($network->id.'-'.$weekNumber);

        return $this->seededShuffle($weighted, $seed);
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
            return $content->movie_data['info']['plot'] ?? null;
        }

        return null;
    }

    /**
     * Get the image URL for a content item.
     * Uses LogoService where applicable to ensure proper proxying.
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
            // Try multiple sources for channel/VOD images
            return $content->logo
                ?? $content->logo_internal
                ?? $content->movie_data['info']['cover_big'] ?? null
                ?? $content->movie_data['info']['movie_image'] ?? null
                ?? $content->info['cover_big'] ?? null
                ?? $content->info['movie_image'] ?? null;
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
        $networks = Network::where('enabled', true)->get();

        foreach ($networks as $network) {
            if ($network->needsScheduleRegeneration()) {
                $this->generateSchedule($network);
            }
        }
    }
}
