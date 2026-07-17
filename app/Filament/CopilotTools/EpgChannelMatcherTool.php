<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgMap;
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
 *   - Automatic matches ready for confirmation
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
        return 'Match unmapped IPTV channels in a playlist group to EPG guide channels using the latest map settings for that playlist and source. Returns safe automatic matches for confirmation, review-only candidates, and unresolved channels. Always ask the user which EPG source (epg_id) to use. Call EpgMappingStateTool first to identify the playlist and group.';
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
            $totalEligible = Channel::where('playlist_id', $playlistId)
                ->where('user_id', auth()->id())
                ->where('group', $group)
                ->eligibleForEpgMapping()
                ->count();

            if ($totalEligible === 0) {
                return "No eligible live TV channels in group \"{$group}\" for playlist #{$playlistId}. The group may contain only VOD content or have EPG mapping disabled.";
            }

            return "All {$totalEligible} eligible live TV channel(s) in group \"{$group}\" for playlist #{$playlistId} are already mapped.";
        }

        $channels = Channel::where('playlist_id', $playlistId)
            ->where('user_id', auth()->id())
            ->where('group', $group)
            ->eligibleForEpgMapping()
            ->whereNull('epg_channel_id')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'name_custom', 'title', 'title_custom', 'stream_id', 'stream_id_custom']);

        if (! $epg->channels()->exists()) {
            return "EPG source \"{$epg->name}\" (id: {$epgId}) has no channels loaded. Please sync the EPG source first.";
        }

        $automaticMatches = [];
        $fuzzyMatches = [];
        $unresolved = [];
        $matcher = app(SimilaritySearchService::class);
        $settings = EpgMap::query()
            ->where('user_id', auth()->id())
            ->where('playlist_id', $playlistId)
            ->where('epg_id', $epgId)
            ->latest('id')
            ->value('settings') ?? [];

        // Configure matcher with settings so searchTermsFor uses the same cleaning
        $matcher->configureForSettings($settings);

        // Collect all search terms from all channels for a single shared EPG query
        $allSearchTerms = $channels
            ->flatMap(fn (Channel $channel): array => $matcher->searchTermsFor(
                channel: $channel,
                cleanedTitle: $matcher->cleanNameForMatching($channel->title_custom ?? $channel->title, $settings),
                cleanedName: $matcher->cleanNameForMatching($channel->name_custom ?? $channel->name, $settings),
            ))
            ->unique()
            ->values()
            ->all();

        // Load EPG candidates once for the entire batch
        $prefetchedCandidates = $matcher->loadEpgCandidates($epg, $allSearchTerms);

        foreach ($channels as $channel) {
            // Pass the shared candidate set so each channel reuses the same EPG query
            $result = $matcher->findEpgChannelCandidatesUsingSettings($channel, $epg, $settings, $prefetchedCandidates);
            $topCandidate = $result['candidates'][0] ?? null;

            if ($result['automatic_match']) {
                $automaticMatches[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $result['normalized_name'],
                    'epg_channel_id' => $result['automatic_match']->id,
                    'epg_display_name' => $topCandidate['display_name'],
                    'decision' => $result['decision'],
                    'candidates' => $result['candidates'],
                ];

                continue;
            }

            if ($result['candidates'] === []) {
                $unresolved[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $result['normalized_name'],
                    'decision' => $result['decision'],
                ];
            } else {
                $fuzzyMatches[] = [
                    'channel_id' => $channel->id,
                    'original_name' => $channel->name,
                    'cleaned_name' => $result['normalized_name'],
                    'decision' => $result['decision'],
                    'candidates' => array_map(fn (array $candidate): array => [
                        'epg_channel_id' => $candidate['epg_channel_id'],
                        'display_name' => $candidate['display_name'],
                        'score' => $candidate['confidence'],
                        'reason' => $candidate['reason'],
                        'matched_value' => $candidate['matched_value'],
                        'normalized_value' => $candidate['normalized_value'],
                        'evidence' => $candidate['evidence'],
                    ], $result['candidates']),
                ];
            }
        }

        return $this->formatOutput(
            $playlist->name,
            $group,
            $epg->name,
            $epgId,
            $automaticMatches,
            $fuzzyMatches,
            $unresolved,
            $offset,
            $limit,
            $totalUnmapped
        );
    }

    /**
     * @param  list<array<string, mixed>>  $automaticMatches
     * @param  list<array<string, mixed>>  $fuzzyMatches
     * @param  list<array<string, mixed>>  $unresolved
     */
    private function formatOutput(
        string $playlistName,
        string $group,
        string $epgName,
        int $epgId,
        array $automaticMatches,
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

        if (! empty($automaticMatches)) {
            $lines[] = 'AUTOMATIC MATCHES (confirm with user before applying):';

            foreach ($automaticMatches as $m) {
                $lines[] = "  Channel #{$m['channel_id']} \"{$m['original_name']}\"";
                $lines[] = "    -> {$m['epg_display_name']} (epg_channel_id: {$m['epg_channel_id']}) [{$m['decision']}]";

                foreach ($m['candidates'] as $i => $c) {
                    $lines[] = '    '.($i + 1).". {$c['display_name']} (epg_channel_id: {$c['epg_channel_id']}) - {$c['confidence']}% - {$c['reason']}; compared \"{$c['matched_value']}\" as \"{$c['normalized_value']}\"";
                }
            }

            $lines[] = '';
        }

        if (! empty($fuzzyMatches)) {
            $lines[] = 'FUZZY MATCHES (ask user to choose the correct candidate or skip):';

            foreach ($fuzzyMatches as $m) {
                $lines[] = "  Channel #{$m['channel_id']} \"{$m['original_name']}\" -> normalized: \"{$m['cleaned_name']}\" [{$m['decision']}]";

                foreach ($m['candidates'] as $i => $c) {
                    $lines[] = '    '.($i + 1).". {$c['display_name']} (epg_channel_id: {$c['epg_channel_id']}) - {$c['score']}% - {$c['reason']}; compared \"{$c['matched_value']}\" as \"{$c['normalized_value']}\"";
                }
            }

            $lines[] = '';
        }

        if (! empty($unresolved)) {
            $lines[] = 'UNRESOLVED (no match found, will remain unmapped):';

            foreach ($unresolved as $u) {
                $lines[] = "  Channel #{$u['channel_id']} \"{$u['original_name']}\" -> normalized: \"{$u['cleaned_name']}\" [{$u['decision']}]";
            }

            $lines[] = '';
        }

        $lines[] = sprintf(
            'Summary: %d automatic, %d fuzzy, %d unresolved.',
            count($automaticMatches),
            count($fuzzyMatches),
            count($unresolved)
        );

        if ($totalUnmapped > $offset + $limit) {
            $nextOffset = $offset + $limit;
            $lines[] = "More channels available. Call this tool again with offset={$nextOffset} to continue.";
        }

        $lines[] = '';
        $lines[] = 'Present this plan to the user. Get approval for the automatic matches and resolve the fuzzy ones before calling EpgMappingApplyTool.';

        return implode("\n", $lines);
    }
}
