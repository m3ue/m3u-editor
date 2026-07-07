<?php

namespace App\Services;

use App\Models\AedProfile;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Throwable;

class AedExtractorService
{
    /**
     * Extract event data from a channel title using an AED profile.
     * Returns null if the title regex doesn't match or time parsing fails.
     */
    public function extract(AedProfile $profile, string $channelTitle): ?AedEvent
    {
        $eventTitle = $this->extractTitle($profile, $channelTitle);
        if ($eventTitle === null) {
            return null;
        }

        $startTime = $this->extractStartTime($profile, $channelTitle);

        return new AedEvent(
            title: $this->formatTitle($profile->title_format, $eventTitle, $channelTitle, $startTime),
            description: $profile->description_format
                ? $this->formatTitle($profile->description_format, $eventTitle, $channelTitle, $startTime)
                : null,
            start: $startTime,
            end: $startTime?->copy()->addMinutes($profile->event_duration_minutes),
            durationMinutes: $profile->event_duration_minutes,
        );
    }

    /**
     * Build a fallback AedEvent using no_event_format when regex extraction fails.
     */
    public function fallback(AedProfile $profile, string $channelTitle): AedEvent
    {
        $title = $profile->no_event_format
            ? str_replace('{channel}', $channelTitle, $profile->no_event_format)
            : $channelTitle;

        return new AedEvent(
            title: $title,
            description: null,
            start: null,
            end: null,
            durationMinutes: $profile->event_duration_minutes,
        );
    }

    /**
     * Build the title for a pre-event padding slot.
     * Supports {time_until} (e.g. "2h 30m"), {title}, {channel}, {date}, {time}.
     */
    public function preEventTitle(AedProfile $profile, string $channelTitle, AedEvent $event, Carbon $slotStart): ?string
    {
        if (empty($profile->pre_event_format)) {
            return null;
        }

        $timeUntil = $this->formatTimeUntil($slotStart, $event->start);

        return str_replace(
            ['{time_until}', '{title}', '{channel}', '{date}', '{time}'],
            [
                $timeUntil,
                $event->title,
                $channelTitle,
                $event->start?->format('M j, Y') ?? '',
                $event->start?->format('g:i A') ?? '',
            ],
            $profile->pre_event_format
        );
    }

    /**
     * Build the title for a post-event padding slot.
     * Supports {title}, {channel}, {date}, {time}.
     * Returns null when post_event_format is not configured (no post-event slots emitted).
     */
    public function postEventTitle(AedProfile $profile, string $channelTitle, AedEvent $event): ?string
    {
        if (empty($profile->post_event_format)) {
            return null;
        }

        return str_replace(
            ['{title}', '{channel}', '{date}', '{time}'],
            [
                $event->title,
                $channelTitle,
                $event->start?->format('M j, Y') ?? '',
                $event->start?->format('g:i A') ?? '',
            ],
            $profile->post_event_format
        );
    }

    private function formatTimeUntil(Carbon $from, Carbon $to): string
    {
        $totalMinutes = max(0, (int) $from->diffInMinutes($to));
        $hours = intdiv($totalMinutes, 60);
        $mins = $totalMinutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        }

        if ($hours > 0) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        return "{$mins} mins";
    }

    private function extractTitle(AedProfile $profile, string $channelTitle): ?string
    {
        if (empty($profile->title_regex)) {
            return $channelTitle;
        }

        try {
            if (! preg_match('/'.$profile->title_regex.'/u', $channelTitle, $matches)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $raw = $matches[1] ?? $matches[0];

        if ($profile->team_delimiter && str_contains($raw, $profile->team_delimiter)) {
            return $raw;
        }

        return trim($raw);
    }

    private function extractStartTime(AedProfile $profile, string $channelTitle): ?Carbon
    {
        if (empty($profile->time_regex) || empty($profile->time_format)) {
            return null;
        }

        try {
            if (! preg_match('/'.$profile->time_regex.'/u', $channelTitle, $timeMatches)) {
                return null;
            }
            $timeString = trim($timeMatches[1] ?? $timeMatches[0]);

            $dateString = '';
            if (! empty($profile->date_regex) && ! empty($profile->date_format)) {
                if (preg_match('/'.$profile->date_regex.'/u', $channelTitle, $dateMatches)) {
                    $dateString = trim($dateMatches[1] ?? $dateMatches[0]);
                }
            }

            $sourceTimezone = $profile->source_timezone ?: 'UTC';
            $outputTimezone = $profile->output_timezone ?: 'UTC';

            // Try each pipe-separated time format until one parses successfully
            $startTime = null;
            foreach (explode('|', $profile->time_format) as $fmt) {
                $fmt = trim($fmt);
                $combined = $dateString ? "{$dateString} {$timeString}" : $timeString;
                $combinedFormat = $dateString && $profile->date_format
                    ? trim($profile->date_format).' '.$fmt
                    : $fmt;

                try {
                    $parsed = Carbon::createFromFormat($combinedFormat, $combined, $sourceTimezone);
                    if ($parsed !== false) {
                        $startTime = $parsed;
                        break;
                    }
                } catch (InvalidFormatException) {
                    continue;
                }
            }

            if (! $startTime) {
                return null;
            }

            // If no year was in the date format, snap to the nearest upcoming occurrence
            if (! $dateString || ! str_contains($profile->date_format ?? '', 'Y')) {
                $now = Carbon::now($sourceTimezone);
                if ($startTime->lt($now->copy()->subHours(12))) {
                    $startTime->addYear();
                }
            }

            return $startTime->setTimezone($outputTimezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Format a template string with extracted variables.
     * Supports: {title}, {team1}, {team2}, {date}, {time}, {channel}
     */
    private function formatTitle(
        string $template,
        string $eventTitle,
        string $channelTitle,
        ?Carbon $startTime
    ): string {
        $team1 = $eventTitle;
        $team2 = '';

        // Split by team delimiter if present in the title
        // (delimiter is stored on the profile but we only have extracted title here)
        if (str_contains($eventTitle, ' vs ') || str_contains($eventTitle, ' vs. ')) {
            $parts = preg_split('/\s+vs\.?\s+/i', $eventTitle, 2);
            $team1 = $parts[0] ?? $eventTitle;
            $team2 = $parts[1] ?? '';
        }

        return str_replace(
            ['{title}', '{team1}', '{team2}', '{channel}', '{date}', '{time}'],
            [
                $eventTitle,
                $team1,
                $team2,
                $channelTitle,
                $startTime?->format('M j, Y') ?? '',
                $startTime?->format('g:i A') ?? '',
            ],
            $template
        );
    }
}
