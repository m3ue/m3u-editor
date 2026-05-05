<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that matches unmapped IPTV channels to EPG guide channels.
 *
 * Strips common IPTV prefixes (US:, UK:, PM:, etc.) and quality/region
 * suffixes (| HD, | FHD, | EAST, etc.) from channel names, then scores
 * each cleaned name against display_name and additional_display_names in
 * the chosen EPG source. Returns:
 *   - Exact matches ready for auto-apply
 *   - Fuzzy candidates needing human review (top 3 per channel)
 *   - Unresolved channels with no usable candidate
 *
 * Supports pagination via limit/offset for large groups.
 */
class EpgChannelMatcherTool extends BaseTool
{
    private const FUZZY_MIN_SCORE = 40;

    private const TOP_CANDIDATES = 3;

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    public function description(): Stringable|string
    {
        return 'Match unmapped IPTV channels in a playlist group to EPG guide channels. Strips IPTV prefixes (US:, UK:, PM:, etc.) and quality suffixes (| HD, | FHD, etc.) from channel names, then finds EPG candidates using exact and fuzzy matching. Returns exact matches ready to auto-apply, fuzzy candidates for human review, and unresolved channels. Always ask the user which EPG source (epg_id) to use. Call EpgMappingStateTool first to identify the playlist and group.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'playlist_id' => $schema->integer()
                ->description(__('The playlist ID containing the channels to match.'))
                ->required(),
            'group' => $schema->string()
                ->description(__('The channel group name to process (e.g. "UNITED STATES").'))
                ->required(),
            'epg_id' => $schema->integer()
                ->description(__('The EPG source ID to match against. Always ask the user which EPG source to use before calling this tool.'))
                ->required(),
            'limit' => $schema->integer()
                ->description(__('Channels to process per call (default: 50, max: 100). Use with offset for pagination.')),
            'offset' => $schema->integer()
                ->description(__('Channels to skip (default: 0). Increment by limit to page through a large group.')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $playlistId = (int) $request['playlist_id'];
        $group = trim((string) $request['group']);
        $epgId = (int) $request['epg_id'];
        $limit = min(self::MAX_LIMIT, max(1, (int) ($request['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, (int) ($request['offset'] ?? 0));

        $playlist = Playlist::where('id', $playlistId)
            ->where('user_id', auth()->id())
            ->first();
        if (! $playlist) {
            return "Playlist #{$playlistId} not found.";
        }

        $epg = Epg::where('id', $epgId)
            ->where('user_id', auth()->id())
            ->first();
        if (! $epg) {
            return "EPG source #{$epgId} not found.";
        }

        $totalUnmapped = Channel::where('playlist_id', $playlistId)
            ->where('user_id', auth()->id())
            ->where('group', $group)
            ->whereNull('epg_channel_id')
            ->count();

        if ($totalUnmapped === 0) {
            return "No unmapped channels in group \"{$group}\" for playlist #{$playlistId}. All channels in this group are already mapped.";
        }

        $channels = Channel::where('playlist_id', $playlistId)
            ->where('user_id', auth()->id())
            ->where('group', $group)
            ->whereNull('epg_channel_id')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name']);

        // Load EPG channels for this source into memory for comparison.
        // Only id, display_name, and additional_display_names are needed.
        $epgChannels = EpgChannel::without('epg')
            ->where('epg_id', $epgId)
            ->get(['id', 'display_name', 'additional_display_names'])
            ->toArray();

        if (empty($epgChannels)) {
            return "EPG source \"{$epg->name}\" (id: {$epgId}) has no channels loaded. Please sync the EPG source first.";
        }

        // Build a lowercase → record lookup for O(1) exact matching.
        // Both display_name and every additional_display_name are indexed.
        $exactLookup = $this->buildExactLookup($epgChannels);

        $exactMatches = [];
        $fuzzyMatches = [];
        $unresolved = [];

        foreach ($channels as $channel) {
            $cleaned = $this->cleanName($channel->name);
            $lowerCleaned = strtolower($cleaned);

            if (isset($exactLookup[$lowerCleaned])) {
                $match = $exactLookup[$lowerCleaned];
                $exactMatches[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $cleaned,
                    'epg_channel_id' => $match['id'],
                    'epg_display_name' => $match['display_name'],
                ];

                continue;
            }

            $candidates = $this->findFuzzyCandidates($cleaned, $epgChannels);

            if (empty($candidates)) {
                $unresolved[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $cleaned,
                ];
            } else {
                $fuzzyMatches[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $cleaned,
                    'candidates' => $candidates,
                ];
            }
        }

        return $this->formatOutput(
            $playlist->name,
            $group,
            $epg->name,
            $epgId,
            $exactMatches,
            $fuzzyMatches,
            $unresolved,
            $offset,
            $limit,
            $totalUnmapped
        );
    }

    /**
     * Strip common IPTV prefixes and quality/region suffixes from a channel name.
     *
     * Prefixes removed: two or three uppercase letters followed by a colon and
     * optional whitespace (US:, PM:, UK:, CA:, AU:, MEX:, INT:, etc.).
     *
     * Suffixes removed: everything from a pipe character onward when the
     * token after the pipe is a known quality or region tag.
     */
    private function cleanName(string $name): string
    {
        // Strip prefix: "US: ", "PM:   ", "MEX: ", etc.
        $name = preg_replace('/^[A-Z]{2,4}:\s+/', '', $name) ?? $name;

        // Strip suffix: "| HD", "| FHD", "| UHD", "| 4K", "| CA", "| EAST", "| WEST", "| BACKUP"
        $name = preg_replace('/\s*\|\s*(UHD|FHD|HD|SD|4K|CA|EAST|WEST|BACKUP|\d+K)\s*$/i', '', $name) ?? $name;

        return trim($name);
    }

    /**
     * Build a lowercase-keyed lookup of all EPG display names and aliases.
     *
     * @param  array<int, array<string, mixed>>  $epgChannels
     * @return array<string, array<string, mixed>>
     */
    private function buildExactLookup(array $epgChannels): array
    {
        $lookup = [];

        foreach ($epgChannels as $ec) {
            $displayName = (string) ($ec['display_name'] ?? '');

            if ($displayName !== '') {
                $lookup[strtolower(trim($displayName))] = $ec;
            }

            $additionalNames = $ec['additional_display_names'] ?? [];

            if (is_array($additionalNames)) {
                foreach ($additionalNames as $altName) {
                    if (is_string($altName) && $altName !== '') {
                        $lookup[strtolower(trim($altName))] ??= $ec;
                    }
                }
            }
        }

        return $lookup;
    }

    /**
     * Score all EPG channels against a cleaned name and return the top candidates.
     *
     * @param  array<int, array<string, mixed>>  $epgChannels
     * @return list<array{epg_channel_id: int, display_name: string, score: int}>
     */
    private function findFuzzyCandidates(string $cleanedName, array $epgChannels): array
    {
        $lowerCleaned = strtolower($cleanedName);
        $scored = [];

        foreach ($epgChannels as $ec) {
            $displayName = (string) ($ec['display_name'] ?? '');
            $score = $this->similarity($lowerCleaned, strtolower($displayName));

            // Also score against additional display names; keep the best.
            $additionalNames = $ec['additional_display_names'] ?? [];

            if (is_array($additionalNames)) {
                foreach ($additionalNames as $altName) {
                    if (is_string($altName) && $altName !== '') {
                        $altScore = $this->similarity($lowerCleaned, strtolower($altName));

                        if ($altScore > $score) {
                            $score = $altScore;
                        }
                    }
                }
            }

            if ($score >= self::FUZZY_MIN_SCORE) {
                $scored[] = [
                    'epg_channel_id' => $ec['id'],
                    'display_name' => $displayName,
                    'score' => $score,
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, self::TOP_CANDIDATES);
    }

    /**
     * Returns a 0–100 similarity score between two lowercase strings.
     *
     * Uses similar_text as the base, with a bonus when one string fully
     * contains the other (e.g. "cinemax" inside "cinemax action max").
     */
    private function similarity(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);
        $maxLen = max($lenA, $lenB);

        $dist = levenshtein($a, $b);
        $score = (int) round((1 - $dist / $maxLen) * 100);

        if (str_contains($b, $a) || str_contains($a, $b)) {
            $score = max($score, 80);
        }

        return $score;
    }

    /**
     * @param  list<array<string, mixed>>  $exactMatches
     * @param  list<array<string, mixed>>  $fuzzyMatches
     * @param  list<array<string, mixed>>  $unresolved
     */
    private function formatOutput(
        string $playlistName,
        string $group,
        string $epgName,
        int $epgId,
        array $exactMatches,
        array $fuzzyMatches,
        array $unresolved,
        int $offset,
        int $limit,
        int $totalUnmapped
    ): string {
        $rangeStart = $offset + 1;
        $rangeEnd = min($offset + $limit, $totalUnmapped);
        $totalPages = (int) ceil($totalUnmapped / $limit);
        $currentPage = (int) floor($offset / $limit) + 1;

        $lines = [
            "EPG Match Preview — {$group} (playlist: {$playlistName})",
            "EPG Source: {$epgName} (id: {$epgId})",
            "Channels {$rangeStart}–{$rangeEnd} of {$totalUnmapped} unmapped (page {$currentPage}/{$totalPages})",
            '',
        ];

        if (! empty($exactMatches)) {
            $lines[] = 'EXACT MATCHES (confirm with user before applying):';

            foreach ($exactMatches as $m) {
                $lines[] = "  Channel #{$m['channel_id']} \"{$m['original_name']}\"";
                $lines[] = "    → {$m['epg_display_name']} (epg_channel_id: {$m['epg_channel_id']})";
            }

            $lines[] = '';
        }

        if (! empty($fuzzyMatches)) {
            $lines[] = 'FUZZY MATCHES (ask user to choose the correct candidate or skip):';

            foreach ($fuzzyMatches as $m) {
                $lines[] = "  Channel #{$m['channel_id']} \"{$m['original_name']}\" → cleaned: \"{$m['cleaned_name']}\"";

                foreach ($m['candidates'] as $i => $c) {
                    $lines[] = '    '.($i + 1).". {$c['display_name']} (epg_channel_id: {$c['epg_channel_id']}) — {$c['score']}%";
                }
            }

            $lines[] = '';
        }

        if (! empty($unresolved)) {
            $lines[] = 'UNRESOLVED (no match found — will remain unmapped):';

            foreach ($unresolved as $u) {
                $lines[] = "  Channel #{$u['channel_id']} \"{$u['original_name']}\" → cleaned: \"{$u['cleaned_name']}\"";
            }

            $lines[] = '';
        }

        $lines[] = sprintf(
            'Summary: %d exact, %d fuzzy, %d unresolved.',
            count($exactMatches),
            count($fuzzyMatches),
            count($unresolved)
        );

        if ($totalUnmapped > $offset + $limit) {
            $nextOffset = $offset + $limit;
            $lines[] = "More channels available — call this tool again with offset={$nextOffset} to continue.";
        }

        $lines[] = '';
        $lines[] = 'Present this plan to the user. Get approval for the exact matches and resolve the fuzzy ones before calling EpgMappingApplyTool.';

        return implode("\n", $lines);
    }
}
