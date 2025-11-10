<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SimilaritySearchService
{
    // Configurable parameters
    private $bestFuzzyThreshold = 15;      // Reduced from 40 for stricter exact matches
    private $upperFuzzyThreshold = 50;     // Reduced from 70 for better filtering
    private $embedSimThreshold = 0.75;     // Increased from 0.65 for stricter similarity
    private $minChannelLength = 3;         // Minimum length to consider for matching

    // Words to ignore
    private $stopWords = [
        "tv",
        "channel",
        "network",
        "television",
        "east",
        "west",
        "hd",
        "uhd",
        "fhd",
        "us",
        "usa",
        "not",
        "24/7",
        "1080p",
        "720p",
        "540p",
        "480p",
        "arabic",
        "latino",
        "film",
        "movie",
        "movies"
    ];

    /**
     * Find the best matching EPG channel for a given channel.
     * 
     * @param Channel $channel
     * @param Epg $epg
     * 
     * @return EpgChannel|null
     */
    public function findMatchingEpgChannel($channel, $epg = null): ?EpgChannel
    {
        $debug = false; // config('app.debug');
        $regionCode = $epg->preferred_local ? strtolower($epg->preferred_local) : null;
        $title = $channel->title_custom ?? $channel->title;
        $name = $channel->name_custom ?? $channel->name;
        $fallbackName = trim($title ?: $name);
        $normalizedChan = $this->normalizeChannelName($fallbackName);

        if (!$normalizedChan || strlen($normalizedChan) < $this->minChannelLength) {
            if ($debug) {
                Log::debug("Channel {$channel->id} '{$fallbackName}' => empty or too short after normalization, skipping");
            }
            return null;
        }

        // Step 1: Try to find exact normalized matches first (highest priority)
        // Normalize the search term once (remove spaces, dashes, underscores)
        $normalizedSearch = strtolower(str_replace([' ', '-', '_'], '', $normalizedChan));
        
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
            $normalizedChannelId = strtolower(str_replace([' ', '-', '_'], '', $candidate->channel_id ?? ''));
            $normalizedName = strtolower(str_replace([' ', '-', '_'], '', $candidate->name ?? ''));
            $normalizedDisplayName = strtolower(str_replace([' ', '-', '_'], '', $candidate->display_name ?? ''));
            
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
            ->where(function ($query) use ($normalizedChan) {
                // Use LIKE with at least 3 characters for better filtering
                $searchTerm = strlen($normalizedChan) >= 5 ? substr($normalizedChan, 0, 5) : $normalizedChan;
                $query->whereRaw('LOWER(channel_id) like ?', ["%$searchTerm%"])
                    ->orWhereRaw('LOWER(name) like ?', ["%$searchTerm%"])
                    ->orWhereRaw('LOWER(display_name) like ?', ["%$searchTerm%"]);
                
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
         * Levenshtein Distance for Fuzzy Matching
         */
        foreach ($epgChannels->cursor() as $epgChannel) {
            $normalizedEpg = empty($epgChannel->name)
                ? $this->normalizeChannelName($epgChannel->channel_id)
                : $this->normalizeChannelName($epgChannel->name);
            if (!$normalizedEpg) continue;

            // Calculate fuzzy similarity
            $score = levenshtein($normalizedChan, $normalizedEpg);
            
            // Calculate similarity percentage for better filtering
            $maxLength = max(strlen($normalizedChan), strlen($normalizedEpg));
            $similarityPercentage = $maxLength > 0 ? (1 - ($score / $maxLength)) * 100 : 0;

            // Apply region-based bonus (convert to penalty for Levenshtein)
            $regionBonus = 0;
            if ($regionCode && stripos(strtolower($epgChannel->channel_id . ' ' . $epgChannel->name), $regionCode) !== false) {
                $score = max(0, $score - 15); // Subtract to improve the match
                $regionBonus = 15;
            }

            // Store candidate with metadata for better decision making
            $candidates[] = [
                'channel' => $epgChannel,
                'score' => $score,
                'similarity' => $similarityPercentage,
                'region_bonus' => $regionBonus,
                'normalized_name' => $normalizedEpg
            ];

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMatch = $epgChannel;
            }

            // Store candidate for embedding similarity if in borderline range
            if ($score >= $this->bestFuzzyThreshold && $score < $this->upperFuzzyThreshold) {
                $bestEpgForEmbedding = $epgChannel;
            }
        }

        // Filter out poor matches - require at least 60% similarity
        $candidates = array_filter($candidates, function($candidate) {
            return $candidate['similarity'] >= 60;
        });

        // Sort candidates by score (lower is better)
        usort($candidates, function($a, $b) {
            return $a['score'] <=> $b['score'];
        });

        // If we have a best match with Levenshtein < bestFuzzyThreshold and good similarity, return it
        if ($bestMatch && $bestScore < $this->bestFuzzyThreshold) {
            // Double check that this is actually a good match
            if (!empty($candidates) && $candidates[0]['similarity'] >= 70) {
                if ($debug) {
                    Log::debug("Channel {$channel->id} '{$fallbackName}' matched with EPG channel_id={$bestMatch->channel_id} (score={$bestScore}, similarity={$candidates[0]['similarity']}%)");
                }
                return $bestMatch;
            }
        }

        // ** Cosine Similarity for Borderline Cases **
        if ($bestEpgForEmbedding && !empty($candidates)) {
            $chanVector = $this->textToVector($normalizedChan);
            $epgVector = $this->textToVector($this->normalizeChannelName($bestEpgForEmbedding->name));
            if (!empty($chanVector) && !empty($epgVector)) {
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
        if ($debug && !empty($candidates)) {
            $topCandidate = $candidates[0];
            Log::debug("Channel {$channel->id} '{$fallbackName}' => No match found. Best candidate: '{$topCandidate['channel']->channel_id}' (score={$topCandidate['score']}, similarity={$topCandidate['similarity']}%)");
        }

        return null;
    }

    /**
     * Normalize a channel name for similarity comparison.
     * 
     * @param string $name
     * 
     * @return string
     */
    private function normalizeChannelName($name): string
    {
        if (!$name) return '';
        $name = strtolower($name);
        // Remove brackets and parentheses
        $name = preg_replace('/\[.*?\]|\(.*?\)/', '', $name);
        // Remove special characters
        $name = preg_replace('/[^\w\s]/', '', $name);
        // Remove stop words
        $tokens = explode(' ', $name);
        $tokens = array_diff($tokens, $this->stopWords);
        return trim(implode(' ', $tokens));
    }

    /**
     * Convert a text into a word frequency vector.
     * 
     * @param string $text
     * 
     * @return array
     */
    private function textToVector($text): array
    {
        $words = explode(' ', $text);
        $vector = array_count_values($words); // Simple word frequency vector
        return $vector;
    }

    /**
     * Calculate the cosine similarity between two vectors.
     * 
     * @param array $vecA
     * @param array $vecB
     * 
     * @return float
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

        if ($magA == 0 || $magB == 0) return 0;

        return $dotProduct / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Add database-specific search condition for additional_display_names JSONB column.
     *
     * @param Builder $query
     * @param string $normalizedChan
     *
     * @return void
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
