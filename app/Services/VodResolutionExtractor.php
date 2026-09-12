<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;

/**
 * Extracts a VOD channel's vertical resolution (2160/1440/1080/720/576/480)
 * for resolution-aware movie merge scoring.
 *
 * Priority: probed stream_stats → title → name → url. This never triggers a
 * live ffprobe — it only reads already-stored stream_stats and the channel's
 * title/name/url strings, so it is safe to call during the merge pass.
 */
class VodResolutionExtractor
{
    /**
     * @return array{resolution: int, source: 'probed'|'title'|'name'|'url'}|null
     */
    public static function extract(Channel $channel): ?array
    {
        $probed = self::probedResolution($channel);
        if ($probed !== null) {
            return ['resolution' => $probed, 'source' => 'probed'];
        }

        $parser = app(AioStreamsQualityParser::class);

        foreach (['title', 'name', 'url'] as $field) {
            $value = $channel->{$field};
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $resolution = $parser->parseResolution($value);
            if ($resolution !== null) {
                return ['resolution' => $resolution, 'source' => $field];
            }
        }

        return null;
    }

    /**
     * Read an already-probed resolution from stream_stats without triggering a
     * new probe. Accepts both the flat emby-style shape (a `resolution` key
     * holding "WIDTHxHEIGHT" or a bare vertical value) and the nested ffprobe
     * shape (a list of `stream` entries with width/height).
     */
    private static function probedResolution(Channel $channel): ?int
    {
        $stats = $channel->stream_stats;
        if (! is_array($stats) || $stats === []) {
            return null;
        }

        if (array_key_exists('resolution', $stats)) {
            $resolution = $stats['resolution'];
            if (is_int($resolution) && $resolution > 0) {
                return $resolution;
            }
            if (is_numeric($resolution) && (int) $resolution > 0) {
                return (int) $resolution;
            }
            if (is_string($resolution) && ($height = self::heightFromResolution($resolution)) !== null) {
                return $height;
            }
        }

        foreach ($stats as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $stream = $entry['stream'] ?? $entry;
            if (($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }

            $height = (int) ($stream['height'] ?? 0);
            if ($height > 0) {
                return $height;
            }
        }

        return null;
    }

    private static function heightFromResolution(string $resolution): ?int
    {
        if (preg_match('/(?<width>\d{3,5})\s*[x×]\s*(?<height>\d{3,5})/i', $resolution, $matches) === 1) {
            return (int) $matches['height'];
        }

        if (preg_match('/\b(?<height>2160|1440|1080|720|576|480)\b/i', $resolution, $matches) === 1) {
            return (int) $matches['height'];
        }

        return null;
    }
}
