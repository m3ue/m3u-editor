<?php

namespace App\Services;

use App\Enums\ImageProfile;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogoCacheService
{
    public const CACHE_DIRECTORY = 'cached-logos';

    public const LEGACY_EXTENSIONLESS_PREFIX = 'logo_';

    /**
     * Extensions a cache file can carry (see normalizeExtensionFromContentType()).
     * Probed directly so a miss never has to list the whole cache directory.
     */
    private const CACHED_EXTENSIONS = ['jpg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'];

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
        $disk = Storage::disk('local');
        $meta = self::readCacheMetadata($url);

        if ($meta !== null) {
            // Only a size copy is cached when the source needed resizing (see storeImage()).
            return ! empty($meta['file']) && $disk->exists($meta['file']) ? $meta['file'] : null;
        }

        foreach (self::CACHED_EXTENSIONS as $extension) {
            $file = self::cacheFileForUrl($url, $extension);
            if ($disk->exists($file)) {
                return $file;
            }
        }

        $legacyPath = self::CACHE_DIRECTORY.'/'.self::cacheKeyForUrl($url);

        if ($disk->exists($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    /**
     * @return array{file?: string, extension?: string, content_type?: ?string, profile?: string, cached_at?: string}|null
     */
    public static function readCacheMetadata(string $url): ?array
    {
        $metaFile = self::cacheMetaFileForUrl($url);

        if (! Storage::disk('local')->exists($metaFile)) {
            return null;
        }

        $meta = json_decode((string) Storage::disk('local')->get($metaFile), true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * Merge [$changes] into the companion metadata for [$sourceKey]: `file`
     * (the original's path, absent when only a size copy is cached),
     * `extension`, `content_type` and `profile` (the role it was cached for).
     * Null values leave a key unchanged.
     *
     * @param  array{file?: ?string, extension?: string, content_type?: ?string, profile?: ?string}  $changes
     */
    private static function writeCacheMetadata(string $sourceKey, array $changes): void
    {
        $meta = array_merge(
            self::readCacheMetadata($sourceKey) ?? [],
            array_filter($changes, fn ($value) => $value !== null),
            ['cached_at' => now()->toIso8601String()],
        );

        Storage::disk('local')->put(self::cacheMetaFileForUrl($sourceKey), json_encode($meta, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Stable cache identity for an image URL. Media server and Schedules
     * Direct proxy URLs are keyed by what they point at, not by the signed
     * URL itself, so a host, signature or url-version change still hits the
     * same cache entry. Any other URL is its own key.
     */
    public static function sourceKeyForUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#/media-server/(\d+)/image/([^/]+)(?:/([^/]+))?/?$#', $path, $matches)) {
            return self::mediaServerSourceKey((int) $matches[1], rawurldecode($matches[2]), rawurldecode($matches[3] ?? 'Primary'));
        }

        if (preg_match('#/schedules-direct/([^/]+)/image/([^/]+)/?$#', $path, $matches)) {
            return self::schedulesDirectSourceKey(rawurldecode($matches[1]), rawurldecode($matches[2]));
        }

        return $url;
    }

    public static function mediaServerSourceKey(int $integrationId, string $itemId, string $imageType): string
    {
        return "media-server://{$integrationId}/{$itemId}/{$imageType}";
    }

    public static function schedulesDirectSourceKey(string $epgUuid, string $imageHash): string
    {
        return "schedules-direct://{$epgUuid}/{$imageHash}";
    }

    /**
     * Cache path of the [$width]-wide copy of [$sourceKey]: `name.jpg` -> `name@w600.jpg`.
     * The width is part of the name, so changing a profile size simply misses
     * and the old copy expires through the regular cache cleanup.
     */
    public static function variantFileFor(string $sourceKey, string $extension, int $width): string
    {
        return self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($sourceKey).'@w'.$width.'.'.ltrim(strtolower($extension), '.');
    }

    /**
     * The cache base name a cache file belongs to: `cached-logos/logo_x@w600.jpg`
     * and `cached-logos/logo_x.jpg` are both `logo_x`, which also names their
     * shared `logo_x.meta.json`.
     */
    public static function cacheBaseNameOf(string $cacheFile): string
    {
        return (string) preg_replace('/@w\d+(h\d+)?$/', '', pathinfo($cacheFile, PATHINFO_FILENAME));
    }

    /**
     * Find a cached copy of [$sourceKey] sized for [$profile] (or the original
     * when no profile is given or optimization is off). A cached original
     * wider than the profile is downscaled once into a size copy. Files older
     * than [$maxAgeHours] count as missing so the caller refetches them.
     */
    public static function findImage(string $sourceKey, ?ImageProfile $profile = null, ?int $maxAgeHours = null): ?string
    {
        $disk = Storage::disk('local');
        $original = self::findCacheFileForUrl($sourceKey);
        $freshOriginal = $original && self::isFresh($original, $maxAgeHours) ? $original : null;

        $width = $profile?->maxWidth();
        $extension = self::readCacheMetadata($sourceKey)['extension']
            ?? ($original ? strtolower(pathinfo($original, PATHINFO_EXTENSION)) : null);

        // SVG scales losslessly on the client and is never rasterised here.
        if ($width === null || ! $extension || $extension === 'svg') {
            return $freshOriginal;
        }

        $variant = self::variantFileFor($sourceKey, $extension, $width);
        if ($disk->exists($variant) && self::isFresh($variant, $maxAgeHours)) {
            return $variant;
        }

        if ($freshOriginal === null) {
            return null;
        }

        // Header-only check, so an original that already fits is served
        // without reading it.
        $originalWidth = @getimagesize($disk->path($freshOriginal))[0] ?? null;
        if (! $originalWidth || $originalWidth <= $width) {
            return $freshOriginal;
        }

        // A failed resize stores the original bytes as this size's copy, so
        // the work is not retried on every request.
        $bytes = (string) $disk->get($freshOriginal);
        $disk->put($variant, self::optimize($bytes, $width) ?? $bytes);

        return $variant;
    }

    /**
     * Any cached copy of [$sourceKey], whatever its size or age: the original,
     * else the widest size copy. For when refetching is not an option (the
     * upstream is down, or a daily download limit is reached), so listing
     * the cache directory here is acceptable.
     */
    public static function findAnyCopy(string $sourceKey): ?string
    {
        $original = self::findCacheFileForUrl($sourceKey);
        if ($original) {
            return $original;
        }

        $variants = [];
        $pattern = self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($sourceKey).'@w*.*';
        foreach (glob(Storage::disk('local')->path($pattern)) ?: [] as $path) {
            if (preg_match('/@w(\d+)\./', basename($path), $matches)) {
                $variants[self::CACHE_DIRECTORY.'/'.basename($path)] = (int) $matches[1];
            }
        }
        arsort($variants);

        return array_key_first($variants);
    }

    /**
     * Cache freshly fetched image bytes for [$sourceKey] and return the file to
     * serve: with a profile, a downscaled copy when the source is wider than
     * the profile, otherwise the source bytes as the original (which then
     * serves that profile too).
     */
    public static function storeImage(string $sourceKey, string $bytes, ?string $contentType, ?ImageProfile $profile = null): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::CACHE_DIRECTORY);

        $extension = self::normalizeExtensionFromContentType($contentType, $sourceKey);
        $width = $extension === 'svg' ? null : $profile?->maxWidth();
        $optimized = $width !== null ? self::optimize($bytes, $width) : null;

        $cacheFile = $optimized !== null
            ? self::variantFileFor($sourceKey, $extension, $width)
            : self::cacheFileForUrl($sourceKey, $extension);
        $disk->put($cacheFile, $optimized ?? $bytes);

        self::writeCacheMetadata($sourceKey, [
            'file' => $optimized === null ? $cacheFile : null,
            'extension' => $extension,
            'content_type' => $contentType,
            'profile' => $profile?->value,
        ]);

        return $cacheFile;
    }

    /**
     * Downscale [$bytes] to [$maxWidth] (aspect preserved, never upscaled) at
     * the configured quality, keeping the source format. Returns null when
     * there is nothing to do: an undecodable image, a source already within
     * the width, or a result no smaller than the source.
     */
    public static function optimize(string $bytes, int $maxWidth): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || (int) ($info[0] ?? 0) <= $maxWidth) {
            return null;
        }

        try {
            $image = Image::fromBytes($bytes)->scale(width: $maxWidth);
            $quality = ImageProfile::quality();
            if ($quality !== null) {
                $image = $image->quality($quality);
            }

            $optimized = $image->toBytes();
        } catch (\Throwable $e) {
            Log::warning('Image optimization failed, caching original', [
                'width' => $maxWidth,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return strlen($optimized) < strlen($bytes) ? $optimized : null;
    }

    /**
     * Stream a cached image file with client cache headers.
     *
     * @param  array<string, string>  $headers  Extra headers merged over the defaults.
     */
    public static function streamResponse(string $cacheFile, ?string $contentType = null, int $maxAgeSeconds = 2592000, array $headers = []): StreamedResponse
    {
        $filePath = Storage::disk('local')->path($cacheFile);

        return response()->stream(function () use ($filePath) {
            $stream = fopen($filePath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, array_merge([
            'Content-Type' => $contentType ?: self::contentTypeForFile($filePath),
            'Content-Length' => (string) filesize($filePath),
            'Cache-Control' => "public, max-age={$maxAgeSeconds}",
            'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAgeSeconds),
            'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', filemtime($filePath)),
        ], $headers));
    }

    private static function contentTypeForFile(string $filePath): string
    {
        $mimeType = mime_content_type($filePath);

        if ($mimeType && str_starts_with($mimeType, 'image/')) {
            return $mimeType;
        }

        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            default => 'image/png',
        };
    }

    private static function isFresh(string $cacheFile, ?int $maxAgeHours): bool
    {
        if ($maxAgeHours === null) {
            return true;
        }

        return now()->timestamp - Storage::disk('local')->lastModified($cacheFile) < $maxAgeHours * 3600;
    }

    /**
     * Remove every cached copy of [$url] (original, size variants, metadata).
     * Media server / Schedules Direct proxy URLs also clear the entry their
     * proxy route caches under (see sourceKeyForUrl()).
     */
    public static function clearByUrl(string $url): int
    {
        $cleared = 0;

        foreach (array_unique([$url, self::sourceKeyForUrl($url)]) as $sourceKey) {
            $cleared += self::clearBySourceKey($sourceKey);
        }

        return $cleared;
    }

    private static function clearBySourceKey(string $sourceKey): int
    {
        $disk = Storage::disk('local');
        $cleared = 0;

        $cacheFile = self::findCacheFileForUrl($sourceKey);
        if ($cacheFile) {
            $disk->delete($cacheFile);
            $cleared++;
        }

        // Every size variant, including ones the metadata no longer knows
        // about. Listing the cache directory is fine for this rare admin action.
        $variantPattern = self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($sourceKey).'@w*.*';
        foreach (glob($disk->path($variantPattern)) ?: [] as $variantPath) {
            $disk->delete(self::CACHE_DIRECTORY.'/'.basename($variantPath));
            $cleared++;
        }

        $metaFile = self::cacheMetaFileForUrl($sourceKey);
        if ($disk->exists($metaFile)) {
            $disk->delete($metaFile);
            $cleared++;
        }

        $legacyPath = self::CACHE_DIRECTORY.'/'.self::cacheKeyForUrl($sourceKey);
        if ($disk->exists($legacyPath)) {
            $disk->delete($legacyPath);
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

    public static function isPlaceholderUrl(?string $url, string $type = 'logo'): bool
    {
        if (empty($url)) {
            return true;
        }

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

        $defaultUrl = url($defaultPath);
        if ($url === $defaultUrl) {
            return true;
        }

        if (! empty($configured)) {
            $configuredUrl = filter_var($configured, FILTER_VALIDATE_URL)
                ? $configured
                : (str_starts_with($configured, '/') ? url($configured) : url('/storage/'.ltrim($configured, '/')));
            if ($url === $configuredUrl) {
                return true;
            }
        }

        return false;
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
