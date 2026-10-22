<?php

namespace App\Services;

class AssetFilePolicy
{
    // Matches Livewire's default temporary-upload limit (12 MiB).
    public const MAX_UPLOAD_KILOBYTES = 12288;

    private const RASTER_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'image/bmp' => ['bmp'],
        'image/x-ms-bmp' => ['bmp'],
    ];

    private const MEDIA_TYPES = [
        ...self::RASTER_TYPES,
        'audio/mpeg' => ['mp3'],
        'audio/ogg' => ['ogg', 'oga'],
        'audio/wav' => ['wav'],
        'audio/x-wav' => ['wav'],
        'audio/mp4' => ['m4a'],
        'audio/x-m4a' => ['m4a'],
        'audio/aac' => ['aac'],
        'audio/x-aac' => ['aac'],
        'audio/flac' => ['flac'],
        'audio/x-flac' => ['flac'],
        'video/mp4' => ['mp4', 'm4v', 'm4a'],
        'application/mp4' => ['mp4', 'm4v', 'm4a'],
        'video/webm' => ['webm'],
        'video/ogg' => ['ogv', 'ogg'],
        'video/quicktime' => ['mov'],
        'application/ogg' => ['ogg', 'oga', 'ogv'],
    ];

    /** @return list<string> */
    public static function mimeTypes(bool $imagesOnly = false): array
    {
        return array_keys($imagesOnly ? self::RASTER_TYPES : self::MEDIA_TYPES);
    }

    public static function allows(string $filename, ?string $mimeType, bool $imagesOnly = false): bool
    {
        $types = $imagesOnly ? self::RASTER_TYPES : self::MEDIA_TYPES;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, $types[$mimeType ?? ''] ?? [], true);
    }
}
