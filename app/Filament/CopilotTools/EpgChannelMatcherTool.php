<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use App\Services\SimilaritySearchService;
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
            ->eligibleForEpgMapping()
            ->whereNull('epg_channel_id')
            ->count();

        if ($totalUnmapped === 0) {
            return "No eligible unmapped live TV channels in group \"{$group}\" for playlist #{$playlistId}.";
        }

        $channels = Channel::where('playlist_id', $playlistId)
            ->where('user_id', auth()->id())
            ->where('group', $group)
            ->eligibleForEpgMapping()
            ->whereNull('epg_channel_id')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'name_custom', 'title', 'title_custom']);

        if (! $epg->channels()->exists()) {
            return "EPG source \"{$epg->name}\" (id: {$epgId}) has no channels loaded. Please sync the EPG source first.";
        }

        $exactMatches = [];
        $fuzzyMatches = [];
        $unresolved = [];
        $matcher = app(SimilaritySearchService::class);

        // Preload matching EPG channels once for the whole batch instead of
        // issuing a LIKE scan per channel. Most EPG sources are large enough
        // that N round-trips dominate the request time.
        $unionTerms = $channels->flatMap(
            fn (Channel $channel): array => $matcher->searchTermsFor(
                channel: $channel,
                cleanedTitle: $channel->title_custom ?? $channel->title,
                cleanedName: $channel->name_custom ?? $channel->name,
            ),
        )->unique()->values()->all();
        $prefetched = $matcher->loadEpgCandidates($epg, $unionTerms);

        foreach ($channels as $channel) {
            $result = $matcher->findEpgChannelCandidates(
                channel: $channel,
                epg: $epg,
                removeQualityIndicators: true,
                prefetchedCandidates: $prefetched,
            );
            $topCandidate = $result['candidates'][0] ?? null;

            if ($result['automatic_match'] && ($topCandidate['confidence'] ?? 0) === 100) {
                $exactMatches[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $result['normalized_name'],
                    'epg_channel_id' => $result['automatic_match']->id,
                    'epg_display_name' => $topCandidate['display_name'],
                ];

                continue;
            }

            if ($result['candidates'] === []) {
                $unresolved[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $result['normalized_name'],
                ];
            } else {
                $fuzzyMatches[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $result['normalized_name'],
                    'candidates' => array_map(fn (array $candidate): array => [
                        'epg_channel_id' => $candidate['epg_channel_id'],
                        'display_name' => $candidate['display_name'],
                        'score' => $candidate['confidence'],
                        'reason' => $candidate['reason'],
                        'matched_value' => $candidate['matched_value'],
                        'normalized_value' => $candidate['normalized_value'],
                    ], $result['candidates']),
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
            "EPG Match Preview - {$group} (playlist: {$playlistName})",
            "EPG Source: {$epgName} (id: {$epgId})",
            "Channels {$rangeStart}-{$rangeEnd} of {$totalUnmapped} unmapped (page {$currentPage}/{$totalPages})",
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
                $lines[] = "  Channel #{$m['channel_id']} \"{$m['original_name']}\" → normalized: \"{$m['cleaned_name']}\"";

                foreach ($m['candidates'] as $i => $c) {
                    $lines[] = '    '.($i + 1).". {$c['display_name']} (epg_channel_id: {$c['epg_channel_id']}) - {$c['score']}% - {$c['reason']}; compared \"{$c['matched_value']}\" as \"{$c['normalized_value']}\"";
                }
            }

            $lines[] = '';
        }

        if (! empty($unresolved)) {
            $lines[] = 'UNRESOLVED (no match found, will remain unmapped):';

            foreach ($unresolved as $u) {
                $lines[] = "  Channel #{$u['channel_id']} \"{$u['original_name']}\" → normalized: \"{$u['cleaned_name']}\"";
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
            $lines[] = "More channels available. Call this tool again with offset={$nextOffset} to continue.";
        }

        $lines[] = '';
        $lines[] = 'Present this plan to the user. Get approval for the exact matches and resolve the fuzzy ones before calling EpgMappingApplyTool.';

        return implode("\n", $lines);
    }
}
