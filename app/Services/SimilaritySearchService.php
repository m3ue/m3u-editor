<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimilaritySearchService
{
    // Constant for original search prefix length
    private const ORIGINAL_SEARCH_PREFIX_LENGTH = 10;

    // Configurable parameters
    private $bestFuzzyThreshold = 8;       // Stricter threshold for exact matches (reduced from 15)

    private $upperFuzzyThreshold = 25;     // Much stricter - only allow very similar names (reduced from 50)

    private $embedSimThreshold = 0.80;     // Higher similarity required (increased from 0.75)

    private $minChannelLength = 3;         // Minimum length to consider for matching

    // Words to ignore (reduced list - keep quality indicators that differentiate channels)
    private $stopWords = [
        'tv',
        'channel',
        'network',
        'television',
        'east',
        'west',
        'us',
        'usa',
        'not',
        '24/7',
        'arabic',
        'latino',
        'film',
        'movie',
        'movies',
    ];

    // Quality indicators to optionally remove (when setting is enabled)
    private $qualityIndicators = [
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

    // Whether to remove quality indicators during normalization
    private $removeQualityIndicators = false;

    /**
     * Sanitizes UTF-8 encoding in strings to prevent PostgreSQL errors.
     *
     * @param  string|null  $value
     * @return string|null
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
     * @param  Channel  $channel
     * @param  Epg  $epg
     * @param  bool  $removeQualityIndicators  Whether to remove quality indicators during matching
     * @param  int  $similarityThreshold  Minimum similarity percentage (0-100)
     * @param  int  $fuzzyMaxDistance  Maximum Levenshtein distance for fuzzy matching
     * @param  int  $exactMatchDistance  Maximum distance for exact matches
     */
    public function findMatchingEpgChannel(
        $channel, 
        $epg = null, 
        $removeQualityIndicators = false,
        $similarityThreshold = 70,
        $fuzzyMaxDistance = 25,
        $exactMatchDistance = 8
    ): ?EpgChannel
    {
        // Set the instance variables
        $this->removeQualityIndicators = $removeQualityIndicators;
        $this->upperFuzzyThreshold = $fuzzyMaxDistance;
        $this->bestFuzzyThreshold = $exactMatchDistance;

        $debug = false; // config('app.debug');
        $regionCode = $epg->preferred_local ? mb_strtolower($epg->preferred_local, 'UTF-8') : null;
        
        // Sanitize UTF-8 encoding immediately to prevent PostgreSQL errors
        $title = $this->sanitizeUtf8($channel->title_custom ?? $channel->title);
        $name = $this->sanitizeUtf8($channel->name_custom ?? $channel->name);
        $fallbackName = trim($title ?: $name);
        $normalizedChan = $this->normalizeChannelName($fallbackName);

        if (! $normalizedChan || strlen($normalizedChan) < $this->minChannelLength) {
            if ($debug) {
                Log::debug("Channel {$channel->id} '{$fallbackName}' => empty or too short after normalization, skipping");
            }

            return null;
        }

        // Step 1: Try to find exact normalized matches first (highest priority)
        // Normalize the search term once (remove spaces, dashes, underscores)
        $normalizedSearch = mb_strtolower(str_replace([' ', '-', '_'], '', $normalizedChan), 'UTF-8');

        // Find candidates that could match when normalized
        // Use LIKE with wildcards to find potential matches, then verify in PHP
        $exactMatchCandidates = $epg->channels()
            ->where(function ($query) use ($normalizedChan) {
                // Search for the normalized term in channel_id, name, or display_name
                // This allows database to use indexes
                $query->whereRaw('LOWER(channel_id) LIKE ?', ["%{$normalizedChan}%"])
                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$normalizedChan}%"])
                    ->orWhereRaw('LOWER(display_name) LIKE ?', ["%{$normalizedChan}%"]);
            })
            ->select('id', 'channel_id', 'name', 'display_name')
            ->get();

        // Verify exact match after normalization in PHP (faster than DB REPLACE operations)
        foreach ($exactMatchCandidates as $candidate) {
            $normalizedChannelId = mb_strtolower(str_replace([' ', '-', '_'], '', $candidate->channel_id ?? ''), 'UTF-8');
            $normalizedName = mb_strtolower(str_replace([' ', '-', '_'], '', $candidate->name ?? ''), 'UTF-8');
            $normalizedDisplayName = mb_strtolower(str_replace([' ', '-', '_'], '', $candidate->display_name ?? ''), 'UTF-8');

            if ($normalizedSearch === $normalizedChannelId ||
                $normalizedSearch === $normalizedName ||
                $normalizedSearch === $normalizedDisplayName) {
                if ($debug) {
                    Log::debug("Channel {$channel->id} '{$fallbackName}' => EXACT normalized match with EPG channel_id={$candidate->channel_id}");
                }

                return $candidate;
            }
        }

        // Step 2: Fetch EPG channels using fuzzy matching (more restrictive)
        // Only fetch candidates that have significant overlap with the search term
        $epgChannels = $epg->channels()
            ->where(function ($query) use ($normalizedChan, $fallbackName) {
                // Use LIKE with at least 3 characters for better filtering
                // Also try the original fallback name for cases where normalization is too aggressive
                $searchTerm = mb_strlen($normalizedChan, 'UTF-8') >= 5 ? mb_substr($normalizedChan, 0, 5, 'UTF-8') : $normalizedChan;
                $originalSearch = mb_strtolower(mb_substr($fallbackName, 0, min(self::ORIGINAL_SEARCH_PREFIX_LENGTH, mb_strlen($fallbackName, 'UTF-8')), 'UTF-8'), 'UTF-8');

                // Optimized query: search each column once with both search terms combined
                $query->where(function ($subQuery) use ($searchTerm, $originalSearch) {
                    $subQuery->whereRaw('LOWER(channel_id) LIKE ? OR LOWER(channel_id) LIKE ?', ["%$searchTerm%", "%$originalSearch%"])
                        ->orWhereRaw('LOWER(name) LIKE ? OR LOWER(name) LIKE ?', ["%$searchTerm%", "%$originalSearch%"])
                        ->orWhereRaw('LOWER(display_name) LIKE ? OR LOWER(display_name) LIKE ?', ["%$searchTerm%", "%$originalSearch%"]);
                });

                // Add search for additional_display_names JSONB column
                $this->addJsonSearchCondition($query, $searchTerm);
            });

        // Setup variables
        $bestScore = PHP_INT_MAX; // Levenshtein: lower is better
        $bestMatch = null;
        $bestEpgForEmbedding = null;
        $candidates = []; // Store all candidates with scores for better decision making

        if ($epgChannels->count() === 0) {
            if ($debug) {
                Log::debug("Channel {$channel->id} '{$fallbackName}' => no EPG channels found, skipping");
            }

            return null;
        }

        /**
         * Multi-Strategy Matching for Better Accuracy
         */
        foreach ($epgChannels->cursor() as $epgChannel) {
            // Try matching with both normalized and less-normalized versions
            $normalizedEpg = empty($epgChannel->name)
                ? $this->normalizeChannelName($epgChannel->channel_id)
                : $this->normalizeChannelName($epgChannel->name);
            if (! $normalizedEpg) {
                continue;
            }

            // Also try with less aggressive normalization (keep more info)
            $epgNameOriginal = mb_strtolower(trim($epgChannel->name ?? $epgChannel->channel_id), 'UTF-8');
            $channelNameOriginal = mb_strtolower(trim($fallbackName), 'UTF-8');

            // Calculate fuzzy similarity with normalized names
            $score = levenshtein($normalizedChan, $normalizedEpg);

            // Also calculate with original names (can be more accurate for similar channels)
            $scoreOriginal = levenshtein($channelNameOriginal, $epgNameOriginal);

            // Use the better score
            $finalScore = min($score, $scoreOriginal);

            // Calculate similarity percentage for better filtering
            $maxLength = max(mb_strlen($normalizedChan, 'UTF-8'), mb_strlen($normalizedEpg, 'UTF-8'));
            $similarityPercentage = $maxLength > 0 ? (1 - ($finalScore / $maxLength)) * 100 : 0;

            // Apply region-based bonus (convert to penalty for Levenshtein)
            $regionBonus = 0;
            if ($regionCode) {
                $haystack = mb_strtolower(($epgChannel->channel_id ?? '') . ' ' . ($epgChannel->name ?? ''), 'UTF-8');
                if (mb_stripos($haystack, $regionCode, 0, 'UTF-8') !== false) {
                    $finalScore = max(0, $finalScore - 15); // Subtract to improve the match
                    $regionBonus = 15;
                }
            }

            // Store candidate with metadata for better decision making
            $candidates[] = [
                'channel' => $epgChannel,
                'score' => $finalScore,
                'similarity' => $similarityPercentage,
                'region_bonus' => $regionBonus,
                'normalized_name' => $normalizedEpg,
                'original_score' => $scoreOriginal,  // Track original score for debugging
            ];

            if ($finalScore < $bestScore) {
                $bestScore = $finalScore;
                $bestMatch = $epgChannel;
            }

            // Store candidate for embedding similarity if in borderline range
            if ($finalScore >= $this->bestFuzzyThreshold && $finalScore < $this->upperFuzzyThreshold) {
                $bestEpgForEmbedding = $epgChannel;
            }
        }

        // Filter out poor matches - use configurable similarity threshold
        // This prevents false positives like "Spiegel TV HD" matching "Spiegel Geschichte SD"
        $candidates = array_filter($candidates, function ($candidate) use ($similarityThreshold) {
            return $candidate['similarity'] >= $similarityThreshold;
        });

        // Sort candidates by score (lower is better), then by similarity (higher is better)
        usort($candidates, function ($a, $b) {
            // First compare by score with epsilon for float comparison
            $scoreDiff = $a['score'] - $b['score'];
            if (abs($scoreDiff) > 0.001) {
                return $scoreDiff > 0 ? 1 : -1;
            }

            // If scores are equal (within epsilon), prefer higher similarity
            return (int) $b['similarity'] <=> (int) $a['similarity'];
        });

        // If we have a best match with Levenshtein < bestFuzzyThreshold and good similarity, return it
        if ($bestMatch && $bestScore < $this->bestFuzzyThreshold) {
            // Double check that this is actually a good match
            if (! empty($candidates) && $candidates[0]['similarity'] >= 60) {
                if ($debug) {
                    Log::debug("Channel {$channel->id} '{$fallbackName}' matched with EPG channel_id={$bestMatch->channel_id} (score={$bestScore}, similarity={$candidates[0]['similarity']}%)");
                }

                return $bestMatch;
            }
        }

        // ** Cosine Similarity for Borderline Cases **
        if ($bestEpgForEmbedding && ! empty($candidates)) {
            $chanVector = $this->textToVector($normalizedChan);
            $epgVector = $this->textToVector($this->normalizeChannelName($bestEpgForEmbedding->name));
            if (! empty($chanVector) && ! empty($epgVector)) {
                $similarity = $this->cosineSimilarity($chanVector, $epgVector);

                // Only accept if similarity is high enough
                if ($similarity >= $this->embedSimThreshold) {
                    // Additional check: ensure this is actually the best candidate
                    $candidateFound = false;
                    foreach ($candidates as $candidate) {
                        if ($candidate['channel']->id === $bestEpgForEmbedding->id && $candidate['similarity'] >= 65) {
                            $candidateFound = true;
                            break;
                        }
                    }

                    if ($candidateFound) {
                        if ($debug) {
                            Log::debug("Channel {$channel->id} '{$fallbackName}' matched via cosine similarity with channel_id={$bestEpgForEmbedding->channel_id} (cos-sim={$similarity})");
                        }

                        return $bestEpgForEmbedding;
                    }
                } else {
                    if ($debug) {
                        Log::debug("Channel {$channel->id} '{$fallbackName}' cosine-similarity with '{$bestEpgForEmbedding->name}' = {$similarity} (rejected, below threshold)");
                    }
                }
            }
        }

        // If we have candidates, log why we didn't match
        if ($debug && ! empty($candidates)) {
            $topCandidate = $candidates[0];
            Log::debug("Channel {$channel->id} '{$fallbackName}' => No match found. Best candidate: '{$topCandidate['channel']->channel_id}' (score={$topCandidate['score']}, similarity={$topCandidate['similarity']}%)");
        }

        return null;
    }

    /**
     * Normalize a channel name for similarity comparison.
     *
     * @param  string  $name
     */
    private function normalizeChannelName($name): string
    {
        if (! $name) {
            return '';
        }
        $name = mb_strtolower($name, 'UTF-8');
        
        // Remove brackets and parentheses CONTENT but keep the channel name intact
        $name = preg_replace('/\[.*?\]/', '', $name);
        $name = preg_replace('/\(.*?\)/', '', $name);
        
        // Only remove truly special characters, but keep: ², ³, +, numbers
        // This preserves HDraw², FHD+, etc.
        $name = preg_replace('/[^\w\s²³\+\-]/', '', $name);

        // Work with UTF-8 and lowercase properly
        $name = mb_strtolower($name, 'UTF-8');

        // Remove brackets and parentheses (Unicode-aware)
        $name = preg_replace('/\[.*?\]|\(.*?\)/u', '', $name);

        // Remove special characters but keep letters & numbers from all scripts
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);

        // Normalize whitespace
        $name = preg_replace('/\s+/u', ' ', $name);

        // Remove stop words (they are lowercased English tokens)
        $tokens = explode(' ', $name);
        $tokens = array_filter($tokens, fn($t) => $t !== '');
        $tokens = array_values(array_diff($tokens, $this->stopWords));

        // Optionally remove quality indicators
        if ($this->removeQualityIndicators) {
            $tokens = array_values(array_diff($tokens, $this->qualityIndicators));
        }

        return trim(implode(' ', $tokens));
    }

    /**
     * Convert a text into a word frequency vector.
     *
     * @param  string  $text
     */
    private function textToVector($text): array
    {
        $words = explode(' ', $text);
        $vector = array_count_values($words); // Simple word frequency vector

        return $vector;
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
     *
     * @param  Builder  $query
     */
    private function addJsonSearchCondition($query, string $normalizedChan): void
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
