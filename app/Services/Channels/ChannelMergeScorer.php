<?php

namespace App\Services\Channels;

use App\Models\Channel;

/**
 * Stateless scoring service shared by MergeChannels (sync-time scoring) and
 * RescoreChannelFailovers (periodic rescoring of failover groups).
 *
 * Construct with the normalized priority order plus any context the scorers
 * need (playlist priority lookup, group weights, codec preference, keywords),
 * then call score() for each channel.
 */
class ChannelMergeScorer
{
    /**
     * Default priority attributes order (first = highest priority).
     *
     * Also acts as the allow-list when normalizing user-supplied priority
     * configurations.
     */
    public const DEFAULT_PRIORITY_ORDER = [
        'playlist_priority',
        'group_priority',
        'catchup_support',
        'resolution',
        'fps',
        'bitrate',
        'codec',
        'keyword_match',
    ];

    /**
     * @param  array<int, string>  $priorityOrder  Normalized priority order, in priority-descending order
     * @param  array<int, int>  $playlistPriority  Map of playlist_id => priority index (lower index = higher priority)
     * @param  array<int, int>  $groupPriorityCache  Map of group_id => weight
     * @param  array<int, string>  $priorityKeywords  Substrings to match against channel title
     */
    public function __construct(
        protected array $priorityOrder,
        protected array $playlistPriority = [],
        protected array $groupPriorityCache = [],
        protected ?string $preferredCodec = null,
        protected array $priorityKeywords = [],
    ) {}

    /**
     * Normalize a user-supplied priority attributes config.
     *
     * Accepts either a flat string array (`['resolution', 'fps']`) or the
     * Filament repeater shape (`[['attribute' => 'resolution'], ...]`).
     * Filters out anything not in DEFAULT_PRIORITY_ORDER. Returns the default
     * order when the input is empty or fully invalid.
     *
     * @param  array<mixed>|null  $raw
     * @return array<int, string>
     */
    public static function normalizePriorityOrder(?array $raw): array
    {
        if (! is_array($raw) || empty($raw)) {
            return self::DEFAULT_PRIORITY_ORDER;
        }

        $allowed = array_flip(self::DEFAULT_PRIORITY_ORDER);
        $normalized = [];

        foreach ($raw as $item) {
            $attribute = is_array($item) ? ($item['attribute'] ?? null) : $item;
            if (! is_string($attribute)) {
                continue;
            }

            $attribute = trim($attribute);
            if ($attribute === '' || ! isset($allowed[$attribute])) {
                continue;
            }

            $normalized[] = $attribute;
        }

        $normalized = array_values(array_unique($normalized));

        return ! empty($normalized) ? $normalized : self::DEFAULT_PRIORITY_ORDER;
    }

    /**
     * Calculate the weighted score for a channel under the configured priority order.
     *
     * Each attribute scores 0-100 (see scoreBreakdown). Weights are positional:
     * with N priorities the first attribute is weighted ×N, the second ×(N-1),
     * down to ×1. The total is normalized so the final score is always 0-100,
     * regardless of how many priority attributes are configured.
     *
     *   score = round( 100 × Σ(rawᵢ × weightᵢ) / (100 × Σ weightᵢ) )
     *         = round( Σ(rawᵢ × weightᵢ) / Σ weightᵢ )
     *
     * Relative ordering is identical to the unnormalized weighted sum, so this
     * is a pure UX simplification — no behavioral change for ranking.
     */
    public function score(Channel $channel): int
    {
        $count = count($this->priorityOrder);
        if ($count === 0) {
            return 0;
        }

        $sumOfWeights = $count * ($count + 1) / 2;
        $weighted = 0;
        $weight = $count;

        foreach ($this->priorityOrder as $attribute) {
            $weighted += $this->attributeScore($attribute, $channel) * $weight;
            $weight--;
        }

        return (int) round($weighted / $sumOfWeights);
    }

    /**
     * Return per-attribute scores (0-100 each) for a channel under the configured priority order.
     *
     * Useful for explaining why a channel ranked the way it did. The priority
     * order is preserved so the highest-impact attributes appear first.
     *
     * @return array<string, int>
     */
    public function scoreBreakdown(Channel $channel): array
    {
        $breakdown = [];
        foreach ($this->priorityOrder as $attribute) {
            $breakdown[$attribute] = $this->attributeScore($attribute, $channel);
        }

        return $breakdown;
    }

    /**
     * Score a single attribute (0-100). Returns 0 for unknown attributes.
     */
    protected function attributeScore(string $attribute, Channel $channel): int
    {
        return match ($attribute) {
            'playlist_priority' => $this->getPlaylistPriorityScore($channel),
            'group_priority' => $this->getGroupPriorityScore($channel),
            'catchup_support' => $this->getCatchupScore($channel),
            'resolution' => $this->getResolutionScore($channel),
            'fps' => $this->getFpsScore($channel),
            'bitrate' => $this->getBitrateScore($channel),
            'codec' => $this->getCodecScore($channel),
            'keyword_match' => $this->getKeywordScore($channel),
            default => 0,
        };
    }

    protected function getPlaylistPriorityScore(Channel $channel): int
    {
        $priority = $this->playlistPriority[$channel->playlist_id] ?? 999;

        return max(0, 100 - $priority);
    }

    protected function getGroupPriorityScore(Channel $channel): int
    {
        return $this->groupPriorityCache[$channel->group_id] ?? 0;
    }

    protected function getCatchupScore(Channel $channel): int
    {
        return ! empty($channel->catchup) ? 100 : 0;
    }

    protected function getResolutionScore(Channel $channel): int
    {
        $resolution = self::getResolution($channel);

        // Normalize: 4K (3840x2160 = 8294400) = 100, 1080p = ~25, 720p = ~11
        return min(100, (int) ($resolution / 82944));
    }

    protected function getFpsScore(Channel $channel): int
    {
        $fps = self::getFps($channel);

        // Normalize: 25/30 fps = ~25-30, 50/60 fps = ~50-60, 100+ fps caps at 100
        return min(100, (int) round($fps));
    }

    protected function getBitrateScore(Channel $channel): int
    {
        $kbps = self::getBitrate($channel);

        // Normalize: 5000 kbps = 50, 10000+ kbps caps at 100
        return min(100, (int) ($kbps / 100));
    }

    protected function getCodecScore(Channel $channel): int
    {
        if (! $this->preferredCodec) {
            return 0;
        }

        $channelCodec = self::getCodec($channel);
        if (! $channelCodec) {
            return 0;
        }

        $preferred = strtolower($this->preferredCodec);
        $codec = strtolower($channelCodec);

        $isHevc = str_contains($codec, 'hevc') || str_contains($codec, 'h265') || str_contains($codec, '265');
        $isH264 = str_contains($codec, 'h264') || str_contains($codec, 'avc') || str_contains($codec, '264');

        if ($preferred === 'hevc' || $preferred === 'h265') {
            return $isHevc ? 100 : 0;
        }

        if ($preferred === 'h264' || $preferred === 'avc') {
            return $isH264 ? 100 : 0;
        }

        return 0;
    }

    protected function getKeywordScore(Channel $channel): int
    {
        if (empty($this->priorityKeywords)) {
            return 0;
        }

        $channelName = strtolower($channel->title ?? $channel->name ?? '');
        $matchCount = 0;

        foreach ($this->priorityKeywords as $keyword) {
            if (str_contains($channelName, strtolower($keyword))) {
                $matchCount++;
            }
        }

        return min(100, $matchCount * 25);
    }

    /**
     * Total pixel count of the first video stream (width * height).
     */
    public static function getResolution(Channel $channel): int
    {
        $streamStats = $channel->ensureStreamStats();
        foreach ($streamStats as $entry) {
            $stream = $entry['stream'] ?? null;
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                return (int) ($stream['width'] ?? 0) * (int) ($stream['height'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Frame rate (fps) of the first video stream. Routes via getEmbyStreamStats
     * so the fractional-rate parsing (e.g. "30000/1001" → 29.97) is reused.
     */
    public static function getFps(Channel $channel): float
    {
        $channel->ensureStreamStats();
        $emby = $channel->getEmbyStreamStats();

        return (float) ($emby['source_fps'] ?? 0.0);
    }

    /**
     * Video bitrate (kbps). Routes via getEmbyStreamStats so format-level and
     * packet-sampling fallbacks for live MPEG-TS streams apply.
     */
    public static function getBitrate(Channel $channel): int
    {
        $channel->ensureStreamStats();
        $emby = $channel->getEmbyStreamStats();

        return (int) ($emby['ffmpeg_output_bitrate'] ?? 0);
    }

    /**
     * Codec name of the first video stream, or null if no video stream is present.
     */
    public static function getCodec(Channel $channel): ?string
    {
        $streamStats = $channel->ensureStreamStats();
        foreach ($streamStats as $entry) {
            $stream = $entry['stream'] ?? null;
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                return $stream['codec_name'] ?? null;
            }
        }

        return null;
    }
}
