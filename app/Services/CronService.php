<?php

namespace App\Services;

use Cron\CronExpression;
use Illuminate\Support\HtmlString;

class CronService
{
    public static function isValid(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    /**
     * Return a human-readable description for common cron patterns.
     * Falls back to the raw expression for complex or unrecognised patterns.
     */
    public static function describe(string $expression): string
    {
        if (! self::isValid($expression)) {
            return 'Invalid expression';
        }

        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $dom, $month, $dow] = $parts;

        $time = fn (string $h, string $m): string => str_pad($h, 2, '0', STR_PAD_LEFT).':'.str_pad($m, 2, '0', STR_PAD_LEFT);

        return match (true) {
            $minute === '*' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*' => 'Every minute',

            (bool) preg_match('/^\*\/(\d+)$/', $minute, $mm) && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*' => 'Every '.$mm[1].' minutes',

            $minute === '0' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*' => 'Hourly',

            is_numeric($minute) && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*' => 'Every hour at minute '.$minute,

            (bool) preg_match('/^\*\/(\d+)$/', $hour, $mh) && is_numeric($minute) && $dom === '*' && $month === '*' && $dow === '*' => 'Every '.$mh[1].' hours'.($minute === '0' ? '' : ' at minute '.$minute),

            is_numeric($minute) && is_numeric($hour) && $dom === '*' && $month === '*' && is_numeric($dow) => 'Weekly on '.self::dayName((int) $dow).' at '.$time($hour, $minute),

            is_numeric($minute) && is_numeric($hour) && is_numeric($dom) && $month === '*' && $dow === '*' => 'Monthly on day '.$dom.' at '.$time($hour, $minute),

            is_numeric($minute) && is_numeric($hour) && is_numeric($dom) && is_numeric($month) && $dow === '*' => 'Yearly on '.self::monthName((int) $month).' '.$dom.' at '.$time($hour, $minute),

            is_numeric($minute) && is_numeric($hour) && $dom === '*' && $month === '*' && $dow === '*' => 'Daily at '.$time($hour, $minute),

            default => $expression,
        };
    }

    /**
     * Return the next $count run dates as formatted strings.
     *
     * @return array<int, string>
     */
    public static function nextRuns(string $expression, int $count = 5): array
    {
        if (! self::isValid($expression)) {
            return [];
        }

        $cron = new CronExpression($expression);
        $runs = [];

        for ($i = 0; $i < $count; $i++) {
            $runs[] = $cron->getNextRunDate('now', $i)->format('D, M j Y \a\t H:i');
        }

        return $runs;
    }

    /**
     * @return array<string, string>
     */
    public static function presets(): array
    {
        return [
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '*/30 * * * *' => 'Every 30 minutes',
            '0 * * * *' => 'Every hour',
            '0 */2 * * *' => 'Every 2 hours',
            '0 */4 * * *' => 'Every 4 hours',
            '0 */6 * * *' => 'Every 6 hours',
            '0 */12 * * *' => 'Every 12 hours',
            '0 0 * * *' => 'Daily at midnight',
            '0 3 * * *' => 'Daily at 3:00',
            '0 6 * * *' => 'Daily at 6:00',
            '0 0 * * 1' => 'Weekly (Monday midnight)',
            '0 0 * * 0' => 'Weekly (Sunday midnight)',
            '0 0 1 * *' => 'Monthly (1st at midnight)',
        ];
    }

    public static function renderPreview(string $expression): HtmlString
    {
        if (! self::isValid($expression)) {
            return new HtmlString(
                '<div class="rounded-lg bg-danger-50 dark:bg-danger-950 p-3 text-sm text-danger-700 dark:text-danger-300">'
                .'<strong>Invalid expression.</strong> Please check the cron syntax and try again.'
                .'</div>'
            );
        }

        $description = self::describe($expression);
        $runs = self::nextRuns($expression, 5);

        $html = '<div class="space-y-3">';

        $html .= '<div class="text-sm font-semibold text-primary-600 dark:text-primary-400">'
            .htmlspecialchars($description)
            .'</div>';

        if ($runs) {
            $html .= '<div>';
            $html .= '<p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Next 5 occurrences</p>';
            $html .= '<ul class="space-y-1">';
            foreach ($runs as $i => $run) {
                $html .= '<li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">'
                    .'<span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-xs font-medium text-primary-700 dark:text-primary-300">'.($i + 1).'</span>'
                    .htmlspecialchars($run)
                    .'</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    private static function dayName(int $day): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$day] ?? 'day '.$day;
    }

    private static function monthName(int $month): string
    {
        return ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][$month] ?? 'month '.$month;
    }
}
