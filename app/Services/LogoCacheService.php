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
     * when the original already serves that width, null to forget it).
     * Null values leave a key unchanged; keys in [$forget] are removed.
     *
     * @param  array{file?: string, extension?: string, content_type?: ?string, profile?: ?string, variants?: array<string, ?bool>}  $changes
     * @param  list<string>  $forget
     */
    private static function updateCacheMetadata(string $sourceKey, array $changes, array $forget = []): void
    {
        $meta = self::readCacheMetadata($sourceKey) ?? [];
        $variants = array_filter(
            array_replace($meta['variants'] ?? [], $changes['variants'] ?? []),
            fn (?bool $decision): bool => $decision !== null
        );
        unset($changes['variants']);

        $meta = array_merge(
            array_diff_key($meta, array_flip($forget)),
            array_filter($changes, fn ($value) => $value !== null),
            ['variants' => $variants, 'cached_at' => now()->toIso8601String()],
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
     * The width is part of the name, so changing a profile size simply misses.
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
     * Find a cached copy of [$sourceKey] for [$profile] (or the original when
     * no profile is given or optimization is off). An original, or a larger
     * copy cached before a width was lowered, is downscaled locally rather
     * than refetched; an original only gains a second copy when that copy is
     * meaningfully smaller. Files older than [$maxAgeHours] count as missing
     * so the caller refetches them.
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

        if ($freshOriginal) {
            $optimized = self::optimize((string) $disk->get($freshOriginal), $width);
            self::updateCacheMetadata($sourceKey, [
                'extension' => $extension,
                'variants' => [(string) $width => $optimized !== null],
            ]);

            if ($optimized === null) {
                return $freshOriginal;
            }

            return self::writeVariant($sourceKey, $extension, $width, $optimized);
        }

        // Usually there is no original: only a downscaled copy is stored. A
        // larger one (e.g. cached before this width was lowered in settings)
        // is resized locally instead of refetching from upstream.
        $larger = collect(self::variantFiles($sourceKey, $extension))
            ->filter(fn (int $variantWidth, string $file): bool => $variantWidth > $width && self::isFresh($file, $maxAgeHours))
            ->sort()
            ->keys()
            ->first();

        if ($larger === null) {
            return null;
        }

        $resized = self::optimize((string) $disk->get($larger), $width, minSavings: 0.0);

        return $resized !== null
            ? self::writeVariant($sourceKey, $extension, $width, $resized)
            : $larger;
    }

    /**
     * Any cached copy of [$sourceKey], whatever its size or age: the original,
     * else the largest size variant. For when refetching is not an option
     * (the upstream is down, or a daily download limit is reached).
     */
    public static function findAnyCopy(string $sourceKey): ?string
    {
        $original = self::findCacheFileForUrl($sourceKey);
        if ($original) {
            return $original;
        }

        $variants = self::variantFiles($sourceKey);
        arsort($variants);

        return array_key_first($variants);
    }

    /**
     * Cache freshly fetched image bytes for [$sourceKey] and return the file to
     * serve. Exactly one file is written: with a profile, the downscaled copy
     * when it is meaningfully smaller, otherwise the source bytes as the
     * original (which then serves that profile too). Copies the new bytes
     * supersede are removed.
     */
    public static function storeImage(string $sourceKey, string $bytes, ?string $contentType, ?ImageProfile $profile = null): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::CACHE_DIRECTORY);

        $extension = self::normalizeExtensionFromContentType($contentType, $sourceKey);
        $width = $extension === 'svg' ? null : $profile?->maxWidth();
        $optimized = $width !== null ? self::optimize($bytes, $width) : null;

        if ($width !== null && $optimized !== null) {
            // Only reached when no fresh original exists, so any original on
            // disk is an expired copy of the source these bytes replace.
            $staleOriginal = self::findCacheFileForUrl($sourceKey);
            if ($staleOriginal) {
                $disk->delete($staleOriginal);
            }

            self::updateCacheMetadata($sourceKey, [
                'extension' => $extension,
                'content_type' => $contentType,
                'profile' => $profile?->value,
            ], forget: ['file']);

            return self::writeVariant($sourceKey, $extension, $width, $optimized);
        }

        $cacheFile = self::cacheFileForUrl($sourceKey, $extension);
        $disk->put($cacheFile, $bytes);

        if ($width !== null) {
            // A refetch can replace a once-larger source; its old copy is stale.
            $disk->delete(self::variantFileFor($sourceKey, $extension, $width));
        }

        self::updateCacheMetadata($sourceKey, [
            'file' => $cacheFile,
            'extension' => $extension,
            'content_type' => $contentType,
            'profile' => $profile?->value,
            'variants' => $width !== null ? [(string) $width => false] : [],
        ]);
        self::deleteStaleVariants($sourceKey, $extension);

        return $cacheFile;
    }

    /**
     * Write the [$width]-wide copy of [$sourceKey], record it, and drop copies
     * no profile is sized to any more.
     */
    private static function writeVariant(string $sourceKey, string $extension, int $width, string $bytes): string
    {
        $variant = self::variantFileFor($sourceKey, $extension, $width);
        Storage::disk('local')->put($variant, $bytes);

        self::updateCacheMetadata($sourceKey, [
            'extension' => $extension,
            'variants' => [(string) $width => true],
        ]);
        self::deleteStaleVariants($sourceKey, $extension, keepWidth: $width);

        return $variant;
    }

    /**
     * Size variants cached for [$sourceKey] (of any extension when none is
     * given), as cache file => width. A legacy width-and-height copy
     * (`@w400h300`) reports width 0, so it is never reused or kept.
     *
     * @return array<string, int>
     */
    private static function variantFiles(string $sourceKey, ?string $extension = null): array
    {
        $pattern = self::CACHE_DIRECTORY.'/'.self::cacheBaseNameForUrl($sourceKey).'@w*.'.($extension ? ltrim(strtolower($extension), '.') : '*');
        $variants = [];

        foreach (glob(Storage::disk('local')->path($pattern)) ?: [] as $path) {
            if (preg_match('/@w(\d+)(h\d+)?\.[^.]+$/', $path, $matches)) {
                $variants[self::CACHE_DIRECTORY.'/'.basename($path)] = empty($matches[2]) ? (int) $matches[1] : 0;
            }
        }

        return $variants;
    }

    /**
     * Delete the size variants of [$sourceKey] that no profile is sized to any
     * more (a width was changed in settings, or optimization was turned off),
     * other than [$keepWidth]. Copies at another profile's current width stay:
     * one URL can serve as both a poster and a backdrop.
     */
    private static function deleteStaleVariants(string $sourceKey, string $extension, ?int $keepWidth = null): void
    {
        $currentWidths = array_filter(array_map(
            fn (ImageProfile $profile): ?int => $profile->maxWidth(),
            ImageProfile::cases()
        ));
        $isStale = fn (int $width): bool => $width !== $keepWidth && ! in_array($width, $currentWidths, true);

        $forget = [];
        foreach (self::variantFiles($sourceKey, $extension) as $file => $width) {
            if ($isStale($width)) {
                Storage::disk('local')->delete($file);
                $forget[(string) $width] = null;
            }
        }

        foreach (array_keys(self::readCacheMetadata($sourceKey)['variants'] ?? []) as $width) {
            if ($isStale((int) $width)) {
                $forget[(string) $width] = null;
            }
        }

        if ($forget !== []) {
            self::updateCacheMetadata($sourceKey, ['variants' => $forget]);
        }
    }

    /**
     * Downscale [$bytes] to [$maxWidth] (aspect preserved, never upscaled) at
     * the configured quality. Returns null when there is nothing to gain: an
     * SVG or undecodable image, a source already within the width, or a
     * result less than [$minSavings] (a fraction) smaller than the source.
     */
    public static function optimize(string $bytes, int $maxWidth, float $minSavings = self::MIN_OPTIMIZED_SAVINGS): ?string
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

        return strlen($optimized) <= strlen($bytes) * (1 - $minSavings) ? $optimized : null;
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

        foreach (array_keys(self::variantFiles($sourceKey)) as $variant) {
            $disk->delete($variant);
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
