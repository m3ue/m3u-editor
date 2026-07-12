<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service to handle similarity search between channels and EPG channels.
 */
class SimilaritySearchService
{
    private const MAX_DATABASE_CANDIDATES = 250;

    private const MAX_REVIEW_CANDIDATES = 3;

    private const MIN_REVIEW_CONFIDENCE = 40;

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
    private array $qualityIndicators = [
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

    private bool $removeQualityIndicators = false;

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
        )['automatic_match'];
    }

    /**
     * Return the automatic match decision and explainable review candidates from the same scoring pass.
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
    ): array {
        $this->removeQualityIndicators = $removeQualityIndicators;
        $this->upperFuzzyThreshold = $fuzzyMaxDistance;
        $this->bestFuzzyThreshold = $exactMatchDistance;

        if ($customQualityIndicators !== null) {
            $this->qualityIndicators = array_map('mb_strtolower', $customQualityIndicators);
        }

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

        if (! $epg || ! $normalizedChan || mb_strlen($normalizedChan, 'UTF-8') < $this->minChannelLength) {
            return $emptyResult;
        }

        $searchTerms = collect(explode(' ', $normalizedChan))
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= $this->minChannelLength)
            ->sortByDesc(fn (string $term): int => mb_strlen($term, 'UTF-8'))
            ->take(4)
            ->values();

        if ($searchTerms->isEmpty()) {
            return $emptyResult;
        }

        $databaseCandidates = $epg->channels()
            ->where(function (Builder $query) use ($searchTerms): void {
                foreach ($searchTerms as $term) {
                    $likeTerm = "%{$term}%";
                    $query->orWhereRaw('LOWER(channel_id) LIKE ?', [$likeTerm])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$likeTerm])
                        ->orWhereRaw('LOWER(display_name) LIKE ?', [$likeTerm]);
                    $this->addJsonSearchCondition($query, $term);
                }
            })
            ->select('id', 'channel_id', 'name', 'display_name', 'additional_display_names')
            ->limit(self::MAX_DATABASE_CANDIDATES)
            ->get();

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

            if ($regionCode && str_contains(mb_strtolower(($epgChannel->channel_id ?? '').' '.($epgChannel->name ?? ''), 'UTF-8'), $regionCode)) {
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

            if ($topCandidate['is_exact'] || $meetsDistanceRule || $meetsWordRule) {
                $automaticMatch = $topCandidate['model'];
            }
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
            'candidates' => $reviewCandidates,
            'explanation' => $reviewCandidates === []
                ? __('No candidate had enough normalized name or identifier overlap.')
                : __('Candidates are ranked from the selected EPG source; confirm borderline matches explicitly.'),
        ];
    }

    /**
     * @return array{matched_value: string, normalized_value: string, confidence: int, reason: string, distance: int, levenshtein_confidence: int, word_similarity: float, is_exact: bool}|null
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
        $driver = DB::connection()->getConfig('driver');

        switch ($driver) {
            case 'pgsql':
                // PostgreSQL: Use jsonb_array_elements_text to search through array elements
                $query->orWhereRaw(
                    'EXISTS (SELECT 1 FROM jsonb_array_elements_text(additional_display_names) AS elem WHERE LOWER(elem) LIKE ?)',
                    ["%$normalizedChan%"]
                );
                break;

            case 'mysql':
            case 'mariadb':
                // MySQL/MariaDB: Use JSON_UNQUOTE and JSON_SEARCH for array search
                $query->orWhereRaw(
                    'JSON_SEARCH(LOWER(JSON_UNQUOTE(additional_display_names)), "one", ?) IS NOT NULL',
                    ["%$normalizedChan%"]
                );
                break;

            case 'sqlite':
                // SQLite: Use json_each to iterate through array elements
                $query->orWhereRaw(
                    'EXISTS (SELECT 1 FROM json_each(additional_display_names) WHERE LOWER(json_each.value) LIKE ?)',
                    ["%$normalizedChan%"]
                );
                break;

            default:
                // Fallback: Use Laravel's JSON where clause (less efficient but universal)
                // This converts the array to string and searches within it
                $query->orWhere(function ($subQuery) use ($normalizedChan) {
                    $subQuery->whereNotNull('additional_display_names')
                        ->whereRaw('LOWER(CAST(additional_display_names AS TEXT)) LIKE ?', ["%$normalizedChan%"]);
                });
                break;
        }
    }
}
