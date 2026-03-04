<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Storage;

class LogoCacheService
{
    public const CACHE_DIRECTORY = 'cached-logos';

    public const LEGACY_EXTENSIONLESS_PREFIX = 'logo_';

    public static function cacheKeyForUrl(string $url): string
    {
        return self::LEGACY_EXTENSIONLESS_PREFIX.md5($url);
    }

    public static function cacheBaseNameForUrl(string $url): string
    {
        return self::cacheKeyForUrl($url);
    }

    public static function cacheMetaFileForUrl(string $url): string
    {
        return self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($url).'.meta.json';
    }

    public static function cacheFileForUrl(string $url, string $extension): string
    {
        return self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($url).'.'.ltrim(strtolower($extension), '.');
    }

    public static function findCacheFileForUrl(string $url): ?string
    {
        $metaFile = self::cacheMetaFileForUrl($url);

        if (Storage::disk('local')->exists($metaFile)) {
            $meta = json_decode(Storage::disk('local')->get($metaFile), true);
            if (is_array($meta) && ! empty($meta['file']) && Storage::disk('local')->exists($meta['file'])) {
                return $meta['file'];
            }
        }

        $baseName = self::cacheBaseNameForUrl($url);

        foreach (Storage::disk('local')->files(self::CACHE_DIRECTORY) as $file) {
            if (str_starts_with(basename($file), $baseName.'.') && ! str_ends_with($file, '.meta.json')) {
                return $file;
            }
        }

        $legacyPath = self::CACHE_DIRECTORY.'/'.self::cacheKeyForUrl($url);

        if (Storage::disk('local')->exists($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    public static function writeCacheMetadata(string $url, string $cacheFile, ?string $contentType = null): void
    {
        Storage::disk('local')->put(self::cacheMetaFileForUrl($url), json_encode([
            'file' => $cacheFile,
            'content_type' => $contentType,
            'cached_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));
    }

    public static function clearByUrl(string $url): int
    {
        $cleared = 0;

        $cacheFile = self::findCacheFileForUrl($url);
        if ($cacheFile && Storage::disk('local')->exists($cacheFile)) {
            Storage::disk('local')->delete($cacheFile);
            $cleared++;
        }

        $metaFile = self::cacheMetaFileForUrl($url);
        if (Storage::disk('local')->exists($metaFile)) {
            Storage::disk('local')->delete($metaFile);
            $cleared++;
        }

        $legacyPath = self::CACHE_DIRECTORY.'/'.self::cacheKeyForUrl($url);
        if (Storage::disk('local')->exists($legacyPath)) {
            Storage::disk('local')->delete($legacyPath);
            $cleared++;
        }

        return $cleared;
    }

    public static function clearByUrls(array $urls): int
    {
        $cleared = 0;

        foreach (collect($urls)->filter()->unique() as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $cleared += self::clearByUrl($url);
        }

        return $cleared;
    }

    public static function normalizeExtensionFromContentType(?string $contentType, ?string $sourceUrl = null): string
    {
        $normalizedType = strtolower((string) $contentType);

        if (str_contains($normalizedType, 'jpeg') || str_contains($normalizedType, 'jpg')) {
            return 'jpg';
        }
        if (str_contains($normalizedType, 'png')) {
            return 'png';
        }
        if (str_contains($normalizedType, 'gif')) {
            return 'gif';
        }
        if (str_contains($normalizedType, 'webp')) {
            return 'webp';
        }
        if (str_contains($normalizedType, 'svg')) {
            return 'svg';
        }
        if (str_contains($normalizedType, 'bmp')) {
            return 'bmp';
        }
        if (str_contains($normalizedType, 'avif')) {
            return 'avif';
        }

        if ($sourceUrl) {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'], true)) {
                return $extension === 'jpeg' ? 'jpg' : $extension;
            }
        }

        return 'png';
    }

    public static function buildProxyFilename(string $url, ?string $fallbackExtension = null): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $baseName = basename((string) ($path ?? 'logo'));
        $name = pathinfo($baseName, PATHINFO_FILENAME) ?: 'logo';
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name) ?: 'logo';

        $extension = pathinfo($baseName, PATHINFO_EXTENSION);
        if (! $extension) {
            $extension = $fallbackExtension ?: self::normalizeExtensionFromContentType(null, $url);
        }

        return $name.'.'.strtolower((string) $extension);
    }

    public static function getPlaceholderUrl(string $type = 'logo'): string
    {
        $defaultPath = match ($type) {
            'episode' => '/episode-placeholder.png',
            'poster' => '/vod-series-poster-placeholder.png',
            default => '/placeholder.png',
        };

        try {
            $settings = app(GeneralSettings::class);
            $configured = match ($type) {
                'episode' => $settings->episode_placeholder_url,
                'poster' => $settings->vod_series_poster_placeholder_url,
                default => $settings->logo_placeholder_url,
            };
        } catch (\Exception $e) {
            $configured = null;
        }

        if (empty($configured)) {
            return url($defaultPath);
        }

        if (filter_var($configured, FILTER_VALIDATE_URL)) {
            return $configured;
        }

        if (str_starts_with($configured, '/')) {
            return url($configured);
        }

        return url('/storage/'.ltrim($configured, '/'));
    }
}
