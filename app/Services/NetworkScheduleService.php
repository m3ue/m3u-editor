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
    public function generateSchedule(Network $network, ?Carbon $startFrom = null): void
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

            return;
        }

        DB::transaction(function () use ($network, $startFrom, $endAt, $contentItems) {
            // Clear future programmes (keep past for history)
            $network->programmes()
                ->where('start_time', '>=', $startFrom)
                ->delete();

            // Generate new schedule
            $currentTime = $startFrom->copy();
            $contentIndex = 0;
            $contentCount = $contentItems->count();

            while ($currentTime->lt($endAt)) {
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

        Log::info("Schedule generated for network {$network->name}", [
            'programme_count' => $network->programmes()->count(),
        ]);
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
