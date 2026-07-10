<?php

namespace App\DataObjects;

class ClientCapabilities
{
    private const CODEC_ALIASES = [
        'hevc' => 'hevc',
        'h265' => 'hevc',
        'h.265' => 'hevc',
        'h264' => 'h264',
        'avc' => 'h264',
        'h.264' => 'h264',
        'mpeg4 avc' => 'h264',
        'mpeg2video' => 'mpeg2video',
        'mpeg2' => 'mpeg2video',
        'mpeg-2' => 'mpeg2video',
        'mpeg 2 video' => 'mpeg2video',
        'av1' => 'av1',
        'vp9' => 'vp9',
        'vp8' => 'vp8',
    ];

    private const CONTAINER_ALIASES = [
        'mpegts' => 'mpegts',
        'mpegtsraw' => 'mpegts',
        'ts' => 'mpegts',
        'm2ts' => 'mpegts',
        'hls' => 'hls',
        'm3u8' => 'hls',
        'dash' => 'dash',
        'mpd' => 'dash',
        'mp4' => 'mp4',
        'mkv' => 'mkv',
        'matroska' => 'mkv',
        'flv' => 'flv',
    ];

    public function __construct(
        public readonly string $profile,
        public readonly string $platform,
        public readonly string $backend,
        public readonly array $videoCodecs,
        public readonly array $audioCodecs,
        public readonly array $containers,
        public readonly ?int $maxHeight = null,
        public readonly ?int $maxBitrateKbps = null,
        public readonly ?bool $hdr = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            profile: self::normalizeString($data['profile'] ?? ''),
            platform: self::normalizeString($data['platform'] ?? ''),
            backend: self::normalizeString($data['backend'] ?? ''),
            videoCodecs: self::normalizeCodecList($data['video_codecs'] ?? []),
            audioCodecs: self::normalizeStringList($data['audio_codecs'] ?? []),
            containers: self::normalizeContainerList($data['containers'] ?? []),
            maxHeight: isset($data['max_height']) && is_numeric($data['max_height'])
                ? (int) $data['max_height']
                : null,
            maxBitrateKbps: isset($data['max_bitrate_kbps']) && is_numeric($data['max_bitrate_kbps'])
                ? (int) $data['max_bitrate_kbps']
                : null,
            hdr: isset($data['hdr']) ? filter_var($data['hdr'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
        );
    }

    public static function normalizeCodec(string $codec): string
    {
        $clean = strtolower(trim($codec));

        return self::CODEC_ALIASES[$clean] ?? $clean;
    }

    public static function normalizeContainer(string $container): string
    {
        $clean = strtolower(trim($container));

        return self::CONTAINER_ALIASES[$clean] ?? $clean;
    }

    private static function normalizeString(string $value): string
    {
        return trim($value);
    }

    private static function normalizeStringList(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => strtolower(trim((string) $item)),
            $list
        ), fn (string $v) => $v !== '')));
    }

    private static function normalizeCodecList(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => self::normalizeCodec((string) $item),
            $list
        ), fn (string $v) => $v !== '')));
    }

    private static function normalizeContainerList(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => self::normalizeContainer((string) $item),
            $list
        ), fn (string $v) => $v !== '')));
    }
}
