<?php

namespace App\Services;

/**
 * Normalizes AIOStreams "stream" objects (from AIOStreamsService::fetchStreams())
 * into a consistent shape for ranking/filtering, and provides the default
 * ranking used to pick failover candidates.
 *
 * AIOStreams' structured per-stream fields aren't publicly documented and vary
 * by upstream addon, so this primarily regex/keyword-matches the human-readable
 * name/title/description strings (the one thing consistently present across
 * addons), falling back to structured fields (e.g. behaviorHints) when present.
 */
class AioStreamsQualityParser
{
    /**
     * Recognized VOD container extensions — deliberately excludes ts/m3u8,
     * which are live-stream formats. If a stream's container can't be
     * determined to be one of these, the caller should treat it as unknown
     * (Channel/Episode::getProxyUrl() already default sanely) rather than
     * guessing 'ts', which makes players treat VOD content as live and
     * disable seeking.
     */
    protected const VALID_CONTAINERS = ['mp4', 'mkv', 'avi', 'webm', 'mov', 'm4v', 'flv'];

    /**
     * @param  array<string, mixed>  $stream  A single entry from the `streams` array
     *                                        returned by AIOStreamsService::fetchStreams().
     * @return array{
     *     resolution: ?int,
     *     hdr: bool,
     *     dv: bool,
     *     codec: ?string,
     *     container: ?string,
     *     audio_channels: ?string,
     *     audio_format: ?string,
     *     size_bytes: ?int,
     *     seeders: ?int,
     *     cached: bool,
     *     source_addon: ?string,
     * }
     */
    public function parse(array $stream): array
    {
        $haystack = collect([
            $stream['name'] ?? null,
            $stream['title'] ?? null,
            $stream['description'] ?? null,
        ])->filter()->implode(' ');

        $behaviorHints = $stream['behaviorHints'] ?? [];

        return [
            'resolution' => $this->parseResolution($haystack),
            'hdr' => (bool) preg_match('/\bHDR(10\+?)?\b/i', $haystack),
            'dv' => (bool) preg_match('/\bDV\b|Dolby[\s._-]?Vision/i', $haystack),
            'codec' => $this->parseCodec($haystack),
            'container' => $this->parseContainer($stream, $behaviorHints, $haystack),
            'audio_channels' => $this->parseAudioChannels($haystack),
            'audio_format' => $this->parseAudioFormat($haystack),
            'size_bytes' => $behaviorHints['videoSize'] ?? $this->parseSizeBytes($haystack),
            'seeders' => $stream['seeders'] ?? $this->parseSeeders($haystack),
            'cached' => (bool) ($behaviorHints['cached'] ?? preg_match('/\b(cached|instant)\b/i', $haystack)),
            'source_addon' => $stream['name'] ?? null,
        ];
    }

    public function parseResolution(string $haystack): ?int
    {
        if (preg_match('/\b(2160p|4k|uhd)\b/i', $haystack)) {
            return 2160;
        }
        if (preg_match('/\b1080p\b/i', $haystack)) {
            return 1080;
        }
        if (preg_match('/\b720p\b/i', $haystack)) {
            return 720;
        }
        if (preg_match('/\b(480p|sd)\b/i', $haystack)) {
            return 480;
        }

        return null;
    }

    protected function parseCodec(string $haystack): ?string
    {
        if (preg_match('/\bAV1\b/i', $haystack)) {
            return 'av1';
        }
        if (preg_match('/\b(HEVC|x265|h\.?265)\b/i', $haystack)) {
            return 'hevc';
        }
        if (preg_match('/\b(x264|h\.?264|AVC)\b/i', $haystack)) {
            return 'avc';
        }

        return null;
    }

    /**
     * Best-effort VOD container extension, checked in order of reliability:
     * the addon-provided release filename (behaviorHints.filename — Stremio
     * addons commonly include the real torrent/file name here, extension and
     * all), the stream URL's own path (rarely useful for debrid links, which
     * are usually opaque signed URLs with no extension), then a keyword match
     * against the human-readable name/title/description text.
     *
     * @param  array<string, mixed>  $stream
     * @param  array<string, mixed>  $behaviorHints
     */
    protected function parseContainer(array $stream, array $behaviorHints, string $haystack): ?string
    {
        $fromFilename = $this->extensionFromPath($behaviorHints['filename'] ?? null);
        if ($fromFilename) {
            return $fromFilename;
        }

        $fromUrl = $this->extensionFromPath($stream['url'] ?? null);
        if ($fromUrl) {
            return $fromUrl;
        }

        if (preg_match('/\.?\b('.implode('|', self::VALID_CONTAINERS).')\b/i', $haystack, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    protected function extensionFromPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

        return in_array($extension, self::VALID_CONTAINERS, true) ? $extension : null;
    }

    protected function parseAudioChannels(string $haystack): ?string
    {
        if (preg_match('/\b7\.1\b/', $haystack)) {
            return '7.1';
        }
        if (preg_match('/\b5\.1\b/', $haystack)) {
            return '5.1';
        }
        if (preg_match('/\b2\.0\b|\bstereo\b/i', $haystack)) {
            return '2.0';
        }

        return null;
    }

    protected function parseAudioFormat(string $haystack): ?string
    {
        if (preg_match('/\bATMOS\b/i', $haystack)) {
            return 'atmos';
        }
        if (preg_match('/\bTrueHD\b/i', $haystack)) {
            return 'truehd';
        }
        if (preg_match('/\bDTS[\s._-]?(HD|X)?\b/i', $haystack)) {
            return 'dts';
        }
        if (preg_match('/\bAC3|DD5\.1|Dolby[\s._-]?Digital\b/i', $haystack)) {
            return 'dd';
        }
        if (preg_match('/\bAAC\b/i', $haystack)) {
            return 'aac';
        }

        return null;
    }

    protected function parseSizeBytes(string $haystack): ?int
    {
        if (! preg_match('/([\d.]+)\s?(GB|MB)\b/i', $haystack, $matches)) {
            return null;
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);

        return (int) ($unit === 'GB' ? $value * 1_073_741_824 : $value * 1_048_576);
    }

    protected function parseSeeders(string $haystack): ?int
    {
        if (! preg_match('/👤\s?(\d+)|seeders?[:\s]+(\d+)/i', $haystack, $matches)) {
            return null;
        }

        return (int) ($matches[1] ?: $matches[2]);
    }

    /**
     * Filter out streams that violate hard exclusion rules, then rank the
     * survivors: cached/debrid-ready first, then resolution desc, then
     * seeders/size as a tiebreak. Returns the parsed+ranked list, each entry
     * augmented with the original stream under 'stream'.
     *
     * @param  array<int, array<string, mixed>>  $streams  Raw streams from fetchStreams().
     * @param  array{avoid_hdr?: bool, avoid_dv?: bool, max_audio_channels?: int, avoid_lossless_audio?: bool, avoid_codecs?: array<int, string>}  $preference
     * @return array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>
     */
    public function rank(array $streams, array $preference = []): array
    {
        $avoidCodecs = collect($preference['avoid_codecs'] ?? [])->map(fn ($c) => strtolower($c))->all();

        $candidates = collect($streams)
            ->map(fn (array $stream) => ['parsed' => $this->parse($stream), 'stream' => $stream])
            ->filter(function (array $candidate) use ($preference, $avoidCodecs) {
                $parsed = $candidate['parsed'];

                if (($preference['avoid_hdr'] ?? false) && $parsed['hdr']) {
                    return false;
                }
                if (($preference['avoid_dv'] ?? false) && $parsed['dv']) {
                    return false;
                }
                if (($preference['avoid_lossless_audio'] ?? false) && in_array($parsed['audio_format'], ['truehd', 'dts'], true)) {
                    return false;
                }
                if ($parsed['codec'] && in_array($parsed['codec'], $avoidCodecs, true)) {
                    return false;
                }
                if (! empty($preference['max_audio_channels']) && $parsed['audio_channels']) {
                    $channelCount = (float) $parsed['audio_channels'];
                    if ($channelCount > (float) $preference['max_audio_channels']) {
                        return false;
                    }
                }

                return true;
            })
            ->values();

        return $candidates
            ->sortBy([
                fn (array $a, array $b) => ($b['parsed']['cached'] <=> $a['parsed']['cached']),
                fn (array $a, array $b) => (($b['parsed']['resolution'] ?? 0) <=> ($a['parsed']['resolution'] ?? 0)),
                fn (array $a, array $b) => (($b['parsed']['seeders'] ?? 0) <=> ($a['parsed']['seeders'] ?? 0)),
                fn (array $a, array $b) => (($b['parsed']['size_bytes'] ?? 0) <=> ($a['parsed']['size_bytes'] ?? 0)),
            ])
            ->values()
            ->all();
    }
}
