<?php

namespace App\Services\Sports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dates a channel title states, in the shapes providers use: "2026-11-04",
 * "Nov 03", "3 Nov", "11.03", "03/11". A numeric date is read both ways
 * (month first and day first), since providers use both. A date without a
 * year lands on the nearest occurrence (last, this or next year).
 */
class SportsTitleDates
{
    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const string MONTH_PATTERN = '(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?';

    /**
     * @return list<CarbonImmutable> each at the start of its day, in UTC
     */
    public static function candidates(string $title, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $dates = [];

        if (preg_match_all('/\b(20\d{2})-(\d{1,2})-(\d{1,2})\b/', $title, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = self::make((int) $match[1], (int) $match[2], (int) $match[3]);
            }
        }
        if (preg_match_all('/\b'.self::MONTH_PATTERN.'\s+(\d{1,2})\b/i', $title, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = self::nearest(self::MONTHS[strtolower($match[1])], (int) $match[2], $now);
            }
        }
        if (preg_match_all('/\b(\d{1,2})\s+'.self::MONTH_PATTERN.'(?=\s|$|[^a-z])/i', $title, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = self::nearest(self::MONTHS[strtolower($match[2])], (int) $match[1], $now);
            }
        }
        // "11.03" / "03/11": not part of a longer number run like a version or IP.
        if (preg_match_all('/(?<![\d.\/:-])(\d{1,2})[.\/](\d{1,2})(?![\d.\/:])/', $title, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = self::nearest((int) $match[1], (int) $match[2], $now);
                $dates[] = self::nearest((int) $match[2], (int) $match[1], $now);
            }
        }

        $unique = [];
        foreach (array_filter($dates) as $date) {
            $unique[$date->toDateString()] = $date;
        }

        return array_values($unique);
    }

    private static function nearest(int $month, int $day, CarbonImmutable $now): ?CarbonImmutable
    {
        $best = null;
        foreach ([$now->year - 1, $now->year, $now->year + 1] as $year) {
            $date = self::make($year, $month, $day);
            if ($date !== null && ($best === null || abs($date->diffInDays($now)) < abs($best->diffInDays($now)))) {
                $best = $date;
            }
        }

        return $best;
    }

    private static function make(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC') : null;
    }
}
