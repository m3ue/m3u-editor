# EPG Channel Mapping Improvements

## Overview

The EPG channel mapping process has been significantly improved to provide more accurate matches and reduce false positives, especially in scenarios with many similar channel names.

## Problem Statement

Previous implementation had several issues:
- Channels were being incorrectly matched to unrelated EPG entries
- Example: "F1-TV: F1 TV PRO LIVE 4K" was matched to "SPORTTOTALL EVENT 68"
- Similarity search was too aggressive and would match partially similar names
- No exact match verification before falling back to fuzzy matching
- Poor handling of channels with very similar names

## Improvements Made

### 1. Enhanced Exact Match Logic (MapPlaylistChannelsToEpg.php)

**Three-tier matching approach:**

1. **Tier 1: Exact channel_id match**
   - Tries to match against `channel_id` field in EPG
   - Case-insensitive comparison
   - Checks stream_id, name, and title from playlist channel

2. **Tier 2: Exact name/display_name match**
   - If no channel_id match, tries exact match on `name` and `display_name`
   - Prevents incorrect fuzzy matches when exact alternatives exist

3. **Tier 3: Similarity search (fallback)**
   - Only used when no exact matches found
   - Requires minimum 3 characters in channel name
   - Now uses improved similarity algorithm (see below)

### 2. Stricter Similarity Search (SimilaritySearchService.php)

**Parameter adjustments for better accuracy:**
- `bestFuzzyThreshold`: 40 → 15 (stricter exact matches)
- `upperFuzzyThreshold`: 70 → 50 (better filtering)
- `embedSimThreshold`: 0.65 → 0.75 (stricter similarity requirement)
- Added `minChannelLength`: 3 (minimum length for matching)
- **Stop words reduced**: Removed "hd", "uhd", "fhd", "1080p", etc. from stop words to preserve quality indicators
- **Minimum similarity**: 60% → 50% for better recall with similar channels

**New exact normalized match step:**
- Before fuzzy matching, tries to find exact matches with spaces, dashes, and underscores removed
- Handles cases like "F1-TV" vs "F1 TV" vs "F1TV"
- **Performance optimized**: Uses indexed LIKE queries on database, then verifies exact match in PHP
- Avoids nested REPLACE() operations on database columns which prevent index usage

**Improved query construction:**
- Properly handles empty search terms to avoid malformed WHERE clauses
- Uses dynamic query building with first/subsequent condition logic
- Filters search terms array to only include non-empty values

**Improved candidate filtering:**
- Considers EPG channels with at least 50% similarity (adjusted for better recall)
- Uses both normalized and original names for matching (dual-strategy)
- Compares with both normalized and less-normalized versions for accuracy
- Tracks similarity percentage in addition to Levenshtein distance
- Requires 60% minimum similarity for accepting best match (balanced precision/recall)
- Preserves quality indicators (HD, FHD, UHD, resolutions) that differentiate channels

**Better decision making:**
- Stores all candidates with metadata (score, similarity %, region bonus)
- Sorts candidates by quality
- Verifies best match is actually good before accepting
- Additional validation in cosine similarity stage

### 3. Enhanced Debugging

**Added detailed logging capabilities:**
- Logs exact match success
- Logs why matches were rejected
- Shows best candidate when no match found
- Includes similarity percentage in logs

## Usage

### Enabling Debug Mode

To see detailed matching information, enable debug mode in `SimilaritySearchService.php`:

```php
public function findMatchingEpgChannel($channel, $epg = null): ?EpgChannel
{
    $debug = true; // Enable for detailed logging
    // ...
}
```

### Expected Behavior

**For exact matches (e.g., "F1-TV: F1 TV PRO LIVE 4K"):**
- Should match immediately if EPG contains exact channel_id
- If not in channel_id, should match via name/display_name
- Falls back to normalized exact match (ignoring spaces/dashes)

**For similar names (e.g., "RTL HD" vs "AT: RTL HD+"):**
- Will match if core channel name is similar enough (>50% after normalization)
- Quality indicators (HD, FHD, etc.) are now preserved for better differentiation
- Prefix/suffix differences are handled more intelligently

**For fuzzy matches (e.g., "ProSieben MAXX HDraw" vs "ProSieben MAXX HD"):**
- Will match if similarity is high enough (>60% for best match)
- Uses both normalized and original names for best accuracy
- Region codes provide bonus for local channels
- Cosine similarity provides additional verification

## Testing Recommendations

1. **Test with exact matches**: Verify channels with exact EPG names are matched correctly
2. **Test with similar names**: Ensure channels with similar but different names don't cross-match
3. **Test with variations**: Check spacing/dash/underscore variations work correctly
4. **Test with prefixes**: Verify prefix removal still allows proper matching

## Configuration

Adjust thresholds in `SimilaritySearchService.php` if needed:

```php
private $bestFuzzyThreshold = 15;      // Lower = stricter
private $upperFuzzyThreshold = 50;     // Range for cosine similarity
private $embedSimThreshold = 0.75;     // Higher = stricter
private $minChannelLength = 3;         // Minimum name length
```

## IPTV Considerations

These improvements are safe for IPTV usage:
- No additional stream access required
- All matching done on metadata only
- No rate limiting concerns
- Processing time may be slightly longer due to additional validation steps

## Troubleshooting

If channels are still not matching correctly:

1. **Check EPG data quality**: Verify EPG contains proper channel_id, name, or display_name
2. **Review prefix settings**: Ensure prefix removal is configured correctly
3. **Enable debug logging**: Check logs for matching decisions
4. **Adjust thresholds**: May need tuning for specific EPG sources
5. **Verify normalization**: Check if channel names are being normalized correctly

## Performance Impact

- **Optimized for large EPG datasets**: Uses indexed database queries instead of computed operations
- **Exact normalized matching**: Fetches candidates with LIKE (uses index), verifies in PHP (faster)
- **Smart query construction**: Avoids malformed queries with empty search terms
- **Minimal impact** on overall processing time
- Additional exact match step is very fast (indexed database queries)
- Candidate filtering reduces fuzzy search time
- Overall: **faster AND more accurate** than previous implementation
