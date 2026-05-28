<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Series;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class RegexTesterService
{
    /**
     * Load sample values for a given context.
     *
     * @return Collection<int, string>
     */
    public static function fetchSamplesForContext(string $context, ?int $userId, int $limit = 50): Collection
    {
        return match ($context) {
            'channels' => Channel::where('user_id', $userId)
                ->where('is_vod', false)
                ->whereNotNull('title')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('title'),

            'vod_channels' => Channel::where('user_id', $userId)
                ->where('is_vod', true)
                ->whereNotNull('title')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('title'),

            'groups' => Group::where('user_id', $userId)
                ->where('type', 'live')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('name'),

            'vod_groups' => Group::where('user_id', $userId)
                ->where('type', 'vod')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('name'),

            'series' => Series::where('user_id', $userId)
                ->whereNotNull('name')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('name'),

            'categories' => Category::where('user_id', $userId)
                ->whereNotNull('name')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('name'),

            'epg_channels' => EpgChannel::where('user_id', $userId)
                ->whereNotNull('display_name')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('display_name'),

            default => collect(),
        };
    }

    /**
     * Normalize textarea sample input into a list of non-empty lines.
     *
     * @return array<int, string>
     */
    public static function normalizeSamples(string $samples): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $samples))));
    }

    /**
     * Test a regex pattern against sample strings.
     *
     * @param  string[]  $samples
     * @return array<int, array{input: string, matches: bool, output: string, error: ?string}>
     */
    public static function test(string $pattern, string $flags, string $replacement, array $samples): array
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return [];
        }

        $delimiter = '/';
        $escaped = str_replace($delimiter, '\\/', $pattern);
        $compiled = $delimiter.$escaped.$delimiter.$flags;

        set_error_handler(static fn () => true);
        $valid = @preg_match($compiled, '') !== false;
        restore_error_handler();

        if (! $valid) {
            return [[
                'input' => '',
                'matches' => false,
                'output' => '',
                'error' => 'Invalid regex: '.preg_last_error_msg(),
            ]];
        }

        $results = [];

        foreach ($samples as $sample) {
            $sample = trim($sample);

            if ($sample === '') {
                continue;
            }

            $matches = @preg_match($compiled, $sample) === 1;
            $output = @preg_replace($compiled, $replacement, $sample) ?? $sample;

            $results[] = [
                'input' => $sample,
                'matches' => $matches,
                'output' => (string) $output,
                'error' => null,
            ];
        }

        return $results;
    }

    /**
     * Render test results as an HTML table.
     *
     * @param  array<int, array{input: string, matches: bool, output: string, error: ?string}>  $results
     */
    public static function renderResults(array $results, bool $showReplacement): HtmlString
    {
        if (empty($results)) {
            return new HtmlString('');
        }

        // Single error row
        if (isset($results[0]['error']) && $results[0]['error']) {
            $message = e($results[0]['error']);

            return new HtmlString(
                '<div class="mt-3 rounded-lg border border-danger-200 bg-danger-50 dark:border-danger-800 dark:bg-danger-950/30 p-3 text-sm text-danger-700 dark:text-danger-300">'
                .$message
                .'</div>'
            );
        }

        $matchCount = count(array_filter($results, fn ($r) => $r['matches']));
        $total = count($results);

        $rows = '';
        foreach ($results as $row) {
            $input = e($row['input']);
            $output = e($row['output']);
            $changed = $row['input'] !== $row['output'];

            if ($row['matches']) {
                $badge = '<span class="inline-flex items-center rounded-full bg-success-100 dark:bg-success-950/40 px-2 py-0.5 text-xs font-medium text-success-700 dark:text-success-400">Match</span>';
            } else {
                $badge = '<span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">No match</span>';
            }

            $outputCell = $showReplacement
                ? '<td class="px-3 py-2 font-mono text-xs '.($changed ? 'text-primary-700 dark:text-primary-400 font-semibold' : 'text-gray-400 dark:text-gray-500').'">'.$output.'</td>'
                : '';

            $rows .= '<tr class="border-t border-gray-100 dark:border-gray-800">'
                .'<td class="px-3 py-2 font-mono text-xs text-gray-900 dark:text-gray-100 max-w-xs truncate" title="'.$input.'">'.$input.'</td>'
                .'<td class="px-3 py-2 whitespace-nowrap">'.$badge.'</td>'
                .$outputCell
                .'</tr>';
        }

        $outputHeader = $showReplacement
            ? '<th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Output</th>'
            : '';

        $summary = '<div class="mb-2 text-sm text-gray-600 dark:text-gray-400">'
            .'<span class="font-medium text-success-600 dark:text-success-400">'.$matchCount.' match'.($matchCount === 1 ? '' : 'es').'</span>'
            .' of '.$total.' samples'
            .'</div>';

        $table = '<div class="mt-3 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700">'
            .'<table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">'
            .'<thead class="bg-gray-50 dark:bg-gray-800">'
            .'<tr>'
            .'<th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Input</th>'
            .'<th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Result</th>'
            .$outputHeader
            .'</tr>'
            .'</thead>'
            .'<tbody class="bg-white dark:bg-gray-900">'
            .$rows
            .'</tbody>'
            .'</table>'
            .'</div>';

        return new HtmlString($summary.$table);
    }
}
