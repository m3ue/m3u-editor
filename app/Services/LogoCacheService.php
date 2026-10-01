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

    /**
     * A downscaled copy is only kept when it is at least this much smaller
     * than its source. Below that, the re-encode costs quality for little
     * gain, and next to a cached original it would store the image twice.
     */
    private const MIN_OPTIMIZED_SAVINGS = 0.25;

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
            // A variant-only entry (see storeImage()) has no original on disk.
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
     * @return array{file?: ?string, extension?: string, content_type?: ?string, profile?: string, variants?: array<int|string, bool>, cached_at?: string}|null
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
     * Merge [$changes] into the companion metadata for [$sourceKey]:
     * `file` (the original's path, absent for a variant-only entry),
     * `extension`, `content_type`, `profile` (the role it was cached for),
     * and `variants` (width => true when a downscaled copy is stored, false
     * when the original already serves that width).
     *
     * @param  array{file?: string, extension?: string, content_type?: ?string, profile?: ?string, variants?: array<string, bool>}  $changes
     */
    private static function updateCacheMetadata(string $sourceKey, array $changes): void
    {
        $meta = self::readCacheMetadata($sourceKey) ?? [];
        $variants = array_replace($meta['variants'] ?? [], $changes['variants'] ?? []);

        $meta = array_merge($meta, array_filter($changes, fn ($value) => $value !== null), [
            'variants' => $variants,
            'cached_at' => now()->toIso8601String(),
        ]);

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
     * The width is part of the name, so changing a profile size simply misses.
     */
    public static function variantFileFor(string $sourceKey, string $extension, int $width): string
    {
        return self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($sourceKey).'@w'.$width.'.'.ltrim(strtolower($extension), '.');
    }

    /**
     * Find a cached copy of [$sourceKey] for [$profile] (or the original when
     * no profile is given or optimization is off). An original already on
     * disk is downscaled locally rather than refetched, and only gains a
     * second copy when that copy is meaningfully smaller. Files older than
     * [$maxAgeHours] count as missing so the caller refetches them.
     */
    public static function findImage(string $sourceKey, ?ImageProfile $profile = null, ?int $maxAgeHours = null): ?string
    {
        $disk = Storage::disk('local');
        $width = $profile?->maxWidth();
        $meta = self::readCacheMetadata($sourceKey);
        $original = self::findCacheFileForUrl($sourceKey);
        $freshOriginal = $original && self::isFresh($original, $maxAgeHours) ? $original : null;

        if ($width === null) {
            return $freshOriginal;
        }

        $extension = $meta['extension'] ?? ($original ? pathinfo($original, PATHINFO_EXTENSION) : null);

        // SVG scales losslessly on the client and is never rasterised here.
        if (! $extension || strtolower($extension) === 'svg') {
            return $freshOriginal;
        }

        $useVariant = $meta['variants'][(string) $width] ?? null;
        if ($useVariant === false) {
            return $freshOriginal;
        }

        $variant = self::variantFileFor($sourceKey, $extension, $width);
        if ($disk->exists($variant)) {
            if ($useVariant === null && $original) {
                // Cached before sizes were tracked: drop a copy that barely
                // improves on the original instead of storing both.
                $worthKeeping = $disk->size($variant) <= $disk->size($original) * (1 - self::MIN_OPTIMIZED_SAVINGS);
                if (! $worthKeeping) {
                    $disk->delete($variant);
                }
                self::updateCacheMetadata($sourceKey, ['variants' => [(string) $width => $worthKeeping]]);

                if (! $worthKeeping) {
                    return $freshOriginal;
                }
            }

            if (self::isFresh($variant, $maxAgeHours)) {
                return $variant;
            }
        }

        if (! $freshOriginal) {
            return null;
        }

        $optimized = self::optimize((string) $disk->get($freshOriginal), $width);
        self::updateCacheMetadata($sourceKey, [
            'extension' => $extension,
            'variants' => [(string) $width => $optimized !== null],
        ]);

        if ($optimized === null) {
            return $freshOriginal;
        }

        $disk->put($variant, $optimized);

        return $variant;
    }

    /**
     * Cache freshly fetched image bytes for [$sourceKey] and return the file to
     * serve. Exactly one file is written: with a profile, the downscaled copy
     * when it is meaningfully smaller, otherwise the source bytes as the
     * original (which then serves that profile too).
     */
    public static function storeImage(string $sourceKey, string $bytes, ?string $contentType, ?ImageProfile $profile = null): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::CACHE_DIRECTORY);

        $extension = self::normalizeExtensionFromContentType($contentType, $sourceKey);
        $width = $extension === 'svg' ? null : $profile?->maxWidth();
        $variant = $width !== null ? self::variantFileFor($sourceKey, $extension, $width) : null;
        $optimized = $width !== null ? self::optimize($bytes, $width) : null;

        if ($variant && $optimized !== null) {
            $disk->put($variant, $optimized);
            self::updateCacheMetadata($sourceKey, [
                'extension' => $extension,
                'content_type' => $contentType,
                'profile' => $profile?->value,
                'variants' => [(string) $width => true],
            ]);

            return $variant;
        }

        $cacheFile = self::cacheFileForUrl($sourceKey, $extension);
        $disk->put($cacheFile, $bytes);

        if ($variant) {
            // A refetch can replace a once-larger source; its old copy is stale.
            $disk->delete($variant);
        }

        self::updateCacheMetadata($sourceKey, [
            'file' => $cacheFile,
            'extension' => $extension,
            'content_type' => $contentType,
            'profile' => $profile?->value,
            'variants' => $width !== null ? [(string) $width => false] : [],
        ]);

        return $cacheFile;
    }

    /**
     * Downscale [$bytes] to [$maxWidth] (aspect preserved, never upscaled) at
     * the configured quality. Returns null when there is nothing to gain: an
     * SVG or undecodable image, a source already within the width, or a
     * result less than MIN_OPTIMIZED_SAVINGS smaller than the source.
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

        return strlen($optimized) <= strlen($bytes) * (1 - self::MIN_OPTIMIZED_SAVINGS) ? $optimized : null;
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

        $extension = self::readCacheMetadata($sourceKey)['extension'] ?? null;
        $cacheFile = self::findCacheFileForUrl($sourceKey);
        if ($cacheFile) {
            $extension ??= pathinfo($cacheFile, PATHINFO_EXTENSION);
            $disk->delete($cacheFile);
            $cleared++;
        }

        // Variants share the source's base name: `{base}@w{width}.{ext}`.
        if ($extension) {
            $pattern = $disk->path(self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($sourceKey).'@w*.'.$extension);
            foreach (glob($pattern) ?: [] as $variantPath) {
                if (@unlink($variantPath)) {
                    $cleared++;
                }
            }
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
