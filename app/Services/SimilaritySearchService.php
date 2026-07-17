<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Service to handle similarity search between channels and EPG channels.
 */
class SimilaritySearchService
{
    public const MIN_AUTOMATIC_MATCH_MARGIN = 10;

    private const MAX_DATABASE_CANDIDATES = 250;

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
     * Apply the map's configured prefix or regex cleanup before any matching strategy runs.
     * Used for channel names/titles only — NOT for identifiers.
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
     * Prepare an identifier for matching: only UTF-8 sanitize, trim, and case fold.
     * Prefix/regex cleanup from settings MUST NOT be applied to identifiers.
     */
    public function cleanIdentifierForMatching(?string $value): string
    {
        return mb_strtolower(trim($this->sanitizeUtf8($value) ?? ''), 'UTF-8');
    }

    /**
     * Configure the matcher with settings for subsequent searchTermsFor calls.
     * This sets the instance state without running a full search.
     *
     * @param  array<string, mixed>  $settings
     */
    public function configureForSettings(array $settings): void
    {
        $this->removeQualityIndicators = $settings['remove_quality_indicators'] ?? false;
        $this->upperFuzzyThreshold = $settings['fuzzy_max_distance'] ?? 25;
        $this->bestFuzzyThreshold = $settings['exact_match_distance'] ?? 8;
        $this->qualityIndicators = array_map(
            'mb_strtolower',
            ($settings['quality_indicators'] ?? null) ?: self::DEFAULT_QUALITY_INDICATORS,
        );
    }

    /**
     * Apply one map settings contract to normalization, scoring, retrieval, and decision-making.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function findEpgChannelCandidatesUsingSettings(Channel $channel, Epg $epg, array $settings, ?Collection $prefetchedCandidates = null): array
    {
        return $this->findEpgChannelCandidates(
            channel: $channel,
            epg: $epg,
            removeQualityIndicators: $settings['remove_quality_indicators'] ?? false,
            similarityThreshold: $settings['similarity_threshold'] ?? 70,
            fuzzyMaxDistance: $settings['fuzzy_max_distance'] ?? 25,
            exactMatchDistance: $settings['exact_match_distance'] ?? 8,
            customQualityIndicators: ($settings['quality_indicators'] ?? null) ?: null,
            cleanedTitle: $this->cleanNameForMatching($channel->title_custom ?? $channel->title, $settings),
            cleanedName: $this->cleanNameForMatching($channel->name_custom ?? $channel->name, $settings),
            prefetchedCandidates: $prefetchedCandidates,
            cleanedIdentifier: $this->cleanIdentifierForMatching($channel->stream_id_custom ?? $channel->stream_id),
        );
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
        ?string $cleanedIdentifier = null,
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
            cleanedIdentifier: $cleanedIdentifier,
        )['automatic_match'];
    }

    /**
     * Compute the ordered search terms used to filter EPG candidates.
     *
     * Exposed for compatibility with callers that build bounded candidate
     * previews outside the automatic decision path.
     *
     * @return list<string>
     */
    public function searchTermsFor(Channel $channel, ?string $cleanedTitle = null, ?string $cleanedName = null): array
    {
        $title = $this->sanitizeUtf8($cleanedTitle ?? $channel->title_custom ?? $channel->title);
        $name = $this->sanitizeUtf8($cleanedName ?? $channel->name_custom ?? $channel->name);
        $normalized = $this->normalizeChannelName(trim($title ?: $name));

        if (! $normalized || mb_strlen($normalized, 'UTF-8') < $this->minChannelLength) {
            return [];
        }

        return collect(explode(' ', $normalized))
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= $this->minChannelLength)
            ->sortByDesc(fn (string $term): int => mb_strlen($term, 'UTF-8'))
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * Load a bounded EPG candidate preview matching any supplied search term.
     *
     * Automatic decisions independently use their canonical per-channel query,
     * preventing this preview's term scope from changing confidence.
     *
     * @param  list<string>  $unionTerms
     * @return Collection<int, EpgChannel>
     */
    public function loadEpgCandidates(Epg $epg, array $unionTerms): Collection
    {
        $unionTerms = collect($unionTerms)
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= $this->minChannelLength)
            ->unique()
            ->take(200)
            ->values()
            ->all();

        if ($unionTerms === []) {
            return $epg->channels()
                ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
                ->orderBy('id')
                ->limit(self::MAX_DATABASE_CANDIDATES)
                ->get();
        }

        [$relevanceSql, $relevanceBindings] = $this->candidateRelevanceOrder($unionTerms);

        return $epg->channels()
            ->where(function (Builder $query) use ($unionTerms): void {
                foreach ($unionTerms as $term) {
                    $likeTerm = $this->likePattern($term);
                    $query->orWhereRaw("LOWER(channel_id) LIKE ? ESCAPE '!'", [$likeTerm])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$likeTerm])
                        ->orWhereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", [$likeTerm]);
                    $this->addJsonSearchCondition($query, $term);
                }
            })
            ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->orderByRaw("{$relevanceSql} DESC", $relevanceBindings)
            ->orderBy('id')
            ->limit(self::MAX_DATABASE_CANDIDATES)
            ->get();
    }

    /**
     * Return the automatic match decision and explainable review candidates from the same scoring pass.
     *
     * `$prefetchedCandidates` remains accepted for public compatibility. The
     * automatic decision always uses the canonical bounded selected-source
     * query so page-local or batch-local prefetch scope cannot alter confidence.
     *
     * @param  array<int, string>|null  $customQualityIndicators
     * @return array{
     *     original_name: string,
     *     normalized_name: string,
     *     automatic_match: EpgChannel|null,
     *     decision: string,
     *     candidates: list<array{
     *         epg_channel_id: int,
     *         display_name: string,
     *         matched_value: string,
     *         normalized_value: string,
     *         confidence: int,
     *         reason: string,
     *         evidence: string
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
        ?string $cleanedIdentifier = null,
    ): array {
        $this->removeQualityIndicators = $removeQualityIndicators;
        $this->upperFuzzyThreshold = $fuzzyMaxDistance;
        $this->bestFuzzyThreshold = $exactMatchDistance;

        $this->qualityIndicators = array_map(
            'mb_strtolower',
            $customQualityIndicators ?? self::DEFAULT_QUALITY_INDICATORS,
        );

        $title = $this->sanitizeUtf8($cleanedTitle ?? $channel->title_custom ?? $channel->title);
        $name = $this->sanitizeUtf8($cleanedName ?? $channel->name_custom ?? $channel->name);
        $fallbackName = trim($title ?: $name);
        $normalizedChan = $this->normalizeChannelName($fallbackName);
        $identifier = mb_strtolower(trim($this->sanitizeUtf8(
            $cleanedIdentifier ?? $channel->stream_id_custom ?? $channel->stream_id,
        ) ?? ''), 'UTF-8');
        $originalTitle = trim($this->sanitizeUtf8($channel->title_custom ?? $channel->title) ?? '');
        $originalName = trim($this->sanitizeUtf8($channel->name_custom ?? $channel->name) ?? '');
        $callsign = mb_strtolower($this->extractCallsign($originalTitle ?: $originalName) ?? '', 'UTF-8');

        $emptyResult = [
            'original_name' => $fallbackName,
            'normalized_name' => $normalizedChan,
            'automatic_match' => null,
            'decision' => 'no_candidates',
            'candidates' => [],
            'explanation' => __('No candidate had enough normalized name or identifier overlap.'),
        ];

        $hasUsableName = $normalizedChan && mb_strlen($normalizedChan, 'UTF-8') >= $this->minChannelLength;

        if (! $epg || (! $hasUsableName && $identifier === '' && $callsign === '')) {
            return $emptyResult;
        }

        $searchTerms = collect(explode(' ', $hasUsableName ? $normalizedChan : ''))
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= $this->minChannelLength)
            ->sortByDesc(fn (string $term): int => mb_strlen($term, 'UTF-8'))
            ->take(4)
            ->values();

        if ($searchTerms->isEmpty() && $identifier === '' && $callsign === '') {
            return $emptyResult;
        }

        [$relevanceSql, $relevanceBindings] = $this->candidateRelevanceOrder($searchTerms->all(), $identifier, $callsign);

        $databaseCandidates = $prefetchedCandidates
            ? $this->filterPrefetchedCandidates($prefetchedCandidates, $searchTerms, $identifier, $callsign, $epg)
            : $this->fetchBoundedCandidates($epg, $callsign, $identifier, $searchTerms, $relevanceSql, $relevanceBindings);

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

            $isIdentifierMatch = $identifier !== ''
                && mb_strtolower(trim((string) $epgChannel->channel_id), 'UTF-8') === $identifier;
            $callsignValue = collect([$epgChannel->channel_id, $epgChannel->name, $epgChannel->display_name])
                ->first(fn (mixed $value): bool => $this->valueMatchesCallsign($value, $callsign));

            // The previous implementation also compared raw lowercased names
            // (channel fallback vs EPG name/channel_id) as a tiebreaker. It's
            // intentionally omitted here: normalizeChannelName strips stop
            // words and quality indicators symmetrically on both sides, so
            // the raw comparison rarely beat the normalized one, and the new
            // best-of-fields pass plus cosine/containment fallbacks cover the
            // remaining soft-match space more consistently.
            if ($isIdentifierMatch) {
                $bestComparison = [
                    'matched_value' => $epgChannel->channel_id,
                    'normalized_value' => $identifier,
                    'confidence' => 100,
                    'reason' => __('Exact channel identifier'),
                    'evidence' => 'identifier',
                    'distance' => 0,
                    'levenshtein_confidence' => 100,
                    'word_similarity' => 1.0,
                    'is_exact' => true,
                ];
            } elseif ($callsignValue !== null) {
                $bestComparison = [
                    'matched_value' => $callsignValue,
                    'normalized_value' => $callsign,
                    'confidence' => 100,
                    'reason' => __('Exact callsign evidence'),
                    'evidence' => 'callsign',
                    'distance' => 0,
                    'levenshtein_confidence' => 100,
                    'word_similarity' => 1.0,
                    'is_exact' => true,
                ];
            } else {
                $bestComparison = null;
                foreach ($values as $value) {
                    $comparison = $this->compareNormalizedValues($normalizedChan, $value['value'], $value['field']);
                    if ($comparison && ($bestComparison === null || $comparison['confidence'] > $bestComparison['confidence'])) {
                        $bestComparison = $comparison;
                    }
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
                $bestComparison['reason'] .= __('; preferred region');
            }

            $scoredCandidates[] = [
                'model' => $epgChannel,
                'epg_channel_id' => $epgChannel->id,
                'display_name' => $epgChannel->display_name ?: $epgChannel->name ?: $epgChannel->channel_id,
                ...$bestComparison,
            ];
        }

        usort($scoredCandidates, fn (array $first, array $second): int => [
            $this->evidencePriority($second['evidence']),
            $second['confidence'],
            -$second['epg_channel_id'],
        ] <=> [
            $this->evidencePriority($first['evidence']),
            $first['confidence'],
            -$first['epg_channel_id'],
        ]);

        // Verify decision completeness by running targeted queries for any
        // decision-critical evidence that might exist beyond the bounded window.
        // This ensures decisions don't depend on arbitrary row cutoffs.
        $scoredCandidates = $this->verifyDecisionCompleteness(
            epg: $epg,
            scoredCandidates: $scoredCandidates,
            identifier: $identifier,
            callsign: $callsign,
            normalizedChan: $normalizedChan,
            regionCode: $regionCode,
            similarityThreshold: $similarityThreshold,
        );

        usort($scoredCandidates, fn (array $first, array $second): int => [
            $this->evidencePriority($second['evidence']),
            $second['confidence'],
            -$second['epg_channel_id'],
        ] <=> [
            $this->evidencePriority($first['evidence']),
            $first['confidence'],
            -$first['epg_channel_id'],
        ]);

        $automaticMatch = null;
        $decision = 'review_only';
        if ($topCandidate = $scoredCandidates[0] ?? null) {
            $identifierMatches = collect($scoredCandidates)
                ->filter(fn (array $candidate): bool => $candidate['evidence'] === 'identifier');
            $hasIdentifierConflict = collect($scoredCandidates)
                ->contains(fn (array $candidate): bool => ! in_array($candidate['evidence'], ['identifier', 'callsign'], true)
                    && $candidate['is_exact']
                    && $this->candidateIdentity($candidate) !== $this->candidateIdentity($topCandidate));
            $exactNameIdentities = collect($scoredCandidates)
                ->filter(fn (array $candidate): bool => ! in_array($candidate['evidence'], ['identifier', 'callsign'], true) && $candidate['is_exact'])
                ->map(fn (array $candidate): string => $this->candidateIdentity($candidate))
                ->unique();
            $callsignIdentities = collect($scoredCandidates)
                ->filter(fn (array $candidate): bool => $candidate['evidence'] === 'callsign')
                ->map(fn (array $candidate): string => $this->candidateIdentity($candidate))
                ->unique();
            $runnerUp = collect($scoredCandidates)
                ->first(fn (array $candidate): bool => $this->candidateIdentity($candidate) !== $this->candidateIdentity($topCandidate));
            $hasSafeMargin = $runnerUp === null
                || $topCandidate['confidence'] - $runnerUp['confidence'] >= self::MIN_AUTOMATIC_MATCH_MARGIN;
            $meetsDistanceRule = $topCandidate['distance'] < $this->bestFuzzyThreshold
                && $topCandidate['levenshtein_confidence'] >= max(60, $similarityThreshold);
            $meetsWordRule = $topCandidate['distance'] >= $this->bestFuzzyThreshold
                && $topCandidate['distance'] < $this->upperFuzzyThreshold
                && $topCandidate['word_similarity'] >= $this->embedSimThreshold;

            if ($topCandidate['evidence'] === 'identifier' && $identifierMatches->count() > 1) {
                $decision = 'ambiguous_identifier';
            } elseif ($topCandidate['evidence'] === 'identifier') {
                $automaticMatch = $topCandidate['model'];
                $decision = $hasIdentifierConflict ? 'identifier_conflict' : 'exact_identifier';
            } elseif ($topCandidate['evidence'] === 'callsign' && $callsignIdentities->count() > 1) {
                $decision = 'ambiguous_callsign';
            } elseif ($topCandidate['evidence'] === 'callsign') {
                $automaticMatch = $topCandidate['model'];
                $decision = 'exact_callsign';
            } elseif ($topCandidate['is_exact'] && $exactNameIdentities->count() > 1) {
                $decision = 'ambiguous_name';
            } elseif ($topCandidate['is_exact']) {
                $automaticMatch = $topCandidate['model'];
                $decision = 'exact_name';
            } elseif (($meetsDistanceRule || $meetsWordRule) && ! $hasSafeMargin) {
                $decision = 'insufficient_margin';
            } elseif ($meetsDistanceRule || $meetsWordRule) {
                $automaticMatch = $topCandidate['model'];
                $decision = 'soft_match';
            }
        } else {
            $decision = 'no_candidates';
        }

        $reviewCandidates = array_map(
            fn (array $candidate): array => collect($candidate)
                ->except(['model', 'distance', 'levenshtein_confidence', 'word_similarity', 'is_exact'])
                ->all(),
            array_slice($scoredCandidates, 0, self::MAX_REVIEW_CANDIDATES),
        );

        return [
            'original_name' => $fallbackName,
            'normalized_name' => $normalizedChan,
            'automatic_match' => $automaticMatch,
            'decision' => $decision,
            'candidates' => $reviewCandidates,
            'explanation' => match ($decision) {
                'exact_identifier' => __('An exact selected-source identifier match took precedence over name evidence.'),
                'identifier_conflict' => __('The exact identifier took precedence over a conflicting name winner from the selected EPG source.'),
                'ambiguous_identifier' => __('The exact identifier matched multiple rows in the selected EPG source, so no automatic match was made.'),
                'exact_callsign' => __('An unambiguous callsign match was found in the selected EPG source.'),
                'ambiguous_callsign' => __('The callsign matched multiple identifiers in the selected EPG source, so no automatic match was made.'),
                'ambiguous_name' => __('The selected EPG source contains the same normalized name under multiple identifiers, so no automatic match was made.'),
                'insufficient_margin' => __('The leading soft candidate did not clear the required confidence margin, so no automatic match was made.'),
                'no_candidates' => __('No candidate had enough normalized name or identifier overlap.'),
                default => __('Candidates are ranked from the selected EPG source; confirm borderline matches explicitly.'),
            },
        ];
    }

    /**
     * Fetch the bounded candidate set using the canonical query.
     *
     * @param  list<string>  $searchTerms
     * @return Collection<int, EpgChannel>
     */
    private function fetchBoundedCandidates(
        Epg $epg,
        string $callsign,
        string $identifier,
        SupportCollection $searchTerms,
        string $relevanceSql,
        array $relevanceBindings,
    ): Collection {
        return $epg->channels()
            ->where(function (Builder $query) use ($callsign, $identifier, $searchTerms): void {
                if ($identifier !== '') {
                    $query->orWhereRaw('TRIM(LOWER(channel_id)) = ?', [$identifier]);
                }

                if ($callsign !== '') {
                    $callsignPrefix = $callsign.'-%';
                    $query->orWhereRaw('TRIM(LOWER(channel_id)) = ?', [$callsign])
                        ->orWhereRaw('TRIM(LOWER(channel_id)) LIKE ?', [$callsignPrefix])
                        ->orWhereRaw('TRIM(LOWER(name)) = ?', [$callsign])
                        ->orWhereRaw('TRIM(LOWER(name)) LIKE ?', [$callsignPrefix])
                        ->orWhereRaw('TRIM(LOWER(display_name)) = ?', [$callsign])
                        ->orWhereRaw('TRIM(LOWER(display_name)) LIKE ?', [$callsignPrefix]);
                }

                foreach ($searchTerms as $term) {
                    $likeTerm = $this->likePattern($term);
                    $query->orWhereRaw("TRIM(LOWER(channel_id)) LIKE ? ESCAPE '!'", [$likeTerm])
                        ->orWhereRaw("TRIM(LOWER(name)) LIKE ? ESCAPE '!'", [$likeTerm])
                        ->orWhereRaw("TRIM(LOWER(display_name)) LIKE ? ESCAPE '!'", [$likeTerm]);
                    $this->addJsonSearchCondition($query, $term);
                }
            })
            ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->orderByRaw("{$relevanceSql} DESC", $relevanceBindings)
            ->orderBy('id')
            ->limit(self::MAX_DATABASE_CANDIDATES)
            ->get();
    }

    /**
     * Convert a SQL LIKE pattern (with ! as escape char) to a PHP regex pattern.
     */
    private function likePatternToRegex(string $likePattern): string
    {
        // Escape regex special chars
        $regex = preg_quote($likePattern, '/');
        // Convert SQL LIKE wildcards to regex (%, _ are not regex special chars, so no backslash)
        $regex = str_replace(['%', '_'], ['.*', '.'], $regex);

        // Handle the escape character ! (not needed in regex since we already escaped)
        return '/^'.$regex.'$/i';
    }

    /**
     * Filter a pre-fetched candidate collection to those relevant for a specific channel.
     * This avoids running a new database query when a shared candidate set is available.
     *
     * @param  Collection<int, EpgChannel>  $prefetchedCandidates
     * @param  list<string>  $searchTerms
     * @return Collection<int, EpgChannel>
     */
    private function filterPrefetchedCandidates(
        Collection $prefetchedCandidates,
        SupportCollection $searchTerms,
        string $identifier,
        string $callsign,
        ?Epg $epg = null,
    ): Collection {
        // First filter by EPG source if provided
        if ($epg !== null) {
            $prefetchedCandidates = $prefetchedCandidates->filter(fn (EpgChannel $c): bool => $c->epg_id === $epg->id)->values();
        }

        $regexPatterns = [];
        foreach ($searchTerms as $term) {
            $likeTerm = $this->likePattern($term);
            $regexPatterns[] = $this->likePatternToRegex($likeTerm);
        }

        $result = $prefetchedCandidates->filter(function (EpgChannel $epgChannel) use ($regexPatterns, $identifier, $callsign): bool {
            // Exact identifier match
            if ($identifier !== '' && mb_strtolower(trim((string) $epgChannel->channel_id), 'UTF-8') === $identifier) {
                return true;
            }

            // Exact callsign match
            if ($callsign !== '') {
                $callsignLower = $callsign;
                $channelId = mb_strtolower(trim((string) $epgChannel->channel_id), 'UTF-8');
                $name = mb_strtolower(trim((string) $epgChannel->name), 'UTF-8');
                $displayName = mb_strtolower(trim((string) $epgChannel->display_name), 'UTF-8');

                if ($channelId === $callsignLower || str_starts_with($channelId, $callsignLower.'-')
                    || $name === $callsignLower || str_starts_with($name, $callsignLower.'-')
                    || $displayName === $callsignLower || str_starts_with($displayName, $callsignLower.'-')) {
                    return true;
                }
            }

            // Search term matches (name, display_name, channel_id, additional_display_names)
            $channelId = mb_strtolower(trim((string) $epgChannel->channel_id), 'UTF-8');
            $name = mb_strtolower(trim((string) $epgChannel->name), 'UTF-8');
            $displayName = mb_strtolower(trim((string) $epgChannel->display_name), 'UTF-8');

            foreach ($regexPatterns as $regex) {
                if (preg_match($regex, $channelId) || preg_match($regex, $name) || preg_match($regex, $displayName)) {
                    return true;
                }

                // Check additional_display_names
                foreach ($epgChannel->additional_display_names ?? [] as $additional) {
                    if (preg_match($regex, mb_strtolower(trim((string) $additional), 'UTF-8'))) {
                        return true;
                    }
                }
            }

            return false;
        })->values();

        return $result;
    }

    /**
     * Verify decision completeness by running targeted queries for any
     * decision-critical evidence that might exist beyond the bounded window.
     *
     * This ensures exact identifier, callsign, duplicate normalized identity,
     * ambiguity, and soft margin decisions are provably complete regardless
     * of where rows fall relative to the MAX_DATABASE_CANDIDATES limit.
     *
     * @param  list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>  $scoredCandidates
     * @return list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>
     */
    private function verifyDecisionCompleteness(
        Epg $epg,
        array $scoredCandidates,
        string $identifier,
        string $callsign,
        string $normalizedChan,
        ?string $regionCode,
        int $similarityThreshold,
    ): array {
        if ($scoredCandidates === []) {
            return $scoredCandidates;
        }

        $topCandidate = $scoredCandidates[0];

        // 1. If top candidate has identifier evidence, verify no other rows
        //    share the same identifier (ambiguous_identifier detection).
        if ($identifier !== '' && $topCandidate['evidence'] === 'identifier') {
            $scoredCandidates = $this->verifyIdentifierCompleteness($epg, $scoredCandidates, $identifier, $regionCode);
        }

        // 2. If top candidate has callsign evidence, verify no other rows
        //    share the same callsign (ambiguous_callsign detection).
        if ($callsign !== '' && $topCandidate['evidence'] === 'callsign') {
            $scoredCandidates = $this->verifyCallsignCompleteness($epg, $scoredCandidates, $callsign, $regionCode);
        }

        // 3. If top candidate is an exact name match, verify no other rows
        //    share the same normalized name but different channel_id
        //    (ambiguous_name detection).
        if ($topCandidate['is_exact'] && $topCandidate['evidence'] !== 'identifier' && $topCandidate['evidence'] !== 'callsign') {
            $scoredCandidates = $this->verifyExactNameCompleteness($epg, $scoredCandidates, $normalizedChan, $topCandidate, $regionCode, $similarityThreshold);
        }

        // 4. If top candidate is a soft match, verify we have the true
        //    runner-up by confidence (insufficient_margin detection).
        if (($topCandidate['evidence'] !== 'identifier' && $topCandidate['evidence'] !== 'callsign' && ! $topCandidate['is_exact'])
            && ($topCandidate['distance'] < $this->upperFuzzyThreshold)) {
            $scoredCandidates = $this->verifySoftMarginCompleteness($epg, $scoredCandidates, $normalizedChan, $topCandidate, $regionCode, $similarityThreshold);
        }

        return $scoredCandidates;
    }

    /**
     * Verify no other rows share the same identifier.
     *
     * @param  list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>  $scoredCandidates
     * @return list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>
     */
    private function verifyIdentifierCompleteness(
        Epg $epg,
        array $scoredCandidates,
        string $identifier,
        ?string $regionCode,
    ): array {
        $existingIds = collect($scoredCandidates)->pluck('epg_channel_id')->all();

        $additional = $epg->channels()
            ->whereRaw('TRIM(LOWER(channel_id)) = ?', [$identifier])
            ->whereNotIn('id', $existingIds)
            ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->get();

        foreach ($additional as $epgChannel) {
            $scoredCandidates[] = [
                'model' => $epgChannel,
                'epg_channel_id' => $epgChannel->id,
                'display_name' => $epgChannel->display_name ?: $epgChannel->name ?: $epgChannel->channel_id,
                'matched_value' => $epgChannel->channel_id,
                'normalized_value' => $identifier,
                'confidence' => 100,
                'reason' => __('Exact channel identifier'),
                'evidence' => 'identifier',
                'distance' => 0,
                'levenshtein_confidence' => 100,
                'word_similarity' => 1.0,
                'is_exact' => true,
            ];
        }

        return $scoredCandidates;
    }

    /**
     * Verify no other rows share the same callsign.
     *
     * @param  list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>  $scoredCandidates
     * @return list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>
     */
    private function verifyCallsignCompleteness(
        Epg $epg,
        array $scoredCandidates,
        string $callsign,
        ?string $regionCode,
    ): array {
        $existingIds = collect($scoredCandidates)->pluck('epg_channel_id')->all();
        $callsignPrefix = $callsign.'-%';

        $additional = $epg->channels()
            ->where(function (Builder $query) use ($callsign, $callsignPrefix): void {
                $query->whereRaw('TRIM(LOWER(channel_id)) = ?', [$callsign])
                    ->orWhereRaw('TRIM(LOWER(channel_id)) LIKE ?', [$callsignPrefix])
                    ->orWhereRaw('TRIM(LOWER(name)) = ?', [$callsign])
                    ->orWhereRaw('TRIM(LOWER(name)) LIKE ?', [$callsignPrefix])
                    ->orWhereRaw('TRIM(LOWER(display_name)) = ?', [$callsign])
                    ->orWhereRaw('TRIM(LOWER(display_name)) LIKE ?', [$callsignPrefix]);
            })
            ->whereNotIn('id', $existingIds)
            ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->get();

        foreach ($additional as $epgChannel) {
            $callsignValue = collect([$epgChannel->channel_id, $epgChannel->name, $epgChannel->display_name])
                ->first(fn (mixed $value): bool => $this->valueMatchesCallsign($value, $callsign));

            if ($callsignValue !== null) {
                $scoredCandidates[] = [
                    'model' => $epgChannel,
                    'epg_channel_id' => $epgChannel->id,
                    'display_name' => $epgChannel->display_name ?: $epgChannel->name ?: $epgChannel->channel_id,
                    'matched_value' => $callsignValue,
                    'normalized_value' => $callsign,
                    'confidence' => 100,
                    'reason' => __('Exact callsign evidence'),
                    'evidence' => 'callsign',
                    'distance' => 0,
                    'levenshtein_confidence' => 100,
                    'word_similarity' => 1.0,
                    'is_exact' => true,
                ];
            }
        }

        return $scoredCandidates;
    }

    /**
     * Verify no other rows share the same normalized name but different channel_id.
     *
     * @param  list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>  $scoredCandidates
     * @return list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>
     */
    private function verifyExactNameCompleteness(
        Epg $epg,
        array $scoredCandidates,
        string $normalizedChan,
        array $topCandidate,
        ?string $regionCode,
        int $similarityThreshold,
    ): array {
        $topIdentity = $this->candidateIdentity($topCandidate);
        $existingIds = collect($scoredCandidates)->pluck('epg_channel_id')->all();

        // Find candidates with same normalized name but different channel_id
        $additional = $epg->channels()
            ->whereNotIn('id', $existingIds)
            ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->get()
            ->filter(function (EpgChannel $epgChannel) use ($normalizedChan, $topIdentity): bool {
                $values = [
                    ['field' => 'channel ID', 'value' => $epgChannel->channel_id],
                    ['field' => 'name', 'value' => $epgChannel->name],
                    ['field' => 'display name', 'value' => $epgChannel->display_name],
                ];
                foreach ($epgChannel->additional_display_names ?? [] as $additionalDisplayName) {
                    $values[] = ['field' => 'alternate display name', 'value' => $additionalDisplayName];
                }

                foreach ($values as $value) {
                    $comparison = $this->compareNormalizedValues($normalizedChan, $value['value'], $value['field']);
                    if ($comparison && $comparison['is_exact'] && $this->candidateIdentity([
                        'model' => $epgChannel,
                        'epg_channel_id' => $epgChannel->id,
                    ]) !== $topIdentity) {
                        return true;
                    }
                }

                return false;
            });

        foreach ($additional as $epgChannel) {
            $values = [
                ['field' => 'channel ID', 'value' => $epgChannel->channel_id],
                ['field' => 'name', 'value' => $epgChannel->name],
                ['field' => 'display name', 'value' => $epgChannel->display_name],
            ];
            foreach ($epgChannel->additional_display_names ?? [] as $additionalDisplayName) {
                $values[] = ['field' => 'alternate display name', 'value' => $additionalDisplayName];
            }

            $bestComparison = null;
            foreach ($values as $value) {
                $comparison = $this->compareNormalizedValues($normalizedChan, $value['value'], $value['field']);
                if ($comparison && ($bestComparison === null || $comparison['confidence'] > $bestComparison['confidence'])) {
                    $bestComparison = $comparison;
                }
            }

            if ($bestComparison && $bestComparison['confidence'] >= self::MIN_REVIEW_CONFIDENCE) {
                $inPreferredRegion = $regionCode && str_contains(
                    mb_strtolower(($epgChannel->channel_id ?? '').' '.($epgChannel->name ?? ''), 'UTF-8'),
                    $regionCode,
                );
                if ($inPreferredRegion) {
                    $bestComparison['distance'] = max(0, $bestComparison['distance'] - self::PREFERRED_REGION_DISTANCE_BONUS);
                    $bestComparison['confidence'] = min(100, $bestComparison['confidence'] + 5);
                    $bestComparison['reason'] .= __('; preferred region');
                }

                $scoredCandidates[] = [
                    'model' => $epgChannel,
                    'epg_channel_id' => $epgChannel->id,
                    'display_name' => $epgChannel->display_name ?: $epgChannel->name ?: $epgChannel->channel_id,
                    ...$bestComparison,
                ];
            }
        }

        return $scoredCandidates;
    }

    /**
     * Verify we have the true runner-up by confidence for soft matches.
     *
     * @param  list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>  $scoredCandidates
     * @return list<array{model: EpgChannel, epg_channel_id: int, evidence: string, confidence: int, is_exact: bool, matched_value: string, normalized_value: string, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, display_name: string}>
     */
    private function verifySoftMarginCompleteness(
        Epg $epg,
        array $scoredCandidates,
        string $normalizedChan,
        array $topCandidate,
        ?string $regionCode,
        int $similarityThreshold,
    ): array {
        $topIdentity = $this->candidateIdentity($topCandidate);
        $existingIds = collect($scoredCandidates)->pluck('epg_channel_id')->all();

        // Get the current runner-up confidence (different identity)
        $currentRunnerUp = collect($scoredCandidates)
            ->first(fn (array $candidate): bool => $this->candidateIdentity($candidate) !== $topIdentity);

        if ($currentRunnerUp === null) {
            // No runner-up in current set, need to check if one exists beyond the bound
            $runnerUpConfidence = 0;
        } else {
            $runnerUpConfidence = $currentRunnerUp['confidence'];
        }

        // We only need to check if there's a candidate with confidence >
        // runnerUpConfidence that could change the margin decision.
        // Since we're looking for the true second-best, we check all remaining
        // rows but only keep those that could beat the current runner-up.

        // For efficiency, we do a full scan of remaining rows but only for
        // candidates that could potentially have higher confidence than the
        // current runner-up. This is a bounded operation since we only scan
        // rows not already in our candidate set.

        $additional = $epg->channels()
            ->whereNotIn('id', $existingIds)
            ->select('id', 'epg_id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->get()
            ->filter(function (EpgChannel $epgChannel) use ($normalizedChan, $topIdentity, $runnerUpConfidence, $similarityThreshold): bool {
                $values = [
                    ['field' => 'channel ID', 'value' => $epgChannel->channel_id],
                    ['field' => 'name', 'value' => $epgChannel->name],
                    ['field' => 'display name', 'value' => $epgChannel->display_name],
                ];
                foreach ($epgChannel->additional_display_names ?? [] as $additionalDisplayName) {
                    $values[] = ['field' => 'alternate display name', 'value' => $additionalDisplayName];
                }

                $bestComparison = null;
                foreach ($values as $value) {
                    $comparison = $this->compareNormalizedValues($normalizedChan, $value['value'], $value['field']);
                    if ($comparison && ($bestComparison === null || $comparison['confidence'] > $bestComparison['confidence'])) {
                        $bestComparison = $comparison;
                    }
                }

                if (! $bestComparison || $bestComparison['confidence'] < self::MIN_REVIEW_CONFIDENCE) {
                    return false;
                }

                if ($bestComparison['confidence'] <= $runnerUpConfidence) {
                    return false;
                }

                if ($this->candidateIdentity([
                    'model' => $epgChannel,
                    'epg_channel_id' => $epgChannel->id,
                ]) === $topIdentity) {
                    return false;
                }

                // Check if it meets soft match criteria
                $meetsDistanceRule = $bestComparison['distance'] < $this->bestFuzzyThreshold
                    && $bestComparison['levenshtein_confidence'] >= max(60, $similarityThreshold);
                $meetsWordRule = $bestComparison['distance'] >= $this->bestFuzzyThreshold
                    && $bestComparison['distance'] < $this->upperFuzzyThreshold
                    && $bestComparison['word_similarity'] >= $this->embedSimThreshold;

                return $meetsDistanceRule || $meetsWordRule;
            });

        foreach ($additional as $epgChannel) {
            $values = [
                ['field' => 'channel ID', 'value' => $epgChannel->channel_id],
                ['field' => 'name', 'value' => $epgChannel->name],
                ['field' => 'display name', 'value' => $epgChannel->display_name],
            ];
            foreach ($epgChannel->additional_display_names ?? [] as $additionalDisplayName) {
                $values[] = ['field' => 'alternate display name', 'value' => $additionalDisplayName];
            }

            $bestComparison = null;
            foreach ($values as $value) {
                $comparison = $this->compareNormalizedValues($normalizedChan, $value['value'], $value['field']);
                if ($comparison && ($bestComparison === null || $comparison['confidence'] > $bestComparison['confidence'])) {
                    $bestComparison = $comparison;
                }
            }

            if ($bestComparison && $bestComparison['confidence'] >= self::MIN_REVIEW_CONFIDENCE) {
                $inPreferredRegion = $regionCode && str_contains(
                    mb_strtolower(($epgChannel->channel_id ?? '').' '.($epgChannel->name ?? ''), 'UTF-8'),
                    $regionCode,
                );
                if ($inPreferredRegion) {
                    $bestComparison['distance'] = max(0, $bestComparison['distance'] - self::PREFERRED_REGION_DISTANCE_BONUS);
                    $bestComparison['confidence'] = min(100, $bestComparison['confidence'] + 5);
                    $bestComparison['reason'] .= __('; preferred region');
                }

                $scoredCandidates[] = [
                    'model' => $epgChannel,
                    'epg_channel_id' => $epgChannel->id,
                    'display_name' => $epgChannel->display_name ?: $epgChannel->name ?: $epgChannel->channel_id,
                    ...$bestComparison,
                ];
            }
        }

        return $scoredCandidates;
    }

    /**
     * @return array{matched_value: string, normalized_value: string, confidence: int, reason: string, evidence: string, distance: int, levenshtein_confidence: int, word_similarity: float, is_exact: bool}|null
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
        $reason = __('Similar normalized :field', ['field' => $field]);
        $isExact = $compactChannel === $compactCandidate;

        if ($isExact) {
            $confidence = 100;
            $reason = __('Exact normalized :field', ['field' => $field]);
        } elseif ($wordSimilarity >= $this->embedSimThreshold) {
            $confidence = max($confidence, (int) round($wordSimilarity * 100));
            $reason = __('Same normalized words via :field', ['field' => $field]);
        } elseif (min(strlen($compactChannel), strlen($compactCandidate)) >= 4
            && (str_contains($compactChannel, $compactCandidate) || str_contains($compactCandidate, $compactChannel))) {
            $confidence = max($confidence, 80);
            $reason = __('Strong normalized containment via :field', ['field' => $field]);
        }

        return [
            'matched_value' => $candidateValue,
            'normalized_value' => $normalizedCandidate,
            'confidence' => $confidence,
            'reason' => $reason,
            'evidence' => $field,
            'distance' => $distance,
            'levenshtein_confidence' => $levenshteinConfidence,
            'word_similarity' => $wordSimilarity,
            'is_exact' => $isExact,
        ];
    }

    /** @param  array{model: EpgChannel, epg_channel_id: int}  $candidate */
    private function candidateIdentity(array $candidate): string
    {
        return mb_strtolower(trim((string) $candidate['model']->channel_id), 'UTF-8') ?: 'row:'.$candidate['epg_channel_id'];
    }

    private function evidencePriority(string $evidence): int
    {
        return match ($evidence) {
            'identifier' => 2,
            'callsign' => 1,
            default => 0,
        };
    }

    private function valueMatchesCallsign(mixed $value, string $callsign): bool
    {
        if ($callsign === '' || ! is_string($value)) {
            return false;
        }

        $normalizedValue = mb_strtolower(trim($value), 'UTF-8');

        return $normalizedValue === $callsign || str_starts_with($normalizedValue, $callsign.'-');
    }

    public function extractCallsign(string $name): ?string
    {
        if (preg_match('/\(([KWCkwc][A-Z]{2,3}(?:-(?:DT|LD|CD|HD|TV)\d?)?)\)/i', $name, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Normalize a channel name for similarity comparison.
     */
    private function normalizeChannelName(?string $name): string
    {
        if (! $name) {
            return '';
        }

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

        return trim(implode(' ', $tokens));
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
     * @param  list<string>  $searchTerms
     * @return array{string, list<string>}
     */
    private function candidateRelevanceOrder(array $searchTerms, string $identifier = '', string $callsign = ''): array
    {
        $expressions = [];
        $bindings = [];

        if ($identifier !== '') {
            $expressions[] = "CASE WHEN LOWER(COALESCE(channel_id, '')) = ? THEN 100 ELSE 0 END";
            $bindings[] = $identifier;
        }

        if ($callsign !== '') {
            $callsignPrefix = $callsign.'-%';
            $expressions[] = "CASE WHEN (LOWER(COALESCE(channel_id, '')) = ? OR LOWER(COALESCE(channel_id, '')) LIKE ? OR LOWER(COALESCE(name, '')) = ? OR LOWER(COALESCE(name, '')) LIKE ? OR LOWER(COALESCE(display_name, '')) = ? OR LOWER(COALESCE(display_name, '')) LIKE ?) THEN 50 ELSE 0 END";
            array_push($bindings, $callsign, $callsignPrefix, $callsign, $callsignPrefix, $callsign, $callsignPrefix);
        }

        foreach ($searchTerms as $term) {
            $likeTerm = $this->likePattern($term);
            [$jsonCondition, $jsonBindings] = $this->jsonSearchCondition($term);
            $expressions[] = "CASE WHEN (LOWER(COALESCE(channel_id, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(display_name, '')) LIKE ? ESCAPE '!' OR {$jsonCondition}) THEN 1 ELSE 0 END";
            array_push($bindings, $likeTerm, $likeTerm, $likeTerm, ...$jsonBindings);
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
            'pgsql' => [
                "EXISTS (SELECT 1 FROM jsonb_array_elements_text(additional_display_names) AS elem WHERE LOWER(elem) LIKE ? ESCAPE '!')",
                [$likeTerm],
            ],
            'mysql', 'mariadb' => [
                "JSON_SEARCH(LOWER(JSON_UNQUOTE(additional_display_names)), 'one', ?, '!') IS NOT NULL",
                [$likeTerm],
            ],
            'sqlite' => [
                "EXISTS (SELECT 1 FROM json_each(additional_display_names) WHERE LOWER(json_each.value) LIKE ? ESCAPE '!')",
                [$likeTerm],
            ],
            default => [
                "LOWER(CAST(additional_display_names AS TEXT)) LIKE ? ESCAPE '!'",
                [$likeTerm],
            ],
        };
    }
}
