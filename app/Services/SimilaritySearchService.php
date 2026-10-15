<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Service to handle similarity search between channels and EPG channels.
 */
class SimilaritySearchService
{
    /** @var list<string> */
    private const CANDIDATE_COLUMNS = ['id', 'channel_id', 'name', 'display_name', 'additional_display_names', 'epg_id'];

    private const MAX_DATABASE_CANDIDATES = 250;

    private const MAX_PREFETCH_TERMS = 200;

    private const MAX_NORMALIZED_NAMES = 50000;

    /**
     * Provider labels for high/low bitrate copies of the same feed
     * ("U&Alibi HEVC HB" / "U&Alibi HEVC LB"), which namesConflict() treats
     * like quality tokens rather than as different words.
     *
     * @var list<string>
     */
    private const BITRATE_VARIANTS = ['hb', 'lb'];

    private const MAX_REVIEW_CANDIDATES = 3;

    private const MIN_REVIEW_CONFIDENCE = 40;

    private const PREFERRED_REGION_DISTANCE_BONUS = 15;

    /** @var array<int, string> */
    private const DEFAULT_QUALITY_INDICATORS = [
        'hd',
        'fhd',
        'uhd',
        '4k',
        '8k',
        'sd',
        '720p',
        '1080p',
        '1080i',
        '2160p',
        'hdraw',
        'sdraw',
        'hevc',
        'h264',
        'h265',
    ];

    private int $bestFuzzyThreshold = 8;

    private int $upperFuzzyThreshold = 25;

    private float $embedSimThreshold = 0.80;

    private int $minChannelLength = 3;

    /** @var array<int, string> */
    private array $stopWords = [
        'tv',
        'channel',
        'network',
        'television',
        'east',
        'west',
        // Country/region codes common in IPTV playlist prefixes
        'us',
        'usa',
        'ca',
        'uk',
        'au',
        'de',
        'fr',
        'es',
        'it',
        'nl',
        'pt',
        'be',
        'ch',
        'at',
        'nz',
        'ie',
        'mx',
        'br',
        'in',
        'pk',
        'tr',
        'pl',
        'se',
        'no',
        'dk',
        'fi',
        'ro',
        'hu',
        'gr',
        'il',
        'ae',
        'sa',
        'eg',
        'ng',
        'za',
        'jp',
        'kr',
        'cn',
        'hk',
        // Generic filler tokens
        'not',
        '24/7',
        'arabic',
        'latino',
        'film',
        'movie',
        'movies',
    ];

    /** @var array<int, string> */
    private array $qualityIndicators = self::DEFAULT_QUALITY_INDICATORS;

    private bool $removeQualityIndicators = false;

    /**
     * Instance-lifetime cache for trigramAvailable() - null means "not yet
     * checked." Callers (EpgChannelMatcherTool, BuildEpgMapCandidatesJob,
     * etc.) resolve one instance and reuse it across a whole batch of
     * channels, so this avoids a pg_extension lookup per search term without
     * risking a stale answer surviving across separate requests/jobs.
     */
    private ?bool $trigramAvailable = null;

    /**
     * What candidatesMatchingTerms needs per prefetched pool: each
     * candidate's searchable text and trigram-matched terms (by position),
     * plus the positions each search term finds, filled in as channels ask.
     * Channels in a batch share most of their terms, so each term scans the
     * pool once instead of once per channel. Weakly keyed by the pool.
     *
     * @var WeakMap<Collection<int, EpgChannel>, object{models: list<EpgChannel>, ids: list<int>, priorities: list<int>|null, text: string, starts: list<int>, trigramTerms: array<int, array<string, true>>, termHits: array<string, list<int>>}>|null
     */
    private ?WeakMap $prefetchIndexes = null;

    /**
     * normalizeChannelName() results for the current quality-indicator
     * settings. Batch callers score the same candidate names for every
     * channel in a batch, so this skips repeating the Unicode and regex
     * passes. Cleared whenever those settings change.
     *
     * @var array<string, string>
     */
    private array $normalizedNames = [];

    /**
     * Apply the map's configured prefix or regex cleanup before any matching strategy runs.
     *
     * @param  array<string, mixed>  $settings
     */
    public function cleanNameForMatching(?string $value, array $settings): string
    {
        $value = trim($this->sanitizeUtf8($value) ?? '');

        foreach ($settings['exclude_prefixes'] ?? [] as $pattern) {
            if ($settings['use_regex'] ?? false) {
                $delimiter = '/';
                $finalPattern = $delimiter.str_replace($delimiter, '\\'.$delimiter, $pattern).$delimiter.'u';
                $value = preg_replace($finalPattern, '', $value) ?? $value;
            } elseif (str_starts_with($value, $pattern)) {
                $value = substr($value, strlen($pattern));
            }
        }

        return trim($value);
    }

    /**
     * Extract the matcher-relevant fields from an EpgMap's persisted settings.
     *
     * Centralizes the settings→parameter mapping so a channel gets identical
     * candidates and automatic-match decisions from the mapping job, the
     * candidate review and the Copilot preview tool for the same EpgMap.
     * An empty custom quality indicator list (what the form saves when left
     * empty) means the built-in defaults, as the form's helper text says.
     *
     * @param  array<string, mixed>  $settings
     * @return array{
     *     remove_quality_indicators: bool,
     *     similarity_threshold: int,
     *     fuzzy_max_distance: int,
     *     exact_match_distance: int,
     *     quality_indicators: array<int, string>|null,
     *     trigram_matching_enabled: bool,
     * }
     */
    public function matcherOptionsFromSettings(array $settings): array
    {
        return [
            'remove_quality_indicators' => $settings['remove_quality_indicators'] ?? false,
            'similarity_threshold' => $settings['similarity_threshold'] ?? 70,
            'fuzzy_max_distance' => $settings['fuzzy_max_distance'] ?? 25,
            'exact_match_distance' => $settings['exact_match_distance'] ?? 8,
            'quality_indicators' => ($settings['quality_indicators'] ?? null) ?: null,
            'trigram_matching_enabled' => $settings['trigram_matching_enabled'] ?? false,
        ];
    }

    /**
     * Sanitizes UTF-8 encoding in strings to prevent PostgreSQL errors.
     */
    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Convert to valid UTF-8, removing invalid sequences
        $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Remove control characters that can cause issues
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);

        return $sanitized;
    }

    /**
     * Find the best matching EPG channel for a given channel.
     *
     * @param  array<int, string>|null  $customQualityIndicators  Override the default quality indicators list
     */
    public function findMatchingEpgChannel(
        Channel $channel,
        ?Epg $epg = null,
        bool $removeQualityIndicators = false,
        int $similarityThreshold = 70,
        int $fuzzyMaxDistance = 25,
        int $exactMatchDistance = 8,
        ?array $customQualityIndicators = null,
        ?string $cleanedTitle = null,
        ?string $cleanedName = null,
        ?Collection $prefetchedCandidates = null,
        bool $trigramMatchingEnabled = false,
    ): ?EpgChannel {
        return $this->findEpgChannelCandidates(
            channel: $channel,
            epg: $epg,
            removeQualityIndicators: $removeQualityIndicators,
            similarityThreshold: $similarityThreshold,
            fuzzyMaxDistance: $fuzzyMaxDistance,
            exactMatchDistance: $exactMatchDistance,
            customQualityIndicators: $customQualityIndicators,
            cleanedTitle: $cleanedTitle,
            cleanedName: $cleanedName,
            prefetchedCandidates: $prefetchedCandidates,
            trigramMatchingEnabled: $trigramMatchingEnabled,
        )['automatic_match'];
    }

    /**
     * Compute the ordered search terms used to filter EPG candidates.
     *
     * Exposed so batch callers (Filament review UI, Copilot preview) can union
     * the terms across many channels and preload matching EPG rows in a single
     * LIKE scan instead of one per channel — a meaningful win on very large
     * EPG sources.
     *
     * @return list<string>
     */
    public function searchTermsFor(Channel $channel, ?string $cleanedTitle = null, ?string $cleanedName = null): array
    {
        $title = $this->sanitizeUtf8($cleanedTitle ?? $channel->title_custom ?? $channel->title);
        $name = $this->sanitizeUtf8($cleanedName ?? $channel->name_custom ?? $channel->name);

        return $this->searchTermsForNormalizedName($this->normalizeChannelName(trim($title ?: $name)));
    }

    /**
     * The longest words of a normalized name, shared by searchTermsFor() and
     * findEpgChannelCandidates() so a batch prefetch always covers the terms
     * each channel is later narrowed by.
     *
     * @return list<string>
     */
    private function searchTermsForNormalizedName(string $normalizedName): array
    {
        if (! $normalizedName || mb_strlen($normalizedName, 'UTF-8') < $this->minChannelLength) {
            return [];
        }

        return collect(explode(' ', $normalizedName))
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= $this->minChannelLength)
            ->sortByDesc(fn (string $term): int => mb_strlen($term, 'UTF-8'))
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * Load all EPG candidate rows that match any of the supplied search terms.
     *
     * Intended for batch flows that iterate many channels against the same EPG
     * source: pass the union of every channel's searchTermsFor() result, then
     * feed the returned collection to findEpgChannelCandidates() via the
     * $prefetchedCandidates argument to skip per-channel DB round-trips. Each
     * channel is then only scored against the rows its own terms found (see
     * candidatesMatchingTerms), exactly as its own query would have.
     *
     * @param  list<string>  $unionTerms
     * @return Collection<int, EpgChannel>
     */
    public function loadEpgCandidates(Epg $epg, array $unionTerms, bool $trigramMatchingEnabled = false): Collection
    {
        $unionTerms = collect($unionTerms)
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= $this->minChannelLength)
            ->unique()
            ->values()
            ->all();

        if ($unionTerms === []) {
            return $this->dedupeByPriority(
                $epg,
                $epg->matchableChannels()->select(self::CANDIDATE_COLUMNS)->get(),
            );
        }

        $trigramActive = $trigramMatchingEnabled && $this->trigramAvailable();
        $candidates = [];
        $trigramTerms = [];

        // Batched rather than truncated, so every term is covered even when a
        // caller (like the candidate review) passes a whole map's channels.
        foreach (array_chunk($unionTerms, self::MAX_PREFETCH_TERMS) as $terms) {
            $query = $this->candidateQuery($epg, $terms, $trigramMatchingEnabled);

            if ($trigramActive) {
                // A LIKE match can be re-checked in PHP, but a trigram match
                // can't, so ask Postgres which of these terms each row matched
                // via the same `%` operator and threshold.
                $query->selectRaw(
                    'ARRAY(SELECT term.ordinal FROM unnest(CAST(? AS text[])) WITH ORDINALITY AS term(word, ordinal) WHERE LOWER(channel_id) % term.word OR LOWER(name) % term.word OR LOWER(display_name) % term.word) AS trigram_positions',
                    [$this->postgresTextArray($terms)],
                );
            }

            foreach ($query->get() as $candidate) {
                $candidates[$candidate->id] ??= $candidate;

                if ($trigramActive) {
                    foreach (array_filter(explode(',', trim((string) $candidate->trigram_positions, '{}'))) as $position) {
                        $trigramTerms[$candidate->id][$terms[(int) $position - 1]] = true;
                    }
                    unset($candidate->trigram_positions);
                }
            }
        }

        $pool = $this->dedupeByPriority($epg, new Collection(array_values($candidates)));
        $this->prefetchIndex($pool, $trigramTerms);

        return $pool;
    }

    /**
     * Build (or fetch) the lookup structure candidatesMatchingTerms narrows
     * a prefetched pool with.
     *
     * @param  Collection<int, EpgChannel>  $pool
     * @param  array<int, array<string, true>>  $trigramTermsById
     * @return object{models: list<EpgChannel>, ids: list<int>, priorities: list<int>|null, text: string, starts: list<int>, trigramTerms: array<int, array<string, true>>, termHits: array<string, list<int>>}
     */
    private function prefetchIndex(Collection $pool, array $trigramTermsById = []): object
    {
        $this->prefetchIndexes ??= new WeakMap;

        if (isset($this->prefetchIndexes[$pool])) {
            return $this->prefetchIndexes[$pool];
        }

        $models = $pool->values()->all();
        $trigramTerms = [];
        foreach ($models as $position => $model) {
            if (isset($trigramTermsById[$model->id])) {
                $trigramTerms[$position] = $trigramTermsById[$model->id];
            }
        }

        // Every candidate's searchable text in one string (NUL-separated, so
        // a term of letters and digits can't match across two candidates),
        // with each candidate's start offset, so termPositions() can let
        // strpos() jump between hits instead of testing each text in turn.
        $text = '';
        $starts = [];
        foreach ($models as $model) {
            $starts[] = strlen($text);
            $text .= $this->searchableText($model)."\0";
        }

        return $this->prefetchIndexes[$pool] = (object) [
            'models' => $models,
            'ids' => array_map(fn (EpgChannel $model): int => $model->id, $models),
            'priorities' => null,
            'text' => $text,
            'starts' => $starts,
            'trigramTerms' => $trigramTerms,
            'termHits' => [],
        ];
    }

    /**
     * Positions of the pool candidates a term finds: by substring, like the
     * LIKE conditions, or by Postgres' trigram match.
     *
     * @param  object{models: list<EpgChannel>, ids: list<int>, priorities: list<int>|null, text: string, starts: list<int>, trigramTerms: array<int, array<string, true>>, termHits: array<string, list<int>>}  $index
     * @return list<int>
     */
    private function termPositions(object $index, string $term): array
    {
        $positions = [];
        $offset = 0;

        while ($term !== '' && ($found = strpos($index->text, $term, $offset)) !== false) {
            // Binary search for the candidate whose text holds this offset
            $low = 0;
            $high = count($index->starts) - 1;
            while ($low < $high) {
                $middle = intdiv($low + $high + 1, 2);
                if ($index->starts[$middle] <= $found) {
                    $low = $middle;
                } else {
                    $high = $middle - 1;
                }
            }

            $positions[$low] = true;
            // One hit per candidate is enough, so skip to the next one
            $offset = $index->starts[$low + 1] ?? strlen($index->text);
        }

        foreach ($index->trigramTerms as $position => $trigramTerms) {
            if (isset($trigramTerms[$term])) {
                $positions[$position] = true;
            }
        }

        return array_keys($positions);
    }

    /**
     * Matchable rows of this EPG that at least one term finds: by LIKE on
     * channel_id, name, display_name or an alternate display name, or by
     * pg_trgm similarity when enabled.
     *
     * @param  list<string>  $terms
     */
    private function candidateQuery(Epg $epg, array $terms, bool $trigramMatchingEnabled): Builder
    {
        return $epg->matchableChannels()
            ->where(function (Builder $query) use ($terms, $trigramMatchingEnabled): void {
                if (DB::connection()->getConfig('driver') === 'pgsql') {
                    $this->addPostgresSearchConditions($query, $terms, $trigramMatchingEnabled);

                    return;
                }

                foreach ($terms as $term) {
                    $likeTerm = $this->likePattern($term);
                    $query->orWhereRaw("LOWER(channel_id) LIKE ? ESCAPE '!'", [$likeTerm])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$likeTerm])
                        ->orWhereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", [$likeTerm]);
                    $this->addJsonSearchCondition($query, $term);
                    $this->addTrigramSearchCondition($query, $term, $trigramMatchingEnabled);
                }
            })
            ->select(self::CANDIDATE_COLUMNS);
    }

    /**
     * candidateQuery's conditions written as one `LIKE ANY` (and `% ANY`) per
     * column instead of one condition per term per column. Same rows, but
     * Postgres lowercases each column once per row rather than once per term,
     * which made a batch prefetch's ~100-200 terms about 4x faster.
     *
     * @param  list<string>  $terms
     */
    private function addPostgresSearchConditions(Builder $query, array $terms, bool $trigramMatchingEnabled): void
    {
        // No ESCAPE clause with ANY, so wildcards are escaped with LIKE's
        // default backslash instead of likePattern()'s '!'.
        $patterns = $this->postgresTextArray(array_map(
            fn (string $term): string => '%'.addcslashes($term, '\\%_').'%',
            $terms,
        ));

        $query->whereRaw('LOWER(channel_id) LIKE ANY (CAST(? AS text[]))', [$patterns])
            ->orWhereRaw('LOWER(name) LIKE ANY (CAST(? AS text[]))', [$patterns])
            ->orWhereRaw('LOWER(display_name) LIKE ANY (CAST(? AS text[]))', [$patterns])
            ->orWhereRaw('(additional_display_names IS NOT NULL AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(additional_display_names) AS elem WHERE LOWER(elem) LIKE ANY (CAST(? AS text[]))))', [$patterns]);

        if ($trigramMatchingEnabled && $this->trigramAvailable()) {
            $words = $this->postgresTextArray(array_map(fn (string $term): string => mb_strtolower($term, 'UTF-8'), $terms));

            $query->orWhereRaw('LOWER(channel_id) % ANY (CAST(? AS text[]))', [$words])
                ->orWhereRaw('LOWER(name) % ANY (CAST(? AS text[]))', [$words])
                ->orWhereRaw('LOWER(display_name) % ANY (CAST(? AS text[]))', [$words]);
        }
    }

    /**
     * Encode values as a Postgres text[] literal (each one quoted and
     * escaped), so a list of terms can be bound as a single parameter
     * instead of interpolated into the SQL.
     *
     * @param  list<string>  $values
     */
    private function postgresTextArray(array $values): string
    {
        $quotedValues = array_map(fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values);

        return '{'.implode(',', $quotedValues).'}';
    }

    /**
     * Narrow a batch prefetch (loadEpgCandidates) to what this channel's own
     * query would return: rows matching at least one of its search terms,
     * capped at MAX_DATABASE_CANDIDATES by that query's ranking (source
     * priority, terms matched, id). Scoring every channel against the whole
     * batch instead was slower and let other channels' candidates win
     * (e.g. "Tennis 49: NO EVENT" -> "EVENT 49").
     *
     * @param  Collection<int, EpgChannel>  $candidates
     * @param  list<string>  $searchTerms
     * @return Collection<int, EpgChannel>
     */
    private function candidatesMatchingTerms(Epg $epg, Collection $candidates, array $searchTerms): Collection
    {
        $index = $this->prefetchIndex($candidates);

        // How many of this channel's terms each candidate (by position) matches
        $matched = [];
        foreach ($searchTerms as $term) {
            $index->termHits[$term] ??= $this->termPositions($index, $term);

            foreach ($index->termHits[$term] as $position) {
                $matched[$position] = ($matched[$position] ?? 0) + 1;
            }
        }

        $positions = array_keys($matched);

        // Scoring orders its own results (confidence, then id), so the order
        // here only matters for which candidates survive the cap: the same
        // ones the per-channel query's ORDER BY ... LIMIT would keep.
        if (count($positions) > self::MAX_DATABASE_CANDIDATES) {
            if ($index->priorities === null) {
                $priority = array_flip($epg->matchableEpgIds());
                $index->priorities = array_map(fn (EpgChannel $model): int => $priority[$model->epg_id] ?? PHP_INT_MAX, $index->models);
            }

            $priorities = [];
            $ids = [];
            foreach ($positions as $position) {
                $priorities[] = $index->priorities[$position];
                $ids[] = $index->ids[$position];
            }
            $counts = array_values($matched);

            array_multisort(
                $priorities, SORT_ASC, SORT_NUMERIC,
                $counts, SORT_DESC, SORT_NUMERIC,
                $ids, SORT_ASC, SORT_NUMERIC,
                $positions,
            );
            $positions = array_slice($positions, 0, self::MAX_DATABASE_CANDIDATES);
        }

        return new Collection(array_map(fn (int $position): EpgChannel => $index->models[$position], $positions));
    }

    /**
     * The lowercased fields the candidate LIKE conditions search, joined by
     * newlines so a term (letters and digits only) can't match across two.
     */
    private function searchableText(EpgChannel $candidate): string
    {
        return mb_strtolower(implode("\n", [
            $candidate->channel_id,
            $candidate->name,
            $candidate->display_name,
            ...($candidate->additional_display_names ?? []),
        ]), 'UTF-8');
    }

    /**
     * When an EPG resolves to multiple matchable source EPGs (i.e. a merged EPG),
     * results are already priority-ordered by matchableChannels(); drop lower-priority
     * duplicates of the same channel so a later source can't out-score the master.
     *
     * @param  Collection<int, EpgChannel>  $candidates
     * @return Collection<int, EpgChannel>
     */
    private function dedupeByPriority(Epg $epg, Collection $candidates): Collection
    {
        $ids = $epg->matchableEpgIds();
        if (count($ids) <= 1) {
            return $candidates;
        }

        $priority = array_flip($ids);

        return $candidates
            ->sortBy(fn (EpgChannel $channel): int => $priority[$channel->epg_id] ?? PHP_INT_MAX)
            ->unique(fn (EpgChannel $channel): string => $channel->channel_id ?: $channel->name)
            ->values();
    }

    /**
     * Return the automatic match decision and explainable review candidates from the same scoring pass.
     *
     * `$prefetchedCandidates` lets callers reuse one shared candidate set
     * across many channels (see loadEpgCandidates). When null, the service
     * performs its own bounded LIKE scan against the EPG source.
     *
     * @param  array<int, string>|null  $customQualityIndicators
     * @return array{
     *     original_name: string,
     *     normalized_name: string,
     *     automatic_match: EpgChannel|null,
     *     candidates: list<array{
     *         epg_channel_id: int,
     *         display_name: string,
     *         matched_value: string,
     *         normalized_value: string,
     *         confidence: int,
     *         reason: string
     *     }>,
     *     explanation: string
     * }
     */
    public function findEpgChannelCandidates(
        Channel $channel,
        ?Epg $epg = null,
        bool $removeQualityIndicators = false,
        int $similarityThreshold = 70,
        int $fuzzyMaxDistance = 25,
        int $exactMatchDistance = 8,
        ?array $customQualityIndicators = null,
        ?string $cleanedTitle = null,
        ?string $cleanedName = null,
        ?Collection $prefetchedCandidates = null,
        bool $trigramMatchingEnabled = false,
    ): array {
        $qualityIndicators = array_map(
            'mb_strtolower',
            $customQualityIndicators ?? self::DEFAULT_QUALITY_INDICATORS,
        );

        if ($removeQualityIndicators !== $this->removeQualityIndicators || $qualityIndicators !== $this->qualityIndicators) {
            $this->normalizedNames = [];
        }

        $this->removeQualityIndicators = $removeQualityIndicators;
        $this->qualityIndicators = $qualityIndicators;
        $this->upperFuzzyThreshold = $fuzzyMaxDistance;
        $this->bestFuzzyThreshold = $exactMatchDistance;

        $title = $this->sanitizeUtf8($cleanedTitle ?? $channel->title_custom ?? $channel->title);
        $name = $this->sanitizeUtf8($cleanedName ?? $channel->name_custom ?? $channel->name);
        $fallbackName = trim($title ?: $name);
        $normalizedChan = $this->normalizeChannelName($fallbackName);

        $emptyResult = [
            'original_name' => $fallbackName,
            'normalized_name' => $normalizedChan,
            'automatic_match' => null,
            'candidates' => [],
            'explanation' => __('No candidate had enough normalized name or identifier overlap.'),
        ];

        $searchTerms = $this->searchTermsForNormalizedName($normalizedChan);

        if (! $epg || $searchTerms === []) {
            return $emptyResult;
        }

        if ($prefetchedCandidates !== null) {
            $databaseCandidates = $this->candidatesMatchingTerms($epg, $prefetchedCandidates, $searchTerms);
        } else {
            [$relevanceSql, $relevanceBindings] = $this->candidateRelevanceOrder($searchTerms, $trigramMatchingEnabled);

            $databaseCandidates = $this->dedupeByPriority(
                $epg,
                $this->candidateQuery($epg, $searchTerms, $trigramMatchingEnabled)
                    ->orderByRaw("{$relevanceSql} DESC", $relevanceBindings)
                    ->orderBy('id')
                    ->limit(self::MAX_DATABASE_CANDIDATES)
                    ->get(),
            );
        }

        $regionCode = $epg->preferred_local ? mb_strtolower($epg->preferred_local, 'UTF-8') : null;
        $scoredCandidates = [];

        foreach ($databaseCandidates as $epgChannel) {
            $values = [
                ['field' => 'channel ID', 'value' => $epgChannel->channel_id],
                ['field' => 'name', 'value' => $epgChannel->name],
                ['field' => 'display name', 'value' => $epgChannel->display_name],
            ];

            foreach ($epgChannel->additional_display_names ?? [] as $additionalDisplayName) {
                $values[] = ['field' => 'alternate display name', 'value' => $additionalDisplayName];
            }

            // The previous implementation also compared raw lowercased names
            // (channel fallback vs EPG name/channel_id) as a tiebreaker. It's
            // intentionally omitted here: normalizeChannelName strips stop
            // words and quality indicators symmetrically on both sides, so
            // the raw comparison rarely beat the normalized one, and the new
            // best-of-fields pass plus cosine/containment fallbacks cover the
            // remaining soft-match space more consistently.
            $bestComparison = null;
            foreach ($values as $value) {
                $comparison = $this->compareNormalizedValues($normalizedChan, $value['value'], $value['field']);
                if ($comparison && ($bestComparison === null || $comparison['confidence'] > $bestComparison['confidence'])) {
                    $bestComparison = $comparison;
                }
            }

            if (! $bestComparison || $bestComparison['confidence'] < self::MIN_REVIEW_CONFIDENCE) {
                continue;
            }

            $inPreferredRegion = $regionCode && str_contains(
                mb_strtolower(($epgChannel->channel_id ?? '').' '.($epgChannel->name ?? ''), 'UTF-8'),
                $regionCode,
            );

            if ($inPreferredRegion) {
                // Restore the previous auto-match behavior where preferred_local
                // shifted borderline candidates into the automatic-match band:
                // shave distance by the configured bonus so the meetsDistanceRule
                // gate can fire, in addition to nudging the display confidence.
                $bestComparison['distance'] = max(
                    0,
                    $bestComparison['distance'] - self::PREFERRED_REGION_DISTANCE_BONUS,
                );
                $bestComparison['confidence'] = min(100, $bestComparison['confidence'] + 5);
            }

            $scoredCandidates[] = [
                'model' => $epgChannel,
                'epg_channel_id' => $epgChannel->id,
                'display_name' => $epgChannel->display_name ?: $epgChannel->name ?: $epgChannel->channel_id,
                'preferred_region' => $inPreferredRegion,
                ...$bestComparison,
            ];
        }

        usort($scoredCandidates, fn (array $first, array $second): int => [
            $second['confidence'],
            -$second['epg_channel_id'],
        ] <=> [
            $first['confidence'],
            -$first['epg_channel_id'],
        ]);

        $automaticMatch = null;
        if ($topCandidate = $scoredCandidates[0] ?? null) {
            $meetsDistanceRule = $topCandidate['distance'] < $this->bestFuzzyThreshold
                && $topCandidate['levenshtein_confidence'] >= max(60, $similarityThreshold);
            $meetsWordRule = $topCandidate['distance'] >= $this->bestFuzzyThreshold
                && $topCandidate['distance'] < $this->upperFuzzyThreshold
                && $topCandidate['word_similarity'] >= $this->embedSimThreshold;

            $meetsGuards = $topCandidate['is_exact']
                || $this->agreesWithAnyName($normalizedChan, $topCandidate['model']);

            if (($topCandidate['is_exact'] || $meetsDistanceRule || $meetsWordRule) && $meetsGuards) {
                $automaticMatch = $topCandidate['model'];
            }
        }

        $reviewCandidates = array_map(
            fn (array $candidate): array => [
                'epg_channel_id' => $candidate['epg_channel_id'],
                'display_name' => $candidate['display_name'],
                'matched_value' => $candidate['matched_value'],
                'normalized_value' => $candidate['normalized_value'],
                'confidence' => $candidate['confidence'],
                'reason' => $this->comparisonReason($candidate['reason'], $candidate['field'], $candidate['preferred_region']),
            ],
            array_slice($scoredCandidates, 0, self::MAX_REVIEW_CANDIDATES),
        );

        return [
            'original_name' => $fallbackName,
            'normalized_name' => $normalizedChan,
            'automatic_match' => $automaticMatch,
            'candidates' => $reviewCandidates,
            'explanation' => $reviewCandidates === []
                ? __('No candidate had enough normalized name or identifier overlap.')
                : __('Candidates are ranked from the selected EPG source; confirm borderline matches explicitly.'),
        ];
    }

    /**
     * The review text for a candidate's best comparison. Translated only for
     * the few candidates a result returns, not for every comparison scored.
     */
    private function comparisonReason(string $reason, string $field, bool $preferredRegion): string
    {
        $text = match ($reason) {
            'exact' => __('Exact normalized :field', ['field' => $field]),
            'words' => __('Same normalized words via :field', ['field' => $field]),
            'containment' => __('Strong normalized containment via :field', ['field' => $field]),
            default => __('Similar normalized :field', ['field' => $field]),
        };

        return $preferredRegion ? $text.__('; preferred region') : $text;
    }

    /**
     * @return array{matched_value: string, normalized_value: string, confidence: int, reason: 'similar'|'exact'|'words'|'containment', field: string, distance: int, levenshtein_confidence: int, word_similarity: float, is_exact: bool}|null
     */
    private function compareNormalizedValues(string $normalizedChannel, mixed $candidateValue, string $field): ?array
    {
        if (! is_string($candidateValue) || trim($candidateValue) === '') {
            return null;
        }

        $normalizedCandidate = $this->normalizeChannelName($candidateValue);
        if ($normalizedCandidate === '') {
            return null;
        }

        $compactChannel = str_replace(' ', '', $normalizedChannel);
        $compactCandidate = str_replace(' ', '', $normalizedCandidate);
        $distance = levenshtein($normalizedChannel, $normalizedCandidate);
        $maxLength = max(mb_strlen($normalizedChannel, 'UTF-8'), mb_strlen($normalizedCandidate, 'UTF-8'));
        $levenshteinConfidence = $maxLength > 0 ? max(0, (int) round((1 - ($distance / $maxLength)) * 100)) : 0;
        $wordSimilarity = $this->cosineSimilarity(
            $this->textToVector($normalizedChannel),
            $this->textToVector($normalizedCandidate),
        );
        $confidence = $levenshteinConfidence;
        $reason = 'similar';
        $isExact = $compactChannel === $compactCandidate;

        if ($isExact) {
            $confidence = 100;
            $reason = 'exact';
        } elseif ($wordSimilarity >= $this->embedSimThreshold) {
            $confidence = max($confidence, (int) round($wordSimilarity * 100));
            $reason = 'words';
        } elseif (min(strlen($compactChannel), strlen($compactCandidate)) >= 4
            && (str_contains($compactChannel, $compactCandidate) || str_contains($compactCandidate, $compactChannel))) {
            $confidence = max($confidence, 80);
            $reason = 'containment';
        }

        return [
            'matched_value' => $candidateValue,
            'normalized_value' => $normalizedCandidate,
            'confidence' => $confidence,
            'reason' => $reason,
            'field' => $field,
            'distance' => $distance,
            'levenshtein_confidence' => $levenshteinConfidence,
            'word_similarity' => $wordSimilarity,
            'is_exact' => $isExact,
        ];
    }

    /**
     * Normalize a channel name for similarity comparison.
     */
    private function normalizeChannelName(?string $name): string
    {
        if (! $name) {
            return '';
        }

        if (isset($this->normalizedNames[$name])) {
            return $this->normalizedNames[$name];
        }

        $original = $name;

        // Normalize Unicode compatibility characters (e.g. "ʀᴀᴡ" → "raw", "ＨＤ" → "HD")
        $normalized = \Normalizer::normalize($name, \Normalizer::NFKC);
        if ($normalized !== false) {
            $name = $normalized;
        }

        $name = mb_strtolower($name, 'UTF-8');

        // Remove bracket/parenthesis content (Unicode-aware)
        $name = preg_replace('/\[.*?\]|\(.*?\)/u', '', $name);

        // Keep only letters, numbers, and spaces from all Unicode scripts
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);

        // Normalize whitespace
        $name = preg_replace('/\s+/u', ' ', $name);

        // Remove stop words (they are lowercased English tokens)
        $tokens = explode(' ', $name);
        $tokens = array_filter($tokens, fn ($t) => $t !== '');
        $tokens = array_values(array_diff($tokens, $this->stopWords));

        // Optionally remove quality indicators
        if ($this->removeQualityIndicators) {
            $tokens = array_values(array_diff($tokens, $this->qualityIndicators));
        }

        if (count($this->normalizedNames) >= self::MAX_NORMALIZED_NAMES) {
            $this->normalizedNames = [];
        }

        return $this->normalizedNames[$original] = trim(implode(' ', $tokens));
    }

    /**
     * Whether any of the candidate's names (or, without names, its channel
     * ID) is free of a namesConflict() with the channel. Names rather than
     * the best-scoring field, since channel IDs glue words together
     * ("TigoSport.cr").
     */
    private function agreesWithAnyName(string $normalizedChannel, EpgChannel $candidate): bool
    {
        $names = array_filter([$candidate->name, $candidate->display_name, ...($candidate->additional_display_names ?? [])]);

        foreach ($names ?: [$candidate->channel_id] as $name) {
            if (! $this->namesConflict($normalizedChannel, $this->normalizeChannelName($name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether two normalized names that score as similar are, on closer
     * reading, different channels: their numbers differ ("tsn 42" vs
     * "tsn 12", "itv 1" vs "itv"), or each has a word the other lacks with
     * no typo-like pair between them ("mls 18" vs "tsn 18"). One name only
     * adding words to the other ("radio dallas cowboys" vs "dallas cowboys")
     * is not a conflict. Quality tokens ("4k", "1080p") are ignored either way.
     */
    private function namesConflict(string $normalizedChannel, string $normalizedCandidate): bool
    {
        $ignored = [...self::DEFAULT_QUALITY_INDICATORS, ...$this->qualityIndicators, ...self::BITRATE_VARIANTS];
        $channelTokens = array_values(array_diff(explode(' ', $normalizedChannel), $ignored));
        $candidateTokens = array_values(array_diff(explode(' ', $normalizedCandidate), $ignored));

        if ($this->numbersIn($channelTokens) !== $this->numbersIn($candidateTokens)) {
            return true;
        }

        // Plurals count as the same word ("mysteries" / "mystery")
        $channelWords = array_map($this->singular(...), $channelTokens);
        $candidateWords = array_map($this->singular(...), $candidateTokens);
        $channelOnly = array_diff($channelWords, $candidateWords);
        $candidateOnly = array_diff($candidateWords, $channelWords);

        if ($channelOnly === [] || $candidateOnly === []) {
            return false;
        }

        // A typo is one edit per four letters, so short words like "nhl" and
        // "nfl" must match exactly while "sport"/"sports" still pair up
        foreach ($channelOnly as $channelToken) {
            foreach ($candidateOnly as $candidateToken) {
                $longest = max(mb_strlen($channelToken, 'UTF-8'), mb_strlen($candidateToken, 'UTF-8'));
                if (levenshtein($channelToken, $candidateToken) <= intdiv($longest, 4)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A rough English singular ("mysteries" -> "mystery", "sports" ->
     * "sport"), applied to both names alike so it can only merge plurals.
     * Short words like "cbs" are left alone.
     */
    private function singular(string $word): string
    {
        return match (true) {
            mb_strlen($word, 'UTF-8') <= 3 => $word,
            str_ends_with($word, 'ies') => mb_substr($word, 0, -3, 'UTF-8').'y',
            str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => mb_substr($word, 0, -1, 'UTF-8'),
            default => $word,
        };
    }

    /**
     * Every run of digits in the tokens, without leading zeros, sorted.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function numbersIn(array $tokens): array
    {
        preg_match_all('/\d+/', implode(' ', $tokens), $matches);
        $numbers = array_map(fn (string $number): string => ltrim($number, '0') ?: '0', $matches[0]);
        sort($numbers);

        return $numbers;
    }

    /**
     * Convert a text into a word frequency vector.
     */
    private function textToVector(string $text): array
    {
        return array_count_values(explode(' ', $text));
    }

    /**
     * Calculate the cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0;
        $magA = 0;
        $magB = 0;

        foreach ($vecA as $word => $countA) {
            $countB = $vecB[$word] ?? 0;
            $dotProduct += $countA * $countB;
            $magA += $countA ** 2;
        }

        foreach ($vecB as $countB) {
            $magB += $countB ** 2;
        }

        if ($magA == 0 || $magB == 0) {
            return 0;
        }

        return $dotProduct / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Add database-specific search condition for additional_display_names JSONB column.
     */
    private function addJsonSearchCondition(Builder $query, string $normalizedChan): void
    {
        [$condition, $bindings] = $this->jsonSearchCondition($normalizedChan);

        $query->orWhereRaw($condition, $bindings);
    }

    /**
     * Widen the candidate pool on Postgres using pg_trgm similarity(), so
     * channels without a literal LIKE-able substring match (typos,
     * transliteration differences) can still surface as a candidate. No-op
     * on other drivers, and opt-in per EpgMap via settings.trigram_matching_enabled
     * — the LIKE-based search is unchanged when disabled.
     */
    private function addTrigramSearchCondition(Builder $query, string $term, bool $trigramMatchingEnabled): void
    {
        [$condition, $bindings] = $this->trigramSearchCondition($term, $trigramMatchingEnabled);

        if ($condition === '') {
            return;
        }

        $query->orWhereRaw($condition, $bindings);
    }

    /** @return array{string, list<string>} */
    private function trigramSearchCondition(string $term, bool $trigramMatchingEnabled): array
    {
        if (! $trigramMatchingEnabled || ! $this->trigramAvailable()) {
            return ['', []];
        }

        $lowerTerm = mb_strtolower($term, 'UTF-8');

        // The `%` operator (not the similarity() function) is what lets
        // Postgres use the GIN trgm indexes docker/8.4/db-init.sh creates for
        // the embedded Postgres image - it must match their LOWER(column)
        // expression exactly, or it falls back to a scan. `%`'s similarity
        // cutoff comes from the pg_trgm.similarity_threshold GUC, which this
        // service intentionally never sets - it's a database-level default
        // (db-init.sh sets it via ALTER DATABASE for the embedded image; an
        // externally managed Postgres owns its own default, or falls back to
        // Postgres's built-in default of 0.3 if nobody configured one).
        return [
            '(LOWER(channel_id) % ? OR LOWER(name) % ? OR LOWER(display_name) % ?)',
            [$lowerTerm, $lowerTerm, $lowerTerm],
        ];
    }

    /**
     * Whether pg_trgm is actually installed on this connection - not just
     * "are we on Postgres." No migration or app code installs it; it's set
     * up out-of-band (docker/8.4/db-init.sh for the embedded Postgres image,
     * or manually by whoever administers an external Postgres). Detecting
     * it at runtime means every deployment gets the best matching this
     * connection supports without the app assuming or requiring anything -
     * pg_trgm absent is exactly the same as pre-widening behavior (LIKE-only
     * candidate search), not an error.
     *
     * Cached per instance - see the $trigramAvailable property doc.
     */
    private function trigramAvailable(): bool
    {
        if ($this->trigramAvailable !== null) {
            return $this->trigramAvailable;
        }

        if (DB::connection()->getConfig('driver') !== 'pgsql') {
            return $this->trigramAvailable = false;
        }

        return $this->trigramAvailable = (bool) DB::table('pg_extension')
            ->where('extname', 'pg_trgm')
            ->exists();
    }

    /**
     * Whether trigram matching can actually be turned on for an EpgMap right
     * now - i.e. pg_trgm is installed on this connection. Used by the EpgMap
     * form to disable the "trigram matching" toggle instead of letting a user
     * enable a setting that silently does nothing on their database.
     */
    public function trigramMatchingAvailable(): bool
    {
        return $this->trigramAvailable();
    }

    /**
     * @param  list<string>  $searchTerms
     * @return array{string, list<string>}
     */
    private function candidateRelevanceOrder(array $searchTerms, bool $trigramMatchingEnabled): array
    {
        $expressions = [];
        $bindings = [];

        foreach ($searchTerms as $term) {
            $likeTerm = $this->likePattern($term);
            [$jsonCondition, $jsonBindings] = $this->jsonSearchCondition($term);
            [$trgmCondition, $trgmBindings] = $this->trigramSearchCondition($term, $trigramMatchingEnabled);
            $trgmClause = $trgmCondition === '' ? '' : " OR {$trgmCondition}";
            $expressions[] = "CASE WHEN (LOWER(COALESCE(channel_id, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(display_name, '')) LIKE ? ESCAPE '!' OR {$jsonCondition}{$trgmClause}) THEN 1 ELSE 0 END";
            array_push($bindings, $likeTerm, $likeTerm, $likeTerm, ...$jsonBindings, ...$trgmBindings);
        }

        return [implode(' + ', $expressions), $bindings];
    }

    private function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    /** @return array{string, list<string>} */
    private function jsonSearchCondition(string $term): array
    {
        $driver = DB::connection()->getConfig('driver');
        $likeTerm = $this->likePattern($term);

        return match ($driver) {
            // The IS NOT NULL guards skip the per-row subquery for the (usually
            // most) rows with no alternate names; it can't match those anyway.
            'pgsql' => [
                "(additional_display_names IS NOT NULL AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(additional_display_names) AS elem WHERE LOWER(elem) LIKE ? ESCAPE '!'))",
                [$likeTerm],
            ],
            'mysql', 'mariadb' => [
                "JSON_SEARCH(LOWER(JSON_UNQUOTE(additional_display_names)), 'one', ?, '!') IS NOT NULL",
                [$likeTerm],
            ],
            'sqlite' => [
                "(additional_display_names IS NOT NULL AND EXISTS (SELECT 1 FROM json_each(additional_display_names) WHERE LOWER(json_each.value) LIKE ? ESCAPE '!'))",
                [$likeTerm],
            ],
            default => [
                "LOWER(CAST(additional_display_names AS TEXT)) LIKE ? ESCAPE '!'",
                [$likeTerm],
            ],
        };
    }
}
