<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Job;
use App\Services\SimilaritySearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MapPlaylistChannelsToEpgChunk implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    public $deleteWhenMissingModels = true;

    // Timeout of 10 minutes per chunk
    public $timeout = 60 * 10;

    // Similarity search service
    protected SimilaritySearchService $similaritySearch;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channelIds,
        public int $epgId,
        public int $epgMapId,
        public array $settings,
        public string $batchNo,
        public int $totalChannels,
    ) {
        $this->similaritySearch = new SimilaritySearchService;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Fetch the EPG
        $epg = Epg::find($this->epgId);
        if (! $epg) {
            Log::error("EPG not found: {$this->epgId}");

            return;
        }

        // Fetch the map
        $map = EpgMap::find($this->epgMapId);
        if (! $map) {
            Log::error("EPG Map not found for ID: {$this->epgMapId}");

            return;
        }

        // Fetch the channels
        $channels = Channel::whereIn('id', $this->channelIds);

        // Process each channel
        $skipMissing = $this->settings['skip_missing'] ?? false;
        $setEpgIcon = $this->settings['set_epg_icon'] ?? false;
        $prioritizeNameMatch = $this->settings['prioritize_name_match'] ?? false;
        $mappedChannels = [];

        // Steps 1-3 (exact channel_id, exact name/display_name, callsign) are
        // batched for the whole chunk: gather every channel's lookups first,
        // load the rows any of them could match in one query per kind of
        // lookup (see exactMatchCandidates), then let each channel take the
        // first row its own lookups match.
        $prepared = [];

        foreach ($channels->cursor() as $channel) {
            // Get the title and stream id - sanitize UTF-8 immediately
            $streamId = $this->similaritySearch->cleanNameForMatching(
                $channel->stream_id_custom ?? $channel->stream_id,
                $this->settings,
            );

            if ($skipMissing && empty($streamId)) {
                // Skip channels without stream ID if the setting is enabled
                continue;
            }

            $name = $this->similaritySearch->cleanNameForMatching(
                $channel->name_custom ?? $channel->name,
                $this->settings,
            );
            $title = $this->similaritySearch->cleanNameForMatching(
                $channel->title_custom ?? $channel->title,
                $this->settings,
            );

            // Callsign from the original (pre-cleaned) channel name, e.g.
            // "US: CBS 13 (KOVR) STOCKTON HD" -> "KOVR", which matches "KOVR-DT"
            $originalTitle = $this->sanitizeUtf8(trim((string) ($channel->title_custom ?? $channel->title)));
            $originalName = $this->sanitizeUtf8(trim((string) ($channel->name_custom ?? $channel->name)));
            $callsign = $this->extractCallsign($originalTitle ?: $originalName);

            $prepared[] = [
                'channel' => $channel,
                'name' => $name,
                'title' => $title,
                // Search terms (only non-empty values)
                'terms' => array_values(array_filter([
                    mb_strtolower(trim($streamId), 'UTF-8'),
                    mb_strtolower(trim($name), 'UTF-8'),
                    mb_strtolower(trim($title), 'UTF-8'),
                ], fn ($term) => ! empty($term))),
                'callsign' => $callsign ? mb_strtolower($callsign, 'UTF-8') : null,
            ];
        }

        $exactCandidates = $this->exactMatchCandidates(
            $epg,
            collect($prepared)->pluck('terms')->flatten()->unique()->values()->all(),
            collect($prepared)->pluck('callsign')->filter()->unique()->values()->all(),
        );

        // Channels that fall through to the similarity search (step 4) are
        // deferred too - one prefetch below loads every candidate the whole
        // chunk could need in a single query, instead of a LIKE/trigram scan
        // per channel (see loadEpgCandidates doc block).
        $pendingSimilarity = [];

        foreach ($prepared as $entry) {
            $terms = $entry['terms'];
            $channelIdMatch = fn (EpgChannel $row): bool => in_array($row->channel_id_lower, $terms, true);
            $nameMatch = fn (EpgChannel $row): bool => in_array($row->name_lower, $terms, true)
                || in_array($row->display_name_lower, $terms, true);

            // Steps 1-2: exact channel_id then name/display_name, or the
            // other way around when the map prioritizes name matches
            $epgChannel = $prioritizeNameMatch
                ? $exactCandidates['name']->first($nameMatch) ?? $exactCandidates['channel_id']->first($channelIdMatch)
                : $exactCandidates['channel_id']->first($channelIdMatch) ?? $exactCandidates['name']->first($nameMatch);

            // Step 3: the callsign, alone or with a digital suffix ("KOVR-DT")
            if (! $epgChannel && $entry['callsign']) {
                $callsign = $entry['callsign'];
                $epgChannel = $exactCandidates['callsign']->first(
                    fn (EpgChannel $row): bool => collect([$row->channel_id_lower, $row->name_lower, $row->display_name_lower])
                        ->contains(fn (?string $value): bool => $value === $callsign || str_starts_with((string) $value, $callsign.'-')),
                );
            }

            // Step 4: If no exact match, defer to a batched similarity search
            // (only for channels with significant content).
            if (! $epgChannel) {
                if (strlen(trim($entry['title'] ?: $entry['name'])) >= 3) {
                    $pendingSimilarity[] = [
                        'channel' => $entry['channel'],
                        'cleaned_title' => $entry['title'],
                        'cleaned_name' => $entry['name'],
                    ];
                }

                continue;
            }

            // If EPG channel found via an exact/callsign match, link it now.
            $mappedChannels[] = $this->mappedChannelRow($entry['channel'], $epgChannel, $setEpgIcon);
        }

        // Resolve every deferred channel against one prefetched candidate
        // set for the whole chunk, instead of a per-channel DB round-trip.
        if (! empty($pendingSimilarity)) {
            $options = $this->similaritySearch->matcherOptionsFromSettings($this->settings);

            $unionTerms = collect($pendingSimilarity)
                ->flatMap(fn (array $pending): array => $this->similaritySearch->searchTermsFor(
                    channel: $pending['channel'],
                    cleanedTitle: $pending['cleaned_title'],
                    cleanedName: $pending['cleaned_name'],
                ))
                ->unique()
                ->values()
                ->all();
            $prefetchedCandidates = $this->similaritySearch->loadEpgCandidates($epg, $unionTerms, $options['trigram_matching_enabled']);

            foreach ($pendingSimilarity as $pending) {
                $epgChannel = $this->similaritySearch->findMatchingEpgChannel(
                    channel: $pending['channel'],
                    epg: $epg,
                    removeQualityIndicators: $options['remove_quality_indicators'],
                    similarityThreshold: $options['similarity_threshold'],
                    fuzzyMaxDistance: $options['fuzzy_max_distance'],
                    exactMatchDistance: $options['exact_match_distance'],
                    customQualityIndicators: $options['quality_indicators'],
                    // Pass the prefix/pattern-cleaned values so the similarity
                    // search honors the map's exclude settings (issue #1265)
                    cleanedTitle: $pending['cleaned_title'],
                    cleanedName: $pending['cleaned_name'],
                    prefetchedCandidates: $prefetchedCandidates,
                    trigramMatchingEnabled: $options['trigram_matching_enabled'],
                );

                if ($epgChannel) {
                    $mappedChannels[] = $this->mappedChannelRow($pending['channel'], $epgChannel, $setEpgIcon);
                }
            }
        }

        // Store the mapped channels in Job records for the next stage
        if (! empty($mappedChannels)) {
            // Store in chunks of 50
            foreach (array_chunk($mappedChannels, 50) as $chunk) {
                Job::create([
                    'title' => "Processing EPG channel mapping for: {$epg->name}",
                    'batch_no' => $this->batchNo,
                    'payload' => $chunk,
                    'variables' => [
                        'epgId' => $epg->id,
                    ],
                ]);
            }
        }

        // Update progress
        $progressIncrement = (count($this->channelIds) / $this->totalChannels) * 95; // Reserve 5% for completion
        $map->update(['progress' => min(99, $map->progress + $progressIncrement)]);
    }

    /**
     * Load every EPG row a channel in this chunk could match exactly (steps
     * 1-3), one query per kind of lookup instead of up to three per channel.
     * Rows carry the database's own LOWER() of each field, so PHP compares
     * them exactly as the per-channel SQL did, and keep the order the
     * database returned them in, so a channel's first match is the row its
     * own `->first()` query would have returned.
     *
     * @param  list<string>  $terms
     * @param  list<string>  $callsigns
     * @return array{channel_id: Collection<int, EpgChannel>, name: Collection<int, EpgChannel>, callsign: Collection<int, EpgChannel>}
     */
    protected function exactMatchCandidates(Epg $epg, array $terms, array $callsigns): array
    {
        $baseQuery = fn (): Builder => $epg->matchableChannels()
            ->select('id', 'channel_id', 'name', 'display_name')
            ->selectRaw('LOWER(channel_id) AS channel_id_lower, LOWER(name) AS name_lower, LOWER(display_name) AS display_name_lower');

        return [
            'channel_id' => $terms === [] ? new Collection : $baseQuery()
                ->where('channel_id', '!=', '')
                ->whereIn(DB::raw('LOWER(channel_id)'), $terms)
                ->get(),
            'name' => $terms === [] ? new Collection : $baseQuery()
                ->where(fn (Builder $query): Builder => $query
                    ->whereIn(DB::raw('LOWER(name)'), $terms)
                    ->orWhereIn(DB::raw('LOWER(display_name)'), $terms))
                ->get(),
            'callsign' => $callsigns === [] ? new Collection : $baseQuery()
                ->where(function (Builder $query) use ($callsigns): void {
                    foreach ($callsigns as $callsign) {
                        $query->orWhereRaw('LOWER(channel_id) = ?', [$callsign])
                            ->orWhereRaw('LOWER(channel_id) LIKE ?', [$callsign.'-%'])
                            ->orWhereRaw('LOWER(name) = ?', [$callsign])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$callsign.'-%'])
                            ->orWhereRaw('LOWER(display_name) = ?', [$callsign])
                            ->orWhereRaw('LOWER(display_name) LIKE ?', [$callsign.'-%']);
                    }
                })
                ->get(),
        ];
    }

    /**
     * Build the row queued for the next mapping stage once a channel resolves to an EPG channel.
     *
     * @return array<string, mixed>
     */
    protected function mappedChannelRow(Channel $channel, EpgChannel $epgChannel, bool $setEpgIcon): array
    {
        if ($setEpgIcon) {
            $channel->logo_type = 'epg';
        }

        return [
            'title' => $this->sanitizeUtf8($channel->title),
            'name' => $this->sanitizeUtf8($channel->name),
            'group_internal' => $this->sanitizeUtf8($channel->group_internal),
            'user_id' => $channel->user_id,
            'playlist_id' => $channel->playlist_id,
            'source_id' => $channel->source_id,
            'epg_channel_id' => $epgChannel->id,
            'logo_type' => $channel->logo_type,
        ];
    }

    /**
     * Extract a North American TV station callsign from parentheses in a channel name.
     *
     * Matches FCC-format callsigns (US: K/W prefix) and CRTC-format (Canada: C prefix),
     * with optional digital suffixes (-DT, -LD, -CD, -HD, -TV and subchannel variants
     * like -DT2). This allowlist approach avoids maintaining a blocklist of non-callsign
     * tokens (quality flags, country codes, feed labels, etc.).
     *
     * Examples:
     *   "US: CBS 13 (KOVR) STOCKTON HD"  → "KOVR"
     *   "US: CBS 6 (WKMG-DT) ORLANDO HD" → "WKMG-DT"
     *   "US: FOX (WLOX-DT2) BILOXI HD"   → "WLOX-DT2"
     */
    protected function extractCallsign(string $name): ?string
    {
        // [KW]    = FCC (US); [C] = CRTC (Canada)
        // [A-Z]{2,3} = 2-3 more letters → 3-4 letter callsign total
        // (-(?:DT|LD|CD|HD|TV)\d?)? = optional digital suffix with optional subchannel digit
        if (preg_match('/\(([KWCkwc][A-Z]{2,3}(?:-(?:DT|LD|CD|HD|TV)\d?)?)\)/i', $name, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Sanitize a string to ensure valid UTF-8 encoding for PostgreSQL.
     */
    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove invalid UTF-8 sequences
        // mb_convert_encoding with 'UTF-8' to 'UTF-8' forces re-encoding and drops invalid bytes
        $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Alternative: Use iconv with //IGNORE to skip invalid characters
        // $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        // Remove any remaining control characters except newlines, tabs, and carriage returns
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);

        return $sanitized;
    }
}
