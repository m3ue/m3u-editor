<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use DateTimeZone;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ManualScheduleBuilder extends Page
{
    use InteractsWithRecord;

    protected static string $resource = NetworkResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Schedule Builder';

    protected static ?string $title = 'Schedule Builder';

    protected string $view = 'filament.resources.networks.pages.manual-schedule-builder';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();
    }

    /**
     * Resolve a validated timezone from the browser, falling back to UTC.
     */
    protected function resolveTimezone(string $tz): DateTimeZone
    {
        try {
            return new DateTimeZone($tz);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    // ── Core: List-Based Time Recalculation ─────────────────────────

    /**
     * Recalculate start_time / end_time for all programmes on a given day,
     * walking them in sort_order. Pinned programmes use their pinned time;
     * unpinned programmes flow sequentially from the previous end + gap.
     */
    protected function recalculateTimes(Network $network, string $date, DateTimeZone $tz): void
    {
        $gap = (int) ($network->schedule_gap_seconds ?? 0);

        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $programmes = $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->reorder()
            ->orderBy('sort_order')
            ->get();

        if ($programmes->isEmpty()) {
            return;
        }

        $cursor = $dayStart->copy();
        $isFirst = true;

        foreach ($programmes as $prog) {
            if ($prog->pinned_start_time) {
                $start = $prog->pinned_start_time->copy();
            } else {
                $start = $isFirst ? $cursor->copy() : $cursor->copy()->addSeconds($gap);
            }

            $end = $start->copy()->addSeconds($prog->duration_seconds);

            $prog->update([
                'start_time' => $start,
                'end_time' => $end,
            ]);

            $cursor = $end->copy();
            $isFirst = false;
        }
    }

    /**
     * Reorder programmes by an array of IDs (from frontend drag-and-drop).
     * Sets sort_order based on position, then recalculates all times.
     */
    public function reorderProgrammes(array $orderedIds, string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        foreach ($orderedIds as $index => $id) {
            NetworkProgramme::where('id', $id)
                ->where('network_id', $network->id)
                ->update(['sort_order' => $index]);
        }

        $this->recalculateTimes($network, $date, $tz);

        $network->update(['schedule_generated_at' => Carbon::now()]);

        return [
            'success' => true,
            'programmes' => $this->getScheduleForDate($date, $timezone),
        ];
    }

    /**
     * Pin or unpin a programme's start time.
     * When $time is non-null, pin to that local time. When null, unpin.
     */
    public function pinProgrammeTime(int $programmeId, ?string $time, string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $programme = $network->programmes()->find($programmeId);
        if (! $programme) {
            return ['success' => false];
        }

        if ($time !== null && $time !== '') {
            // Pin to the specified local time, stored as UTC
            $pinnedUtc = Carbon::parse("{$date} {$time}", $tz)->utc();
            $programme->update([
                'pinned_start_time' => $pinnedUtc,
                'start_time' => $pinnedUtc,
                'end_time' => $pinnedUtc->copy()->addSeconds($programme->duration_seconds),
            ]);
        } else {
            // Unpin
            $programme->update(['pinned_start_time' => null]);
        }

        $this->recalculateTimes($network, $date, $tz);

        $network->update(['schedule_generated_at' => Carbon::now()]);

        return [
            'success' => true,
            'programmes' => $this->getScheduleForDate($date, $timezone),
        ];
    }

    // ── Programme Response Formatting ───────────────────────────────

    /**
     * Format a programme record for the frontend, with times in the user's timezone.
     */
    protected function formatProgrammeResponse(NetworkProgramme $programme, DateTimeZone $tz): array
    {
        $localStart = $programme->start_time->copy()->setTimezone($tz);
        $localEnd = $programme->end_time->copy()->setTimezone($tz);

        $pinnedLocal = null;
        if ($programme->pinned_start_time) {
            $pinnedLocal = $programme->pinned_start_time->copy()->setTimezone($tz)->format('H:i');
        }

        return [
            'id' => $programme->id,
            'title' => $programme->title,
            'description' => $programme->description,
            'image' => $programme->image,
            'start_time' => $programme->start_time->toIso8601String(),
            'end_time' => $programme->end_time->toIso8601String(),
            'duration_seconds' => $programme->duration_seconds,
            'contentable_type' => $programme->contentable_type,
            'contentable_id' => $programme->contentable_id,
            'sort_order' => $programme->sort_order,
            'pinned_start_time' => $pinnedLocal,
            'is_pinned' => $programme->pinned_start_time !== null,
            'start_hour' => (int) $localStart->format('H'),
            'start_minute' => (int) $localStart->format('i'),
            'end_hour' => (int) $localEnd->format('H'),
            'end_minute' => (int) $localEnd->format('i'),
        ];
    }

    // ── Schedule Data Loading ───────────────────────────────────────

    /**
     * Get schedule data for a specific date (in the user's local timezone).
     */
    public function getScheduleForDate(string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $programmes = $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->reorder()
            ->orderBy('sort_order')
            ->get();

        return $programmes->map(fn (NetworkProgramme $programme) => $this->formatProgrammeResponse($programme, $tz)
        )->values()->toArray();
    }

    // ── Media Pool ──────────────────────────────────────────────────

    /**
     * Get the media pool (network content + optionally all media).
     * When showAll is true, results are paginated (50 movies + 50 episodes per page)
     * and optionally filtered by a search term.
     *
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function getMediaPool(bool $showAll = false, string $search = '', int $page = 1): array
    {
        $network = $this->getRecord();

        if (! $showAll) {
            $content = $network->networkContent()
                ->with('contentable')
                ->orderBy('sort_order')
                ->get();

            return [
                'items' => $content->map(function (NetworkContent $item) {
                    return $this->formatMediaItem($item->contentable, $item->contentable_type);
                })->filter()->values()->toArray(),
                'has_more' => false,
            ];
        }

        $playlistId = $network->mediaServerIntegration?->playlist_id;
        if (! $playlistId) {
            return ['items' => [], 'has_more' => false];
        }

        $search = trim($search);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $searchLower = strtolower($search);

        $movies = Channel::where('playlist_id', $playlistId)
            ->whereNotNull('movie_data')
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"]))
            ->orderBy('title')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $episodes = Episode::whereHas('series', function ($q) use ($playlistId) {
            $q->where('playlist_id', $playlistId);
        })
            ->with('series')
            ->when($search !== '', function ($q) use ($searchLower) {
                $q->where(function ($inner) use ($searchLower) {
                    $inner->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                        ->orWhereHas('series', fn ($sq) => $sq->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]));
                });
            })
            ->orderBy('title')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $items = collect();
        foreach ($movies as $movie) {
            $items->push($this->formatMediaItem($movie, Channel::class));
        }
        foreach ($episodes as $episode) {
            $items->push($this->formatMediaItem($episode, Episode::class));
        }

        return [
            'items' => $items->filter()->sortBy('title')->values()->toArray(),
            'has_more' => ($movies->count() === $perPage) || ($episodes->count() === $perPage),
        ];
    }

    /**
     * Format a media item for the pool display.
     */
    protected function formatMediaItem(mixed $content, string $type): ?array
    {
        if (! $content) {
            return null;
        }

        $duration = 0;

        if ($content instanceof Episode) {
            $title = $content->title;
            if ($content->series) {
                $seasonNum = $content->season ?? 1;
                $episodeNum = $content->episode_num ?? 1;
                $title = "{$content->series->name} S{$seasonNum}E{$episodeNum}";
            }
            $duration = (int) ($content->info['duration_secs'] ?? 0);
            if ($duration <= 0) {
                $duration = $this->parseDuration($content->info['duration'] ?? null);
            }
            $image = $content->cover
                ?? $content->info['movie_image'] ?? null
                ?? $content->info['cover_big'] ?? null;
        } elseif ($content instanceof Channel) {
            $title = $content->name ?? $content->title ?? 'Unknown';
            $duration = (int) ($content->info['duration_secs'] ?? 0);
            if ($duration <= 0) {
                $duration = $this->parseDuration($content->info['duration'] ?? null);
            }
            if ($duration <= 0) {
                $duration = (int) ($content->movie_data['info']['duration_secs'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = $this->parseDuration($content->movie_data['info']['duration'] ?? null);
            }
            $image = $content->logo
                ?? $content->logo_internal
                ?? $content->movie_data['info']['cover_big'] ?? null
                ?? $content->movie_data['info']['movie_image'] ?? null;
        } else {
            return null;
        }

        if ($duration <= 0) {
            $duration = 1800;
        }

        return [
            'id' => $content->id,
            'type' => $type === Episode::class ? 'episode' : 'movie',
            'contentable_type' => $type,
            'contentable_id' => $content->id,
            'title' => $title,
            'duration_seconds' => $duration,
            'duration_display' => $this->formatDuration($duration),
            'image' => $image,
        ];
    }

    // ── Programme CRUD ──────────────────────────────────────────────

    /**
     * Add a programme to the end of the day's schedule.
     */
    public function addProgramme(string $date, string $timezone, string $contentableType, int $contentableId, ?int $durationOverride = null): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $content = $contentableType === Episode::class
            ? Episode::find($contentableId)
            : Channel::find($contentableId);

        if (! $content) {
            Notification::make()->danger()->title('Content not found')->send();

            return ['success' => false];
        }

        // Ensure content is in the network's content list
        $existingContent = NetworkContent::where('network_id', $network->id)
            ->where('contentable_type', $contentableType)
            ->where('contentable_id', $contentableId)
            ->first();

        if (! $existingContent) {
            $maxSort = $network->networkContent()->max('sort_order') ?? 0;
            NetworkContent::create([
                'network_id' => $network->id,
                'contentable_type' => $contentableType,
                'contentable_id' => $contentableId,
                'sort_order' => $maxSort + 1,
                'weight' => 1,
            ]);
        }

        $formatted = $this->formatMediaItem($content, $contentableType);
        $duration = $durationOverride ?? ($formatted['duration_seconds'] ?? 1800);
        $title = $formatted['title'] ?? 'Unknown';

        $description = null;
        $image = $formatted['image'] ?? null;
        if ($content instanceof Episode) {
            $description = $content->info['plot'] ?? $content->plot ?? null;
        } elseif ($content instanceof Channel) {
            $description = $content->movie_data['info']['plot'] ?? null;
        }

        // Get the next sort_order for this day
        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $maxSortOrder = $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->max('sort_order') ?? -1;

        // Temporary start_time at day start — recalculateTimes will fix it
        $programme = NetworkProgramme::create([
            'network_id' => $network->id,
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'start_time' => $dayStart,
            'end_time' => $dayStart->copy()->addSeconds($duration),
            'duration_seconds' => $duration,
            'contentable_type' => $contentableType,
            'contentable_id' => $contentableId,
            'sort_order' => $maxSortOrder + 1,
        ]);

        $this->recalculateTimes($network, $date, $tz);

        $network->update(['schedule_generated_at' => Carbon::now()]);

        return [
            'success' => true,
            'programmes' => $this->getScheduleForDate($date, $timezone),
        ];
    }

    /**
     * Insert a programme immediately after a specific programme in the list.
     * Shifts subsequent sort_orders up, then recalculates times.
     */
    public function insertAfterProgramme(int $afterProgrammeId, string $date, string $timezone, string $contentableType, int $contentableId, ?int $durationOverride = null): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $afterProgramme = $network->programmes()->find($afterProgrammeId);
        if (! $afterProgramme) {
            Notification::make()->danger()->title('Programme not found')->send();

            return ['success' => false];
        }

        $content = $contentableType === Episode::class
            ? Episode::find($contentableId)
            : Channel::find($contentableId);

        if (! $content) {
            Notification::make()->danger()->title('Content not found')->send();

            return ['success' => false];
        }

        // Ensure content is in the network's content list
        $existingContent = NetworkContent::where('network_id', $network->id)
            ->where('contentable_type', $contentableType)
            ->where('contentable_id', $contentableId)
            ->first();

        if (! $existingContent) {
            $maxSort = $network->networkContent()->max('sort_order') ?? 0;
            NetworkContent::create([
                'network_id' => $network->id,
                'contentable_type' => $contentableType,
                'contentable_id' => $contentableId,
                'sort_order' => $maxSort + 1,
                'weight' => 1,
            ]);
        }

        $formatted = $this->formatMediaItem($content, $contentableType);
        $duration = $durationOverride ?? ($formatted['duration_seconds'] ?? 1800);
        $title = $formatted['title'] ?? 'Unknown';

        $description = null;
        $image = $formatted['image'] ?? null;
        if ($content instanceof Episode) {
            $description = $content->info['plot'] ?? $content->plot ?? null;
        } elseif ($content instanceof Channel) {
            $description = $content->movie_data['info']['plot'] ?? null;
        }

        $insertSortOrder = $afterProgramme->sort_order + 1;

        // Shift all subsequent programmes' sort_order up by 1
        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->where('sort_order', '>=', $insertSortOrder)
            ->increment('sort_order');

        // Temporary start_time — recalculateTimes will fix it
        $programme = NetworkProgramme::create([
            'network_id' => $network->id,
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'start_time' => $dayStart,
            'end_time' => $dayStart->copy()->addSeconds($duration),
            'duration_seconds' => $duration,
            'contentable_type' => $contentableType,
            'contentable_id' => $contentableId,
            'sort_order' => $insertSortOrder,
        ]);

        $this->recalculateTimes($network, $date, $tz);

        $network->update(['schedule_generated_at' => Carbon::now()]);

        return [
            'success' => true,
            'programmes' => $this->getScheduleForDate($date, $timezone),
        ];
    }

    /**
     * Remove a programme from the schedule, then recalculate times.
     */
    public function removeProgramme(int $programmeId, string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);
        $programme = $network->programmes()->find($programmeId);

        if (! $programme) {
            return ['success' => false];
        }

        $programme->delete();

        $this->recalculateTimes($network, $date, $tz);

        Notification::make()->success()->title('Programme removed')->send();

        return [
            'success' => true,
            'programmes' => $this->getScheduleForDate($date, $timezone),
        ];
    }

    // ── Day Actions ─────────────────────────────────────────────────

    /**
     * Clear all programmes for a specific date (in user's local timezone).
     */
    public function clearDay(string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $count = $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->delete();

        Notification::make()
            ->success()
            ->title("Cleared {$count} programme(s)")
            ->send();

        return ['success' => true, 'removed' => $count];
    }

    /**
     * Copy a day's schedule to another date.
     */
    public function copyDaySchedule(string $sourceDate, string $targetDate, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $sourceDayStart = Carbon::parse($sourceDate, $tz)->startOfDay()->utc();
        $sourceDayEnd = Carbon::parse($sourceDate, $tz)->endOfDay()->utc();
        $targetDayStart = Carbon::parse($targetDate, $tz)->startOfDay()->utc();

        $dayDiff = $sourceDayStart->diffInDays($targetDayStart, false);

        $sourceProgrammes = $network->programmes()
            ->where('start_time', '>=', $sourceDayStart)
            ->where('start_time', '<', $sourceDayEnd)
            ->reorder()
            ->orderBy('sort_order')
            ->get();

        if ($sourceProgrammes->isEmpty()) {
            Notification::make()->warning()->title('No programmes to copy from this day')->send();

            return ['success' => false];
        }

        // Clear target day first
        $targetDayEnd = Carbon::parse($targetDate, $tz)->endOfDay()->utc();
        $network->programmes()
            ->where('start_time', '>=', $targetDayStart)
            ->where('start_time', '<', $targetDayEnd)
            ->delete();

        foreach ($sourceProgrammes as $programme) {
            $pinnedCopy = null;
            if ($programme->pinned_start_time) {
                $pinnedCopy = $programme->pinned_start_time->copy()->addDays($dayDiff);
            }

            NetworkProgramme::create([
                'network_id' => $network->id,
                'title' => $programme->title,
                'description' => $programme->description,
                'image' => $programme->image,
                'start_time' => $programme->start_time->copy()->addDays($dayDiff),
                'end_time' => $programme->end_time->copy()->addDays($dayDiff),
                'duration_seconds' => $programme->duration_seconds,
                'contentable_type' => $programme->contentable_type,
                'contentable_id' => $programme->contentable_id,
                'sort_order' => $programme->sort_order,
                'pinned_start_time' => $pinnedCopy,
            ]);
        }

        $network->update(['schedule_generated_at' => Carbon::now()]);

        Notification::make()
            ->success()
            ->title("Copied {$sourceProgrammes->count()} programme(s) to {$targetDate}")
            ->send();

        return ['success' => true, 'copied' => $sourceProgrammes->count()];
    }

    /**
     * Apply the weekly template — replicate the current week's schedule across the schedule window.
     */
    public function applyWeeklyTemplate(): array
    {
        $network = $this->getRecord();
        $scheduleWindowDays = $network->schedule_window_days ?? 7;

        if ($scheduleWindowDays <= 7) {
            Notification::make()->info()->title('Schedule window is already one week')->send();

            return ['success' => true];
        }

        $templateStart = Carbon::now()->startOfDay();
        $templateEnd = $templateStart->copy()->addDays(7);

        $templateProgrammes = $network->programmes()
            ->where('start_time', '>=', $templateStart)
            ->where('start_time', '<', $templateEnd)
            ->reorder()
            ->orderBy('sort_order')
            ->get();

        if ($templateProgrammes->isEmpty()) {
            Notification::make()->warning()->title('No template programmes found in the first week')->send();

            return ['success' => false];
        }

        $network->programmes()
            ->where('start_time', '>=', $templateEnd)
            ->delete();

        $weeksToFill = (int) ceil(($scheduleWindowDays - 7) / 7);
        $created = 0;

        for ($week = 1; $week <= $weeksToFill; $week++) {
            foreach ($templateProgrammes as $programme) {
                $newStart = $programme->start_time->copy()->addWeeks($week);
                $newEnd = $programme->end_time->copy()->addWeeks($week);

                if ($newStart->gt($templateStart->copy()->addDays($scheduleWindowDays))) {
                    break;
                }

                $pinnedCopy = null;
                if ($programme->pinned_start_time) {
                    $pinnedCopy = $programme->pinned_start_time->copy()->addWeeks($week);
                }

                NetworkProgramme::create([
                    'network_id' => $network->id,
                    'title' => $programme->title,
                    'description' => $programme->description,
                    'image' => $programme->image,
                    'start_time' => $newStart,
                    'end_time' => $newEnd,
                    'duration_seconds' => $programme->duration_seconds,
                    'contentable_type' => $programme->contentable_type,
                    'contentable_id' => $programme->contentable_id,
                    'sort_order' => $programme->sort_order,
                    'pinned_start_time' => $pinnedCopy,
                ]);
                $created++;
            }
        }

        $network->update(['schedule_generated_at' => Carbon::now()]);

        Notification::make()
            ->success()
            ->title("Weekly template applied — {$created} programmes created")
            ->send();

        return ['success' => true, 'created' => $created];
    }

    // ── Now Playing ─────────────────────────────────────────────────

    /**
     * Get the currently-playing programme (if any) for the status indicator.
     */
    public function getNowPlaying(): ?array
    {
        $network = $this->getRecord();
        $programme = $network->getCurrentProgramme();

        if (! $programme) {
            $next = $network->programmes()
                ->where('start_time', '>', Carbon::now())
                ->orderBy('start_time')
                ->first();

            if ($next) {
                return [
                    'status' => 'gap',
                    'next_title' => $next->title,
                    'next_start' => $next->start_time->toIso8601String(),
                ];
            }

            return ['status' => 'empty'];
        }

        return [
            'status' => 'playing',
            'title' => $programme->title,
            'end_time' => $programme->end_time->toIso8601String(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

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
     * Format seconds into human-readable duration.
     */
    protected function formatDuration(int $seconds): string
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    /**
     * Get view data for the blade template.
     */
    public function getViewData(): array
    {
        $network = $this->getRecord();

        return [
            'network' => $network,
            'scheduleWindowDays' => $network->schedule_window_days ?? 7,
            'recurrenceMode' => $network->manual_schedule_recurrence ?? 'per_day',
            'gapSeconds' => (int) ($network->schedule_gap_seconds ?? 0),
        ];
    }
}
